<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetBreakdown;
use App\Models\AssetHireBill;
use App\Models\FuelIssue;
use App\Models\MachineLog;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\ClientTime;
use App\Support\Dec;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/** The daily record of a machine: what it worked, what fuel it took and when it broke down. */
class PlantLogService
{
    use CreatesOnce;

    public function logRules(User $user): array
    {
        $org = $user->organization_id;

        return [
            'asset_id' => ['bail', 'required', 'integer', $this->inOrganization('assets', $org)],
            'project_id' => ['bail', 'nullable', 'integer', $this->projectRule($org)],
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'opening_reading' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'closing_reading' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:'.config('modules.assets.max_hours_per_day')],
            'km' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'breakdown_hours' => ['nullable', 'numeric', 'min:0', 'max:'.config('modules.assets.max_hours_per_day')],
            'operator' => ['nullable', 'string', 'max:80'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function fuelRules(User $user): array
    {
        $org = $user->organization_id;

        return [
            'asset_id' => ['bail', 'required', 'integer', $this->inOrganization('assets', $org)],
            'project_id' => ['bail', 'nullable', 'integer', $this->projectRule($org)],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'litres' => ['required', 'numeric', 'gt:0', 'max:100000'],
            // The rate is money. Site staff who may not see it do not enter it; the issue takes the asset's last rate.
            'rate' => Money::visible($user) ? ['required', 'numeric', 'min:0', 'max:100000'] : ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reading' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'source' => ['nullable', 'string', 'max:80'],
            'slip_no' => ['nullable', 'string', 'max:40'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function breakdownRules(User $user): array
    {
        $org = $user->organization_id;

        return [
            'asset_id' => ['bail', 'required', 'integer', $this->inOrganization('assets', $org)],
            'project_id' => ['bail', 'nullable', 'integer', $this->projectRule($org)],
            'reported_at' => ['required', 'date', 'before_or_equal:now'],
            'description' => ['required', 'string', 'max:2000'],
            'cause' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function resolveRules(): array
    {
        return [
            'resolved_at' => ['required', 'date', 'before_or_equal:now'],
            'repair_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Record a day's work. A repeated client_uuid returns the original row. */
    public function record(array $data, User $user): MachineLog
    {
        $asset = $this->asset($data['asset_id'], $user);

        return $this->createOnce(MachineLog::class, $user->organization_id, $data['client_uuid'] ?? null, function () use ($asset, $data, $user) {
            $this->assertWorkable($asset, $data['log_date'], 'log_date');

            [$hours, $km, $entered] = $this->usage($asset, $data);
            $lost = Dec::qty($data['breakdown_hours'] ?? 0);

            if (! $entered && ! filled($data['breakdown_hours'] ?? null)) {
                throw ValidationException::withMessages(['hours' => __('Enter the hours or km worked, or the hours lost to breakdown.')]);
            }

            $max = Dec::of(config('modules.assets.max_hours_per_day'));
            $already = Dec::of($asset->logs()->whereDate('log_date', $data['log_date'])->sum('hours'));

            if ($already->plus($hours)->isGreaterThan($max)) {
                throw ValidationException::withMessages(['hours' => __('That would be more than :max hours for this machine on one day.', ['max' => $max])]);
            }

            return MachineLog::create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'project_id' => $data['project_id'] ?? $asset->project_id,
                'log_date' => $data['log_date'],
                'opening_reading' => filled($data['opening_reading'] ?? null) ? Dec::qty($data['opening_reading']) : null,
                'closing_reading' => filled($data['closing_reading'] ?? null) ? Dec::qty($data['closing_reading']) : null,
                'hours' => $hours,
                'km' => $km,
                'breakdown_hours' => $lost,
                'operator' => $data['operator'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }

    /** A log that is part of a hire bill stays. Cancel the bill to free it. */
    public function deleteLog(MachineLog $log): void
    {
        abort_if($log->isBilled(), 409, __('This log is part of a hire bill. Cancel the bill first.'));

        $log->delete();
    }

    /** The rate on the asset's latest fuel issue, so an entry by someone who never sees rates is still costed. Zero on the first issue. */
    private function lastFuelRate(Asset $asset): string
    {
        return Dec::money($asset->fuelIssues()->latest('issue_date')->latest('id')->value('rate') ?? 0);
    }

    public function issueFuel(array $data, User $user): FuelIssue
    {
        $asset = $this->asset($data['asset_id'], $user);

        return $this->createOnce(FuelIssue::class, $user->organization_id, $data['client_uuid'] ?? null, function () use ($asset, $data, $user) {
            $this->assertWorkable($asset, $data['issue_date'], 'issue_date');

            $rate = Money::visible($user) ? Dec::money($data['rate'] ?? 0) : $this->lastFuelRate($asset);

            return FuelIssue::create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'project_id' => $data['project_id'] ?? $asset->project_id,
                'issue_date' => $data['issue_date'],
                'litres' => Dec::qty($data['litres']),
                'rate' => $rate,
                'amount' => Dec::money(Dec::of($data['litres'])->multipliedBy(Dec::of($rate))),
                'reading' => filled($data['reading'] ?? null) ? Dec::qty($data['reading']) : null,
                'source' => $data['source'] ?? null,
                'slip_no' => $data['slip_no'] ?? null,
                'issued_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }

    /** Report a breakdown. The machine shows as under repair until it is resolved. */
    public function reportBreakdown(array $data, User $user): AssetBreakdown
    {
        $asset = $this->asset($data['asset_id'], $user);

        return $this->createOnce(AssetBreakdown::class, $user->organization_id, $data['client_uuid'] ?? null, function () use ($asset, $data, $user) {
            if ($asset->isRetired()) {
                throw ValidationException::withMessages(['asset_id' => __('A retired asset cannot take new entries.')]);
            }

            $breakdown = AssetBreakdown::create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'project_id' => $data['project_id'] ?? $asset->project_id,
                'reported_at' => ClientTime::parse($data['reported_at']),
                'description' => $data['description'],
                'cause' => $data['cause'] ?? null,
                'status' => 'open',
                'reported_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            $asset->update(['status' => 'under_repair']);

            return $breakdown;
        });
    }

    public function resolveBreakdown(AssetBreakdown $breakdown, array $data): AssetBreakdown
    {
        abort_unless($breakdown->isOpen(), 409, __('This breakdown is already resolved.'));

        $resolvedAt = ClientTime::parse($data['resolved_at']);

        if ($resolvedAt->lt($breakdown->reported_at)) {
            throw ValidationException::withMessages(['resolved_at' => __('It cannot be fixed before it was reported.')]);
        }

        $breakdown->update([
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
            'repair_cost' => Dec::money($data['repair_cost'] ?? 0),
            'resolution' => $data['resolution'] ?? null,
        ]);

        $asset = $breakdown->asset;

        if ($asset->status === 'under_repair' && ! $asset->breakdowns()->where('status', 'open')->exists()) {
            $asset->update(['status' => 'active']);
        }

        return $breakdown;
    }

    /**
     * Hours and km for a log: as entered, or worked out from the meter readings.
     *
     * @return array{0:string,1:string,2:bool} Hours, km, and whether anything was entered.
     */
    private function usage(Asset $asset, array $data): array
    {
        $opening = $data['opening_reading'] ?? null;
        $closing = $data['closing_reading'] ?? null;
        $both = filled($opening) && filled($closing);

        if ($both && Dec::of($closing)->isLessThan(Dec::of($opening))) {
            throw ValidationException::withMessages(['closing_reading' => __('The closing reading cannot be lower than the opening reading.')]);
        }

        $fromMeter = $both ? Dec::qty(Dec::of($closing)->minus(Dec::of($opening))) : null;

        $hours = filled($data['hours'] ?? null) ? Dec::qty($data['hours']) : ($asset->meter_unit === 'hours' ? $fromMeter : null);
        $km = filled($data['km'] ?? null) ? Dec::qty($data['km']) : ($asset->meter_unit === 'km' ? $fromMeter : null);

        return [$hours ?? '0.000', $km ?? '0.000', $hours !== null || $km !== null];
    }

    /** No entries for a retired machine, or for days a hire bill already covers. */
    private function assertWorkable(Asset $asset, string $date, string $field): void
    {
        if ($asset->isRetired()) {
            throw ValidationException::withMessages(['asset_id' => __('A retired asset cannot take new entries.')]);
        }

        $billed = AssetHireBill::where('asset_id', $asset->id)
            ->where('status', '!=', 'CANCELLED')
            ->whereDate('period_from', '<=', $date)
            ->whereDate('period_to', '>=', $date)
            ->exists();

        if ($billed) {
            throw ValidationException::withMessages([$field => __('A hire bill already covers this date. Cancel the bill to add entries.')]);
        }
    }

    private function asset(int|string $id, User $user): Asset
    {
        return Asset::withoutGlobalScopes()->where('organization_id', $user->organization_id)->findOrFail($id);
    }
}
