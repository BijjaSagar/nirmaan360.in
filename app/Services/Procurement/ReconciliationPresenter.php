<?php

namespace App\Services\Procurement;

use Illuminate\Support\Collection;

/** Shapes ReconciliationService's figures for the mobile API. Every figure is a quantity in the material's base unit. */
class ReconciliationPresenter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, array<string, mixed>>  $unallocated
     * @param  Collection<int, array<string, mixed>>  $belowReorder
     * @return array<string, mixed>
     */
    public function present(string $basis, array $rows, Collection $unallocated, Collection $belowReorder): array
    {
        $counts = array_count_values(array_column($rows, 'status'));

        return [
            'basis' => $basis,
            'bases' => array_keys(ReconciliationService::BASES),
            'counts' => [
                'wastage' => $counts['wastage'] ?? 0,
                'under' => $counts['under'] ?? 0,
                'no_work' => $counts['no_work'] ?? 0,
                'no_norm' => $counts['no_norm'] ?? 0,
                'ok' => $counts['ok'] ?? 0,
            ],
            'rows' => array_values($rows),
            'unallocated' => $unallocated->map(fn (array $row) => [
                'material_id' => $row['material']->id,
                'material_name' => $row['material']->name,
                'unit' => $row['material']->baseUnit?->code,
                'activity' => $row['activity'],
                'actual' => $row['actual'],
            ])->values()->all(),
            'below_reorder' => $belowReorder->map(fn (array $row) => [
                'store_id' => $row['store']->id,
                'store' => $row['store']->name,
                'material_id' => $row['material']->id,
                'material_name' => $row['material']->name,
                'unit' => $row['material']->baseUnit?->code,
                'balance' => $row['balance'],
                'reorder_level' => $row['reorder_level'],
                'shortfall' => $row['shortfall'],
            ])->values()->all(),
        ];
    }
}
