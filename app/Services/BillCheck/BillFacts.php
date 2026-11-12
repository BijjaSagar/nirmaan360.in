<?php

namespace App\Services\BillCheck;

use App\Models\BoqItem;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\RaBillDeduction;
use App\Models\RaBillItem;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the checks read about one bill, loaded once and never written.
 *
 * Every query names the company and the project itself and leaves the signed-in person's limits out,
 * so a check gives the same answer for everyone who runs it, and from a job where nobody is signed in.
 * Whoever calls it has already been allowed to open the bill.
 */
final class BillFacts
{
    public readonly RaBill $bill;

    public readonly Project $project;

    public readonly Organization $organization;

    /** Null for a client bill, and for a subcontractor bill whose work order row is gone. */
    public readonly ?WorkOrder $workOrder;

    /** @var Collection<int, RaBillItem> */
    public readonly Collection $lines;

    /** @var Collection<int, RaBillDeduction> */
    public readonly Collection $deductions;

    /** @var array<string, mixed> The terms saved with the bill when it was worked out. */
    public readonly array $terms;

    /** @var Collection<int, RaBill> Every bill raised on this contract before this one, cancelled ones included. */
    public readonly Collection $earlier;

    public readonly CarbonImmutable $today;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(RaBill $bill, ?CarbonInterface $today = null)
    {
        $this->today = CarbonImmutable::parse(($today ?? today())->toDateString());
        $this->bill = RaBill::withoutGlobalScopes()->findOrFail($bill->id);
        $this->project = Project::withoutGlobalScopes()->findOrFail($this->bill->project_id);
        $this->organization = Organization::findOrFail($this->bill->organization_id);
        $this->workOrder = $this->bill->work_order_id === null ? null : WorkOrder::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->where('project_id', $this->bill->project_id)
            ->find($this->bill->work_order_id);
        $this->lines = RaBillItem::withoutGlobalScopes()->where('organization_id', $this->bill->organization_id)->where('ra_bill_id', $this->bill->id)->orderBy('id')->get();
        $this->deductions = RaBillDeduction::withoutGlobalScopes()->where('organization_id', $this->bill->organization_id)->where('ra_bill_id', $this->bill->id)->orderBy('sort')->orderBy('id')->get();
        $this->terms = is_array($this->bill->terms) ? $this->bill->terms : [];
        $this->earlier = $this->contractBills()->where('id', '<', $this->bill->id)->orderBy('id')->get();
    }

    public function isClient(): bool
    {
        return $this->bill->type === 'client';
    }

    /** A subcontractor bill whose work order can no longer be read. Checks against the contract are left out. */
    public function contractMissing(): bool
    {
        return ! $this->isClient() && $this->workOrder === null;
    }

    public function isLumpSum(): bool
    {
        return ($this->terms['rate_type'] ?? null) === 'lump_sum';
    }

    /** The column a measurement carries once it is on a bill of this type. */
    public function billedColumn(): string
    {
        return $this->isClient() ? 'billed_client_bill_id' : 'billed_sub_bill_id';
    }

    /**
     * Bills on the same contract, as the bill generator counts them: every client bill of the project,
     * or every bill of the one work order.
     */
    public function contractBills(): Builder
    {
        return RaBill::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->where('project_id', $this->bill->project_id)
            ->where('type', $this->bill->type)
            ->when(! $this->isClient(), fn (Builder $query) => $query->where('work_order_id', $this->bill->work_order_id));
    }

    /** @return Collection<int, RaBill> Earlier bills that still count: everything but the cancelled ones. */
    public function liveEarlier(): Collection
    {
        return $this->earlier->where('status', '!=', 'CANCELLED')->values();
    }

    /** @return Collection<int, RaBillItem> Lines of the earlier bills that still count. */
    public function earlierLines(): Collection
    {
        return $this->memo['earlierLines'] ??= RaBillItem::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->whereIn('ra_bill_id', $this->liveEarlier()->pluck('id'))
            ->get();
    }

    /** @return Collection<int, RaBillDeduction> Deductions of the earlier bills that still count. */
    public function earlierDeductions(): Collection
    {
        return $this->memo['earlierDeductions'] ??= RaBillDeduction::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->whereIn('ra_bill_id', $this->liveEarlier()->pluck('id'))
            ->get();
    }

    /** Measurements of this contract: the whole project for a client bill, one work order otherwise. */
    public function contractEntries(): Builder
    {
        return $this->projectEntries()
            ->when(! $this->isClient(), fn (Builder $query) => $query->where('work_order_id', $this->bill->work_order_id));
    }

    public function projectEntries(): Builder
    {
        return MeasurementEntry::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->where('project_id', $this->bill->project_id);
    }

    /** @return Collection<int, MeasurementEntry> The measurements this bill was made from. */
    public function entriesOnBill(): Collection
    {
        return $this->memo['entriesOnBill'] ??= $this->projectEntries()->where($this->billedColumn(), $this->bill->id)->orderBy('id')->get();
    }

    /** @return Collection<int, int> Ids of the measurements on this bill that carry a photo or a sketch. */
    public function entriesWithAttachment(): Collection
    {
        return $this->memo['withAttachment'] ??= MeasurementAttachment::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->whereIn('measurement_entry_id', $this->entriesOnBill()->pluck('id'))
            ->pluck('measurement_entry_id')
            ->unique()
            ->values();
    }

    /** The BOQ line behind a bill line, removed ones included. */
    public function boqItem(int $id): ?BoqItem
    {
        $items = $this->memo['boqItems'] ??= BoqItem::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->whereIn('id', $this->lines->pluck('boq_item_id')->merge($this->entriesOnBill()->pluck('boq_item_id'))->unique())
            ->get()
            ->keyBy('id');

        return $items->get($id);
    }

    /** The work order's line for a BOQ item, as it stands today. */
    public function orderLine(int $boqItemId): ?WorkOrderItem
    {
        if ($this->workOrder === null) {
            return null;
        }

        $lines = $this->memo['orderLines'] ??= WorkOrderItem::withoutGlobalScopes()
            ->where('organization_id', $this->bill->organization_id)
            ->where('work_order_id', $this->workOrder->id)
            ->get()
            ->keyBy('boq_item_id');

        return $lines->get($boqItemId);
    }

    /** The code of a bill line's item, as printed on the bill. */
    public function itemCode(int $boqItemId): string
    {
        return (string) ($this->lines->firstWhere('boq_item_id', $boqItemId)?->code ?? $this->boqItem($boqItemId)?->code ?? '#'.$boqItemId);
    }

    public function itemUnit(int $boqItemId): string
    {
        return (string) ($this->lines->firstWhere('boq_item_id', $boqItemId)?->unit ?? $this->boqItem($boqItemId)?->unit ?? '');
    }

    /** @return array<string, mixed> The terms on the contract form as they stand today. */
    public function termsToday(): array
    {
        return ($this->workOrder ?? $this->project)->billingTerms();
    }

    /** What a deduction is worked out on, for a head that is not on the bill. */
    public function baseAmount(string $base, ?RaBill $of = null): BigDecimal
    {
        $of ??= $this->bill;
        $gross = Dec::of($of->gross_amount);

        return match ($base) {
            'running' => $gross->minus(Dec::of($of->deduction_total)),
            'gross_plus_gst' => $gross->plus(Dec::of($of->gst_amount)),
            default => $gross,
        };
    }

    /** Adds up decimal strings exactly. A database sum may come back as a float. */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($values as $value) {
            $total = $total->plus(Dec::of($value));
        }

        return $total;
    }

    /** A percentage as plain text, so that 7.50 and 7.5000 are the same figure. */
    public static function pct(mixed $value): string
    {
        return (string) Dec::of($value)->strippedOfTrailingZeros();
    }
}
