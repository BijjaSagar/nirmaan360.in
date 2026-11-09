<?php

namespace App\Services\Procurement;

use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Models\StockLevel;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only code that writes stock_ledger.
 *
 * Stock is the sum of the movements, with no stored total to drift. To keep it from going
 * negative, a writer locks the store-and-material row, reads the sum, then appends, all in
 * one transaction. A second writer for that pair waits on the lock and then sums rows the
 * first one committed. Locks are taken in sorted order so two documents cannot deadlock.
 * Callers that lock a parent document (a purchase order, a transfer) do so before this.
 */
class StockLedgerService
{
    public const OPENING = 'opening';

    public const ADJUSTMENT = 'adjustment';

    public const GRN = 'grn';

    public const ISSUE = 'issue';

    public const RETURN_IN = 'return_in';

    public const RETURN_OUT = 'return_out';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const REVERSAL = 'reversal';

    public function __construct(private ProcurementAlerts $alerts) {}

    /**
     * Lock the given store-and-material pairs for the rest of the transaction.
     *
     * @param  iterable<array{store_id:int|string, material_id:int|string}>  $pairs
     * @return array<string, StockLevel> Keyed "store:material".
     */
    public function lockPairs(int $organizationId, iterable $pairs): array
    {
        $sorted = collect($pairs)
            ->map(fn ($pair) => [(int) $pair['store_id'], (int) $pair['material_id']])
            ->unique(fn ($pair) => $pair[0].':'.$pair[1])
            ->sort(fn ($a, $b) => $a <=> $b)
            ->values();

        $levels = [];
        $now = now();

        foreach ($sorted as [$storeId, $materialId]) {
            StockLevel::withoutGlobalScopes()->insertOrIgnore([
                'organization_id' => $organizationId,
                'store_id' => $storeId,
                'material_id' => $materialId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $levels[$storeId.':'.$materialId] = StockLevel::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('store_id', $storeId)
                ->where('material_id', $materialId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $levels;
    }

    /**
     * Append movements atomically. All of them post, or none do.
     *
     * Each movement needs project_id, store_id, material_id, type, qty (signed, base units),
     * date, source (a model) and key (unique per company). boq_item_id, activity and
     * reverses_id are optional.
     *
     * @param  list<array<string, mixed>>  $movements
     * @return list<StockLedgerEntry>
     */
    public function post(int $organizationId, array $movements, ?User $by): array
    {
        return DB::transaction(function () use ($organizationId, $movements, $by) {
            $levels = $this->lockPairs($organizationId, $movements);

            // Read only after every lock is held, so the sums include all committed rows.
            $opening = array_map(fn (StockLevel $level) => $this->balance($level->store_id, $level->material_id), $levels);
            $running = $opening;
            $rows = [];

            foreach ($movements as $movement) {
                $key = $movement['store_id'].':'.$movement['material_id'];
                $qty = Dec::of($movement['qty']);
                $after = Dec::of($running[$key])->plus($qty);

                if ($qty->isNegative() && $after->isNegative()) {
                    $this->refuse($movement, $running[$key]);
                }

                $running[$key] = Dec::qty($after);
                $rows[] = StockLedgerEntry::create([
                    'organization_id' => $organizationId,
                    'project_id' => $movement['project_id'],
                    'store_id' => $movement['store_id'],
                    'material_id' => $movement['material_id'],
                    'movement_type' => $movement['type'],
                    'qty' => Dec::qty($qty),
                    'movement_date' => $movement['date'],
                    'source_type' => $movement['source']->getMorphClass(),
                    'source_id' => $movement['source']->getKey(),
                    'reverses_id' => $movement['reverses_id'] ?? null,
                    'boq_item_id' => $movement['boq_item_id'] ?? null,
                    'activity' => $movement['activity'] ?? null,
                    'posting_key' => $movement['key'],
                    'created_by' => $by?->id,
                    'created_at' => now(),
                ]);
            }

            $this->alertOnCrossing($levels, $opening, $running);

            return $rows;
        });
    }

    /** Post the exact opposite of everything a document posted. */
    public function reverse(Model $document, ?User $by): void
    {
        $rows = StockLedgerEntry::withoutGlobalScopes()
            ->where('organization_id', $document->organization_id)
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->where('movement_type', '!=', self::REVERSAL)
            ->orderBy('id')
            ->get();

        $this->post($document->organization_id, $rows->map(fn (StockLedgerEntry $row) => [
            'project_id' => $row->project_id,
            'store_id' => $row->store_id,
            'material_id' => $row->material_id,
            'type' => self::REVERSAL,
            'qty' => Dec::qty(Dec::of($row->qty)->negated()),
            'date' => today(),
            'source' => $document,
            'key' => 'reversal:'.$row->id,
            'reverses_id' => $row->id,
            'boq_item_id' => $row->boq_item_id,
            'activity' => $row->activity,
        ])->all(), $by);
    }

    /** Stock is the sum of its movements. */
    public function balance(int $storeId, int $materialId): string
    {
        return Dec::qty(StockLedgerEntry::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('material_id', $materialId)
            ->sum('qty'));
    }

    /** @return Collection<string, string> Balances keyed "store:material". */
    public function projectBalances(Project $project): Collection
    {
        return StockLedgerEntry::where('project_id', $project->id)
            ->selectRaw('store_id, material_id, SUM(qty) AS balance')
            ->groupBy('store_id', 'material_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->store_id.':'.$row->material_id => Dec::qty($row->balance)]);
    }

    /** Every movement for one store and material, newest first, with the running balance. */
    public function stockCard(MaterialStore $store, Material $material, int $perPage = 50): LengthAwarePaginator
    {
        return StockLedgerEntry::with('creator', 'boqItem')
            ->where('store_id', $store->id)
            ->where('material_id', $material->id)
            ->select('*')
            ->selectRaw('SUM(qty) OVER (ORDER BY id) AS running_balance')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $movement */
    private function refuse(array $movement, string $available): never
    {
        $material = Material::withoutGlobalScopes()->with('baseUnit')->findOrFail($movement['material_id']);
        $store = MaterialStore::withoutGlobalScopes()->findOrFail($movement['store_id']);

        throw ValidationException::withMessages([
            'stock' => __('Only :qty :unit of :material is available in :store.', [
                'qty' => fmt_qty($available),
                'unit' => $material->baseUnit->code,
                'material' => $material->name,
                'store' => $store->name,
            ]),
        ]);
    }

    /**
     * Alert once, when a movement takes stock from at or above the reorder level to below it.
     *
     * @param  array<string, StockLevel>  $levels
     * @param  array<string, string>  $opening
     * @param  array<string, string>  $closing
     */
    private function alertOnCrossing(array $levels, array $opening, array $closing): void
    {
        foreach ($levels as $key => $level) {
            if ($level->reorder_level === null) {
                continue;
            }

            $reorder = Dec::of($level->reorder_level);

            if (Dec::of($opening[$key])->isGreaterThanOrEqualTo($reorder) && Dec::of($closing[$key])->isLessThan($reorder)) {
                $this->alerts->lowStock(
                    MaterialStore::withoutGlobalScopes()->findOrFail($level->store_id),
                    Material::withoutGlobalScopes()->findOrFail($level->material_id),
                    $closing[$key],
                    $level->reorder_level,
                );
            }
        }
    }
}
