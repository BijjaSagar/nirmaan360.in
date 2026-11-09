<?php

namespace App\Services\PriceWatch;

use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use App\Models\User;
use App\Services\PriceWatch\Contracts\PriceSource;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every way a price gets onto a company's list: a person types it, a sheet is uploaded
 * (PriceSheet calls record() per row), or the configured sources are asked. Also the watched
 * materials themselves. A price is only ever appended; the dated list is the record.
 */
class PriceRecorder
{
    public function materialRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'unit' => ['required', 'string', 'max:20'],
            'material_id' => ['nullable', 'integer', org_exists('materials')],
            'is_active' => ['nullable', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function priceRules(): array
    {
        return [
            'price_watch_material_id' => ['required', 'integer', org_exists('price_watch_materials')],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'priced_on' => ['required', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:160'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Put a material of the company's own on the board. A repeated client_uuid returns the row made the first time. */
    public function addMaterial(int $organizationId, array $data, ?User $by = null): PriceWatchMaterial
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findMaterialByUuid($organizationId, $uuid))) {
            return $existing;
        }

        $this->refuseDuplicateName($organizationId, $data['name']);

        return Idempotent::create(
            fn () => PriceWatchMaterial::create([
                'organization_id' => $organizationId,
                'name' => trim($data['name']),
                'unit' => trim($data['unit']),
                'material_id' => $data['material_id'] ?? null,
                'is_active' => true,
                'sort' => (int) PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', $organizationId)->max('sort') + 10,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findMaterialByUuid($organizationId, (string) $uuid),
        );
    }

    /** Rename, relink or switch a material off. Its unit is fixed once a price is on record, or the list would stop comparing like with like. */
    public function updateMaterial(PriceWatchMaterial $material, array $data): PriceWatchMaterial
    {
        $unit = trim($data['unit']);

        if (strcasecmp($unit, $material->unit) !== 0 && $material->prices()->exists()) {
            throw ValidationException::withMessages(['unit' => __('The unit cannot change once prices are recorded. Add a new material instead.')]);
        }

        $this->refuseDuplicateName($material->organization_id, $data['name'], $material->id);

        $material->update([
            'name' => trim($data['name']),
            'unit' => $unit,
            'material_id' => $data['material_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return $material;
    }

    /**
     * Append one dated price. Returns null when the list already has that rate for that day, so
     * saving or uploading twice adds nothing. A repeated client_uuid returns the row made the first time.
     */
    public function record(PriceWatchMaterial $material, array $data, ?User $by = null, string $kind = MaterialPrice::MANUAL): ?MaterialPrice
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findPriceByUuid($material->organization_id, $uuid))) {
            return $existing;
        }

        $rate = Dec::money($data['rate']);
        $pricedOn = substr((string) $data['priced_on'], 0, 10);
        $sameDay = MaterialPrice::withoutGlobalScopes()->where('price_watch_material_id', $material->id)->whereDate('priced_on', $pricedOn)->get();

        if ($sameDay->contains(fn (MaterialPrice $price) => Dec::money($price->rate) === $rate)) {
            return null;
        }

        return Idempotent::create(
            fn () => MaterialPrice::create([
                'organization_id' => $material->organization_id,
                'price_watch_material_id' => $material->id,
                'rate' => $rate,
                'unit' => $material->unit,
                'priced_on' => $pricedOn,
                'source' => filled($data['source'] ?? null) ? trim($data['source']) : null,
                'source_kind' => $kind,
                'noted_by' => $by?->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findPriceByUuid($material->organization_id, (string) $uuid),
        );
    }

    /** @return list<PriceSource> The sources config/modules/price_watch.php lists, in order. */
    public function sources(): array
    {
        return array_map(fn (string $class) => app($class), config('modules.price_watch.sources', []));
    }

    /**
     * Ask every connected source and append what the company does not have yet: a quote for a
     * material it watches, with the same unit, newer than its latest own price. A material the
     * company switched off is left alone; one it has never heard of is added to the board.
     *
     * @return array{added: int, created: int, skipped: int, sources: list<string>}
     */
    public function pull(int $organizationId, ?User $by = null, ?CarbonInterface $today = null): array
    {
        $today ??= today();
        $result = ['added' => 0, 'created' => 0, 'skipped' => 0, 'sources' => []];

        foreach ($this->sources() as $source) {
            if (! $source->isConnected()) {
                continue;
            }

            $result['sources'][] = $source->label();

            DB::transaction(function () use ($organizationId, $source, $by, $today, &$result) {
                $materials = PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', $organizationId)->get();

                foreach ($source->quotes() as $quote) {
                    if ($quote->pricedOn > $today->toDateString()) {
                        $result['skipped']++;

                        continue;
                    }

                    $material = $materials->firstWhere('key', $quote->key)
                        ?? $materials->first(fn (PriceWatchMaterial $material) => $material->key === null && mb_strtolower($material->name) === mb_strtolower($quote->name));

                    if ($material === null) {
                        $material = PriceWatchMaterial::create([
                            'organization_id' => $organizationId,
                            'key' => $quote->key,
                            'name' => $quote->name,
                            'unit' => $quote->unit,
                            'is_active' => true,
                            'sort' => (int) $materials->max('sort') + 10,
                        ]);
                        $materials->push($material);
                        $result['created']++;
                    } elseif ($material->key === null) {
                        $material->update(['key' => $quote->key]);
                    }

                    if (! $material->is_active || strcasecmp($material->unit, $quote->unit) !== 0) {
                        $result['skipped']++;

                        continue;
                    }

                    $latest = $material->prices()->first();

                    if ($latest && $latest->priced_on->toDateString() > $quote->pricedOn) {
                        $result['skipped']++;

                        continue;
                    }

                    $saved = $this->record($material, ['rate' => $quote->rate, 'priced_on' => $quote->pricedOn, 'source' => $quote->source], $by, $source->key());
                    $saved === null ? $result['skipped']++ : $result['added']++;
                }
            });
        }

        return $result;
    }

    private function refuseDuplicateName(int $organizationId, string $name, ?int $exceptId = null): void
    {
        $taken = PriceWatchMaterial::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->get(['id', 'name'])
            ->contains(fn (PriceWatchMaterial $material) => mb_strtolower($material->name) === mb_strtolower(trim($name)));

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('This material is already on the board.')]);
        }
    }

    private function findMaterialByUuid(int $organizationId, string $uuid): ?PriceWatchMaterial
    {
        return ClientUuidRepeat::reachable(PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    private function findPriceByUuid(int $organizationId, string $uuid): ?MaterialPrice
    {
        return ClientUuidRepeat::reachable(MaterialPrice::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
