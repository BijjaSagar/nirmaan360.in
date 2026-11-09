<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetHireBill;
use App\Models\FuelIssue;
use App\Models\MachineLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What the company owes the owner of a hired machine.
 *
 * The quantity comes from the machine log and the rate from the asset's hire terms:
 *  - hour:  hours worked, but never less than the monthly minimum for the days the machine was available.
 *  - day:   days with any work.
 *  - km:    kilometres run.
 *  - month: the monthly rate for each calendar month in the period, less the days the machine was down.
 * A day is "down" when the log shows breakdown hours and no work. Quantity is rounded to three
 * decimals before the amount is worked out, so quantity times rate always equals the amount.
 * Fuel the company issued to a wet-hired machine is recovered. GST and TDS are worked on the gross.
 */
class HireBillService
{
    use CreatesOnce;

    public function rules(User $user): array
    {
        return [
            'asset_id' => ['bail', 'required', 'integer', $this->inOrganization('assets', $user->organization_id)],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'vendor_invoice_no' => ['nullable', 'string', 'max:60'],
            'other_deduction' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'other_deduction_note' => ['nullable', 'string', 'max:255'],
            'gst_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tds_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function payRules(): array
    {
        return [
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function cancelRules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    /**
     * Work out a bill without saving it.
     *
     * @return array{basis:string,rate:string,actual_quantity:string,minimum_quantity:?string,quantity:string,gross_amount:string,hire_type:string,fuel_litres:string,fuel_recovery:string,other_deduction:string,gst_pct:string,gst_amount:string,tds_pct:string,tds_amount:string,net_payable:string,log_count:int,down_days:int}
     */
    public function calculate(Asset $asset, string $from, string $to, array $adjust = []): array
    {
        $this->assertBillable($asset);

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        $logs = $this->unbilledLogs($asset, $start, $end)->get();
        $byDay = $logs->groupBy(fn (MachineLog $log) => $log->log_date->toDateString());

        $worked = fn (Collection $day) => $this->total($day, 'hours')->isPositive() || $this->total($day, 'km')->isPositive();
        $downDays = $byDay
            ->filter(fn (Collection $day) => ! $worked($day) && $this->total($day, 'breakdown_hours')->isPositive())
            ->keys();

        $minimum = null;

        switch ($asset->hire_basis) {
            case 'hour':
                $actual = Dec::qty($this->total($logs, 'hours'));

                if ($asset->min_hours_per_month !== null) {
                    $minimum = Dec::qty(Dec::of($asset->min_hours_per_month)->multipliedBy($this->availableMonths($start, $end, $downDays)));
                }

                $quantity = $minimum !== null && Dec::of($minimum)->isGreaterThan($actual) ? $minimum : $actual;

                break;
            case 'day':
                $actual = $quantity = Dec::qty($byDay->filter($worked)->count());

                break;
            case 'km':
                $actual = $quantity = Dec::qty($this->total($logs, 'km'));

                break;
            default: // month
                $actual = $quantity = Dec::qty($this->availableMonths($start, $end, $downDays));
        }

        if (! Dec::of($quantity)->isPositive()) {
            throw ValidationException::withMessages(['period_from' => __('There is nothing to bill in this period: no unbilled machine log.')]);
        }

        $gross = Dec::money(Dec::of($asset->hire_rate)->multipliedBy($quantity));

        $fuelLitres = Dec::qty(0);
        $fuelRecovery = Dec::money(0);

        if ($asset->hire_type === 'wet') {
            $fuel = FuelIssue::where('asset_id', $asset->id)
                ->whereDate('issue_date', '>=', $start->toDateString())
                ->whereDate('issue_date', '<=', $end->toDateString());

            $fuelLitres = Dec::qty((clone $fuel)->sum('litres'));
            $fuelRecovery = Dec::money((clone $fuel)->sum('amount'));
        }

        $other = Dec::money($adjust['other_deduction'] ?? 0);
        $gstPct = Dec::money($adjust['gst_pct'] ?? 0);
        $tdsPct = Dec::money($adjust['tds_pct'] ?? 0);
        $gst = Dec::percentOf($gross, $gstPct);
        $tds = Dec::percentOf($gross, $tdsPct);

        $net = Dec::of($gross)->plus($gst)->minus($tds)->minus($fuelRecovery)->minus($other);

        if ($net->isNegative()) {
            throw ValidationException::withMessages(['other_deduction' => __('The deductions are more than the bill.')]);
        }

        return [
            'basis' => $asset->hire_basis,
            'rate' => Dec::money($asset->hire_rate),
            'actual_quantity' => $actual,
            'minimum_quantity' => $minimum,
            'quantity' => $quantity,
            'gross_amount' => $gross,
            'hire_type' => $asset->hire_type,
            'fuel_litres' => $fuelLitres,
            'fuel_recovery' => $fuelRecovery,
            'other_deduction' => $other,
            'gst_pct' => $gstPct,
            'gst_amount' => $gst,
            'tds_pct' => $tdsPct,
            'tds_amount' => $tds,
            'net_payable' => Dec::money($net),
            'log_count' => $logs->count(),
            'down_days' => $downDays->count(),
        ];
    }

    /** Build a draft bill and take the period's machine logs into it. A repeated client_uuid returns the original. */
    public function generate(array $data, User $user): AssetHireBill
    {
        $asset = Asset::withoutGlobalScopes()->where('organization_id', $user->organization_id)->findOrFail($data['asset_id']);

        return $this->createOnce(AssetHireBill::class, $asset->organization_id, $data['client_uuid'] ?? null, function () use ($asset, $data, $user) {
            Organization::whereKey($asset->organization_id)->lockForUpdate()->first();

            $this->assertBillable($asset);
            $this->assertNoOverlap($asset, $data['period_from'], $data['period_to']);

            if (Dec::of($data['other_deduction'] ?? 0)->isPositive() && blank($data['other_deduction_note'] ?? null)) {
                throw ValidationException::withMessages(['other_deduction_note' => __('Say what the other deduction is for.')]);
            }

            $calc = $this->calculate($asset, $data['period_from'], $data['period_to'], $data);
            $seq = $this->nextSequence(AssetHireBill::class, 'organization_id', $asset->organization_id);

            $bill = AssetHireBill::create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'vendor_id' => $asset->vendor_id,
                'seq' => $seq,
                'number' => sprintf('HB-%04d', $seq),
                'vendor_invoice_no' => $data['vendor_invoice_no'] ?? null,
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'other_deduction_note' => $data['other_deduction_note'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
                ...collect($calc)->only([
                    'basis', 'rate', 'actual_quantity', 'quantity', 'gross_amount', 'hire_type', 'fuel_litres', 'fuel_recovery',
                    'other_deduction', 'gst_pct', 'gst_amount', 'tds_pct', 'tds_amount', 'net_payable',
                ])->all(),
            ]);

            $this->unbilledLogs($asset, Carbon::parse($data['period_from']), Carbon::parse($data['period_to']))
                ->lockForUpdate()
                ->get()
                ->each(fn (MachineLog $log) => $log->update(['hire_bill_id' => $bill->id]));

            return $bill;
        });
    }

    public function finalize(AssetHireBill $bill): AssetHireBill
    {
        abort_unless($bill->status === 'DRAFT', 409, __('Only a draft bill can be finalised.'));

        $bill->update(['status' => 'FINAL', 'finalized_at' => now()]);

        return $bill;
    }

    public function markPaid(AssetHireBill $bill, array $data): AssetHireBill
    {
        abort_unless($bill->status === 'FINAL', 409, __('Only a final bill can be marked paid.'));

        $bill->update(['status' => 'PAID', 'paid_on' => $data['paid_on'], 'payment_reference' => $data['payment_reference'] ?? null]);

        return $bill;
    }

    /** Cancel a draft or final bill and free its machine logs to be billed again. A paid bill stays. */
    public function cancel(AssetHireBill $bill, string $reason): AssetHireBill
    {
        abort_unless(in_array($bill->status, ['DRAFT', 'FINAL'], true), 409, __('A paid or cancelled bill cannot be cancelled.'));

        $bill->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
        $bill->logs()->get()->each(fn (MachineLog $log) => $log->update(['hire_bill_id' => null]));

        return $bill;
    }

    /** An exact total of one decimal column. Collection::sum would add as floats. */
    private function total(Collection $rows, string $column): BigDecimal
    {
        return $rows->reduce(fn ($carry, $row) => $carry->plus(Dec::of($row->{$column})), Dec::of(0));
    }

    /** @return Builder<MachineLog> */
    private function unbilledLogs(Asset $asset, Carbon $start, Carbon $end)
    {
        return MachineLog::where('asset_id', $asset->id)
            ->whereNull('hire_bill_id')
            ->whereDate('log_date', '>=', $start->toDateString())
            ->whereDate('log_date', '<=', $end->toDateString())
            ->orderBy('log_date');
    }

    /**
     * Months of availability in a period: each calendar month counts its available days over its length,
     * so a whole month is exactly 1 however many days it has.
     *
     * @param  Collection<int, string>  $downDays  Dates, as Y-m-d.
     * @return BigDecimal Six decimals. Callers round it to the precision they need.
     */
    private function availableMonths(Carbon $start, Carbon $end, Collection $downDays): BigDecimal
    {
        $total = Dec::of(0);

        for ($month = $start->copy()->startOfMonth(); $month->lte($end); $month = $month->copy()->addMonthNoOverflow()) {
            $segmentStart = $start->gt($month) ? $start : $month;
            $monthEnd = $month->copy()->endOfMonth()->startOfDay();
            $segmentEnd = $end->lt($monthEnd) ? $end : $monthEnd;

            $days = (int) $segmentStart->diffInDays($segmentEnd) + 1;
            $down = $downDays->filter(fn (string $date) => $date >= $segmentStart->toDateString() && $date <= $segmentEnd->toDateString())->count();

            $total = $total->plus(Dec::of(max($days - $down, 0))->dividedBy($month->daysInMonth, 6, RoundingMode::HalfUp));
        }

        return $total;
    }

    private function assertBillable(Asset $asset): void
    {
        if (! $asset->isHired() || $asset->hire_basis === null || $asset->hire_rate === null) {
            throw ValidationException::withMessages(['asset_id' => __('This asset is not on hire, or its hire rate is not set.')]);
        }
    }

    private function assertNoOverlap(Asset $asset, string $from, string $to): void
    {
        $other = AssetHireBill::where('asset_id', $asset->id)
            ->where('status', '!=', 'CANCELLED')
            ->whereDate('period_from', '<=', $to)
            ->whereDate('period_to', '>=', $from)
            ->first();

        if ($other) {
            throw ValidationException::withMessages(['period_from' => __('Hire bill :number already covers part of this period.', ['number' => $other->number])]);
        }
    }
}
