<?php

namespace App\Services\MaterialControl;

use App\Models\Concerns\ProjectAccessScope;
use App\Models\MaterialShortDelivery;
use App\Models\MaterialSupplierRecord;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** What the company knows about a supplier: short deliveries, a rating and any warning. */
class SupplierRecordService
{
    /** Short more than once is what the discussion calls "repeatedly". */
    public const WARN_AT = 2;

    public function rules(): array
    {
        return [
            'rating' => ['nullable', 'integer', Rule::in([1, 2, 3, 4, 5])],
            'marked' => ['nullable', 'boolean'],
            'marked_reason' => ['nullable', 'string', 'max:500', 'required_if:marked,1'],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Party $party): array
    {
        return $this->summaries(collect([$party]), $party->organization_id)[$party->id];
    }

    /**
     * The warning follows the supplier across the company, so the count ignores project limits.
     *
     * @param  Collection<int, Party>  $parties
     * @return array<int, array<string, mixed>>
     */
    public function summaries(Collection $parties, int $organizationId): array
    {
        $ids = $parties->pluck('id');

        $counts = MaterialShortDelivery::withoutGlobalScope(ProjectAccessScope::class)
            ->where('organization_id', $organizationId)
            ->where('status', 'POSTED')
            ->whereIn('party_id', $ids)
            ->selectRaw('party_id, COUNT(*) AS short_count')
            ->groupBy('party_id')
            ->pluck('short_count', 'party_id');

        $records = MaterialSupplierRecord::where('organization_id', $organizationId)
            ->whereIn('party_id', $ids)
            ->get()
            ->keyBy('party_id');

        return $parties->mapWithKeys(function (Party $party) use ($counts, $records) {
            $shorts = (int) ($counts[$party->id] ?? 0);
            $record = $records->get($party->id);
            $notes = [];

            if ($record?->marked) {
                $notes[] = filled($record->marked_reason)
                    ? __('Marked: :reason', ['reason' => $record->marked_reason])
                    : __('Marked by your company.');
            }

            if ($shorts >= self::WARN_AT) {
                $notes[] = __('Delivered short :count times.', ['count' => $shorts]);
            }

            return [$party->id => [
                'party_id' => $party->id,
                'name' => $party->name,
                'short_deliveries' => $shorts,
                'rating' => $record?->rating ?? max(1, 5 - $shorts),
                'rating_by_hand' => $record?->rating !== null,
                'marked' => (bool) $record?->marked,
                'marked_reason' => $record?->marked_reason,
                'warn' => $notes !== [],
                'warning' => $notes === [] ? null : implode(' ', $notes),
            ]];
        })->all();
    }

    /**
     * Suppliers to choose from, each with any warning to show when they are picked.
     *
     * @return list<array<string, mixed>>
     */
    public function options(int $organizationId): array
    {
        $vendors = Party::where('organization_id', $organizationId)->where('type', 'vendor')->orderBy('name')->get(['id', 'name', 'organization_id']);

        return array_values($this->summaries($vendors, $organizationId));
    }

    public function save(Party $party, array $data, User $by): MaterialSupplierRecord
    {
        $record = MaterialSupplierRecord::firstOrNew(['organization_id' => $party->organization_id, 'party_id' => $party->id]);
        $marked = (bool) ($data['marked'] ?? false);

        $record->fill([
            'rating' => $data['rating'] ?? null,
            'marked' => $marked,
            'marked_reason' => $marked ? ($data['marked_reason'] ?? null) : null,
            'marked_by' => $marked ? $by->id : null,
            'marked_at' => $marked ? now() : null,
        ])->save();

        return $record;
    }
}
