<?php

namespace App\Services;

use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\BoqRevisions;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BoqService
{
    /** The web form's rules, where a code is required. */
    public function rules(): array
    {
        return [
            'section' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'max:16'],
            'qty' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'category' => ['nullable', Rule::in(BoqCategories::keys())],
            'details' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** Why a line is being changed or removed. The BOQ is never locked, so the reason is kept with the change. */
    public function reasonRules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /**
     * Revise a line. The change is saved in the BOQ history with the reason. Measurements, daily
     * reports and bills are left exactly as they were.
     */
    public function update(BoqItem $item, array $data, string $reason, string $source = 'edit'): BoqItem
    {
        $data = array_intersect_key($data, array_flip(['code', 'description', 'unit', 'qty', 'rate', 'category', 'details']));

        if (isset($data['qty'])) {
            $data['qty'] = Dec::qty($data['qty']);
            $this->assertQtyCoversWorkDone($item, Dec::of($data['qty']));
        }

        if (isset($data['rate'])) {
            $data['rate'] = Dec::money($data['rate']);
        }

        // Recorded quantities, quotes and bills are in this unit. Changing it would change what they mean.
        if (isset($data['unit']) && $data['unit'] !== $item->unit && $this->uses($item) !== []) {
            throw ValidationException::withMessages(['unit' => __('The unit cannot change once work, tenders or bills are recorded against this item. Add a new item instead.')]);
        }

        BoqRevisions::with($reason, $source, fn () => $item->update($data));

        return $item;
    }

    /**
     * Remove a line that nothing has been recorded against. The removal is kept in the BOQ history.
     * Each record the line has gets its own message, so the person knows what holds it.
     */
    public function delete(BoqItem $item, string $reason, string $source = 'remove'): void
    {
        $uses = $this->uses($item);

        if ($uses !== []) {
            throw ValidationException::withMessages(['boq' => array_map($this->usedMessage(...), $uses)]);
        }

        BoqRevisions::with($reason, $source, fn () => $item->delete());
    }

    /**
     * Only the company's owner removes BOQ lines from the field app: a built-in owner, as on the Company data page.
     * A custom role is never an owner, whatever its base role.
     */
    public function mayDeleteFromApp(User $user): bool
    {
        return $user->role === 'owner' && $user->custom_role_id === null && $user->is_active;
    }

    /**
     * What is recorded against a line, in the order a refusal names it. Read straight from each table, so no
     * scope can hide a record: a line that anything uses is never removed and its unit never changes.
     * Work orders and daily reports that were themselves deleted no longer hold it.
     *
     * @return list<string>
     */
    public function uses(BoqItem $item): array
    {
        $id = $item->id;
        $checks = [
            'bills' => fn () => DB::table('ra_bill_items')->where('boq_item_id', $id)->exists(),
            'measurements' => fn () => DB::table('measurement_entries')->where('boq_item_id', $id)->exists(),
            'work_orders' => fn () => DB::table('work_order_items')
                ->join('work_orders', 'work_orders.id', '=', 'work_order_items.work_order_id')
                ->where('work_order_items.boq_item_id', $id)->whereNull('work_orders.deleted_at')->exists(),
            'tenders' => fn () => DB::table('contractor_tender_items')->where('boq_item_id', $id)->exists(),
            'daily_reports' => fn () => DB::table('dpr_work_items')
                ->join('dprs', 'dprs.id', '=', 'dpr_work_items.dpr_id')
                ->where('dpr_work_items.boq_item_id', $id)->whereNull('dprs.deleted_at')->exists(),
            'site_progress' => fn () => DB::table('progress_entries')->where('boq_item_id', $id)->exists(),
            'labour' => fn () => DB::table('labour_allocations')->where('boq_item_id', $id)->exists(),
        ];

        return array_keys(array_filter($checks, fn (callable $check) => $check()));
    }

    private function usedMessage(string $use): string
    {
        return match ($use) {
            'bills' => __('This item is on a bill and cannot be removed.'),
            'measurements' => __('This item has measurements and cannot be removed.'),
            'work_orders' => __('This item is on a work order and cannot be removed.'),
            'tenders' => __('This item is in a tender and cannot be removed.'),
            'daily_reports' => __('This item has work in daily reports and cannot be removed.'),
            'site_progress' => __('This item has site progress entries and cannot be removed.'),
            'labour' => __('This item has labour booked against it and cannot be removed.'),
        };
    }

    /**
     * Quantities can be revised, but never below what is already measured (rejected entries left out) or
     * billed to the client. Measured and billed quantities are added up here, never by the database, so no
     * float is involved. Daily report figures are site estimates and an approved one cannot be corrected,
     * so they do not hold the quantity up.
     */
    private function assertQtyCoversWorkDone(BoqItem $item, BigDecimal $qty): void
    {
        $measured = $this->total(DB::table('measurement_entries')->where('boq_item_id', $item->id)->where('status', '!=', 'REJECTED')->pluck('qty'));

        if ($measured->isGreaterThan($qty)) {
            throw ValidationException::withMessages(['qty' => __('Quantity cannot be lower than the quantity already measured (:qty).', ['qty' => fmt_qty($measured)])]);
        }

        $billed = $this->total(DB::table('ra_bill_items')
            ->join('ra_bills', 'ra_bills.id', '=', 'ra_bill_items.ra_bill_id')
            ->where('ra_bill_items.boq_item_id', $item->id)
            ->where('ra_bills.type', 'client')
            ->where('ra_bills.status', '!=', 'CANCELLED')
            ->pluck('ra_bill_items.current_qty'));

        if ($billed->isGreaterThan($qty)) {
            throw ValidationException::withMessages(['qty' => __('Quantity cannot be lower than the quantity already billed (:qty).', ['qty' => fmt_qty($billed)])]);
        }
    }

    /** @param  iterable<mixed>  $values */
    private function total(iterable $values): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($values as $value) {
            $sum = $sum->plus(Dec::of($value));
        }

        return $sum;
    }

    /**
     * Add a line at the end of the BOQ. A repeated client_uuid returns the original row.
     * The project row lock serialises additions, so sort and a generated code cannot be raced.
     */
    public function create(Project $project, array $data): BoqItem
    {
        return DB::transaction(function () use ($project, $data) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findByClientUuid($project->organization_id, $uuid))) {
                return $existing;
            }

            $section = $this->sectionId($project, $data['section'] ?? null);
            unset($data['section']);

            return BoqItem::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'boq_section_id' => $section,
                'code' => filled($data['code'] ?? null) ? $data['code'] : $this->nextCode($project),
                'qty' => Dec::qty($data['qty']),
                // Someone who may not see money cannot enter a rate; the line waits for an estimator to price it.
                'rate' => Dec::money($data['rate'] ?? 0),
                'sort' => (int) $project->boqItems()->max('sort') + 1,
            ]);
        });
    }

    /** Find or create a section by name within the project. A blank name means no section. */
    public function sectionId(Project $project, ?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return BoqSection::firstOrCreate(
            ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name],
        )->id;
    }

    /** One above the highest plain number or sort position. Deleted lines keep theirs, so no number is reused. */
    private function nextCode(Project $project): string
    {
        $lines = BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->get(['code', 'sort']);

        $numbers = $lines->pluck('code')
            ->filter(fn (string $code) => preg_match('/^\d{1,15}$/', $code) === 1)
            ->map(fn (string $code) => (int) $code);

        return (string) ($numbers->push((int) $lines->max('sort'))->max() + 1);
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(int $organizationId, string $uuid): ?BoqItem
    {
        return ClientUuidRepeat::reachable(BoqItem::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
