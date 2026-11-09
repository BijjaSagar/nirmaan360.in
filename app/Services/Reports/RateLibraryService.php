<?php

namespace App\Services\Reports;

use App\Models\Concerns\OrganizationScope;
use App\Models\RateLibraryItem;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The company rate library. An item can carry a rate analysis split into material, labour, plant,
 * overhead and profit. When any part is filled in, the rate is the total of the parts.
 */
class RateLibraryService
{
    public function rules(): array
    {
        $parts = array_fill_keys(RateLibraryItem::PARTS, ['nullable', 'numeric', 'min:0', 'max:999999999999']);

        return [
            'item' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:80'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'required_without_all:'.implode(',', RateLibraryItem::PARTS)],
            ...$parts,
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Add an item. A repeated client_uuid returns the original row. */
    public function create(User $user, array $data): RateLibraryItem
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($user->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            fn () => RateLibraryItem::create([
                ...$this->values($data),
                'organization_id' => $user->organization_id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findByClientUuid($user->organization_id, (string) $uuid),
        );
    }

    public function update(RateLibraryItem $item, array $data): RateLibraryItem
    {
        $item->update($this->values($data));

        return $item;
    }

    /** Items for the screen and for the picker on the BOQ form. Matches the name or the category. */
    public function query(?string $term = null, ?string $category = null): Builder
    {
        $term = trim((string) $term);

        return RateLibraryItem::query()
            ->when($category, fn (Builder $q) => $q->where('category', $category))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

                $q->where(fn (Builder $w) => $w
                    ->whereRaw("lower(item) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(category) like ? escape '\\'", [$like]));
            })
            ->orderBy('item')->orderBy('id');
    }

    /** @return list<string> */
    public function categories(): array
    {
        return RateLibraryItem::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
    }

    /**
     * The columns to save: money to two places, and the rate worked out from the parts when there are any.
     *
     * @return array<string, ?string>
     */
    private function values(array $data): array
    {
        $values = [
            'item' => trim($data['item']),
            'unit' => trim($data['unit']),
            'category' => filled($data['category'] ?? null) ? trim($data['category']) : null,
        ];

        $total = Dec::of(0);
        $split = false;

        foreach (RateLibraryItem::PARTS as $part) {
            $filled = filled($data[$part] ?? null);
            $values[$part] = $filled ? Dec::money($data[$part]) : null;

            if ($filled) {
                $split = true;
                $total = $total->plus(Dec::of($values[$part]));
            }
        }

        // Five parts of at most 12 digits each always fit the 15-digit money column.
        $values['rate'] = Dec::money($split ? $total : $data['rate']);

        return $values;
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(int $organizationId, string $uuid): ?RateLibraryItem
    {
        return RateLibraryItem::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
