<?php

namespace App\Services\SiteProgress;

use App\Models\BoqItem;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteControl\WorkProgress;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Daily quantity entries. An entry is never edited: a mistake is answered by a correction entry that
 * carries the difference, and a pending remark is closed by a resolving entry. Percentages come from WorkProgress.
 */
class ProgressEntryService
{
    public function __construct(private WorkProgress $progress) {}

    /** One rule set for the web form and the mobile API. */
    public function rules(Project $project): array
    {
        return [
            'boq_item_id' => ['bail', 'required', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'project_floor_id' => ['bail', 'nullable', 'integer', Rule::exists('project_floors', 'id')->where('project_id', $project->id)],
            'work_date' => ['nullable', 'date', 'before_or_equal:today'],
            // Left empty only for a pending remark that records no work; record() asks for it otherwise.
            'qty_done' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'is_pending' => ['nullable', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function correctionRules(): array
    {
        return [
            'qty_done' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
            'remark' => ['required', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Add today's (or an earlier day's) work. A repeated client_uuid returns the original entry. */
    public function record(Project $project, array $data, User $user): ProgressEntry
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $this->ofProject($existing, $project);
        }

        $quantity = Dec::of($data['qty_done'] ?? 0);
        $pending = (bool) ($data['is_pending'] ?? false);
        $remark = $this->clean($data['remark'] ?? null);

        if ($quantity->isZero() && ! $pending) {
            throw ValidationException::withMessages(['qty_done' => __('Enter the quantity done, or mark the item as pending.')]);
        }

        if ($pending && $remark === null) {
            throw ValidationException::withMessages(['remark' => __('Say what is pending.')]);
        }

        return Idempotent::create(
            fn () => ProgressEntry::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'boq_item_id' => $data['boq_item_id'],
                'project_floor_id' => $data['project_floor_id'] ?? null,
                'work_date' => $data['work_date'] ?? now()->toDateString(),
                'qty_done' => Dec::qty($quantity),
                'remark' => $remark,
                'is_pending' => $pending,
                'entered_by' => $user->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->find($project->organization_id, (string) $uuid),
        );
    }

    /**
     * Set what an entry should have been. Nothing is edited: a new entry holds the difference, dated
     * the same day as the original so that week and day totals come out right.
     */
    public function correct(ProgressEntry $entry, string $rightQty, string $reason, User $user, ?string $uuid = null): ProgressEntry
    {
        if ($uuid && ($existing = $this->find($entry->organization_id, $uuid))) {
            return $this->sameCorrection($existing, $entry);
        }

        if ($entry->isCorrection() || $entry->isResolution() || Dec::of($entry->qty_done)->isZero()) {
            throw ValidationException::withMessages(['qty_done' => __('Only an entry that recorded work can be corrected. Correct the original entry.')]);
        }

        $reason = $this->clean($reason);

        if ($reason === null) {
            throw ValidationException::withMessages(['remark' => __('Say why the entry is being corrected.')]);
        }

        return Idempotent::create(function () use ($entry, $rightQty, $reason, $user, $uuid) {
            // Two corrections arriving together would each work from the same figure.
            $locked = ProgressEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $difference = Dec::of($rightQty)->minus($this->netQty($locked));

            if ($difference->isZero()) {
                throw ValidationException::withMessages(['qty_done' => __('That is already the quantity on this entry.')]);
            }

            return ProgressEntry::create([
                'organization_id' => $locked->organization_id,
                'project_id' => $locked->project_id,
                'boq_item_id' => $locked->boq_item_id,
                'project_floor_id' => $locked->project_floor_id,
                'work_order_id' => $locked->work_order_id,
                'work_date' => $locked->work_date->toDateString(),
                'qty_done' => Dec::qty($difference),
                'remark' => $reason,
                'is_pending' => false,
                'corrects_entry_id' => $locked->id,
                'entered_by' => $user->id,
                'client_uuid' => $uuid,
            ]);
        }, fn () => $this->find($entry->organization_id, (string) $uuid));
    }

    /** Close a pending remark. The remark itself stays in the history. */
    public function resolve(ProgressEntry $pending, ?string $remark, User $user, ?string $uuid = null): ProgressEntry
    {
        if ($uuid && ($existing = $this->find($pending->organization_id, $uuid))) {
            return $existing->resolves_entry_id === $pending->id
                ? $existing
                : throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return Idempotent::create(function () use ($pending, $remark, $user, $uuid) {
            $locked = ProgressEntry::whereKey($pending->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_pending) {
                throw ValidationException::withMessages(['entry' => __('This entry is not a pending item.')]);
            }

            if (ProgressEntry::where('resolves_entry_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['entry' => __('This pending item is already finished.')]);
            }

            return ProgressEntry::create([
                'organization_id' => $locked->organization_id,
                'project_id' => $locked->project_id,
                'boq_item_id' => $locked->boq_item_id,
                'project_floor_id' => $locked->project_floor_id,
                'work_date' => now()->toDateString(),
                'qty_done' => Dec::qty(0),
                'remark' => $this->clean($remark),
                'is_pending' => false,
                'resolves_entry_id' => $locked->id,
                'entered_by' => $user->id,
                'client_uuid' => $uuid,
            ]);
        }, fn () => $this->find($pending->organization_id, (string) $uuid));
    }

    /** The entry's quantity after its corrections. */
    public function netQty(ProgressEntry $entry): BigDecimal
    {
        return Dec::of($entry->qty_done)->plus(Dec::of(Dec::qty(ProgressEntry::where('corrects_entry_id', $entry->id)->sum('qty_done'))));
    }

    /** True when everything done on the BOQ line now passes its BOQ quantity. */
    public function exceedsBoq(ProgressEntry $entry): bool
    {
        $item = BoqItem::withTrashed()->find($entry->boq_item_id, ['id', 'qty']);

        return $item !== null
            && Dec::of($item->qty)->isGreaterThan(0)
            && $this->progress->completedQty($item->id)->isGreaterThan(Dec::of($item->qty));
    }

    /** Net quantity of every original entry in one query, keyed by entry id. */
    public function netQuantities(iterable $entries): array
    {
        $ids = collect($entries)->pluck('id')->all();
        $corrected = $ids === [] ? collect() : ProgressEntry::whereIn('corrects_entry_id', $ids)
            ->select('corrects_entry_id', DB::raw('SUM(qty_done) as total'))->groupBy('corrects_entry_id')->pluck('total', 'corrects_entry_id');

        $nets = [];

        foreach ($entries as $entry) {
            $nets[$entry->id] = Dec::qty(Dec::of($entry->qty_done)->plus(Dec::of(Dec::qty($corrected[$entry->id] ?? 0))));
        }

        return $nets;
    }

    private function sameCorrection(ProgressEntry $existing, ProgressEntry $entry): ProgressEntry
    {
        return $existing->corrects_entry_id === $entry->id
            ? $existing
            : throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
    }

    private function ofProject(ProgressEntry $existing, Project $project): ProgressEntry
    {
        return $existing->project_id === $project->id
            ? $existing
            : throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /** A repeat is looked up across the company, then checked against the person's project limit. */
    private function find(int $organizationId, string $uuid): ?ProgressEntry
    {
        return ClientUuidRepeat::reachable(
            ProgressEntry::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first()
        );
    }
}
