<?php

namespace App\Services\Procurement;

use App\Models\Material;
use App\Support\Dec;
use Illuminate\Validation\ValidationException;

class UnitConverter
{
    /**
     * Convert a quantity into the material's base unit, which is what the ledger holds.
     * Worked example: cement with base unit kg and 1 bag = 50 kg, so 12.5 bags = 625.000 kg.
     */
    public function toBase(Material $material, mixed $qty, ?int $unitId): string
    {
        $unitId ??= $material->base_unit_id;

        if ((int) $unitId === (int) $material->base_unit_id) {
            return Dec::qty($qty);
        }

        $conversion = $material->conversions->firstWhere('unit_id', $unitId);

        if (! $conversion) {
            throw ValidationException::withMessages([
                'unit_id' => __('This unit has no conversion set up for :material.', ['material' => $material->name]),
            ]);
        }

        $base = Dec::qty(Dec::of($qty)->multipliedBy(Dec::of($conversion->factor)));

        if (! Dec::of($base)->isPositive()) {
            throw ValidationException::withMessages([
                'qty' => __('This quantity is too small to record for :material.', ['material' => $material->name]),
            ]);
        }

        return $base;
    }
}
