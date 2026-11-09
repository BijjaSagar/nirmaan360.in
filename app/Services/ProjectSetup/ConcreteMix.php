<?php

namespace App\Services\ProjectSetup;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\Material;
use App\Models\MaterialUnitConversion;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Concrete can be entered by the cubic metre when it comes ready-mixed (RMC), or by what goes
 * into it when it is mixed on site: cement in bags, aggregate, sand in brass.
 *
 * The ingredients of a mixed-on-site line are kept in boq_material_norms, per unit of the line,
 * which is the table the stock reconciliation already reads. Entering 100 bags of cement for a
 * 37 cum slab stores 2.702703 bags per cum, so the totals follow the line if its quantity is
 * revised. The rate of the line is not worked out from the ingredients: it stays the figure the
 * company entered.
 */
class ConcreteMix
{
    public const MODES = ['rmc', 'ingredients'];

    /** Role stored on the norm => label. */
    public const ROLES = [
        'cement' => 'Cement',
        'coarse_aggregate' => 'Coarse aggregate',
        'sand' => 'Sand (fine aggregate)',
    ];

    public function rules(): array
    {
        $rules = [
            'mode' => ['required', Rule::in(self::MODES)],
            'reason' => ['required', 'string', 'max:500'],
        ];

        foreach (array_keys(self::ROLES) as $role) {
            $rules["{$role}_material_id"] = ['bail', 'required_if:mode,ingredients', 'nullable', 'integer', org_exists('materials')];
            $rules["{$role}_qty"] = ['required_if:mode,ingredients', 'nullable', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999999'];
            $rules["{$role}_unit_id"] = ['nullable', 'integer', org_exists('material_units')];
        }

        return $rules;
    }

    /** True when the line is concrete that can be entered either way. */
    public function applies(BoqItem $item): bool
    {
        return in_array($item->category, BoqCategories::CONCRETE, true);
    }

    /**
     * How a line is entered now, with each ingredient's total for the whole line in the
     * material's base unit.
     *
     * @return array{mode:string,rows:array<string,array{material_id:int,material:string,qty:string,unit:string,unit_id:int}>}
     */
    public function current(BoqItem $item): array
    {
        $rows = [];

        $norms = BoqMaterialNorm::with('material.baseUnit')
            ->where('boq_item_id', $item->id)->whereNotNull('mix_role')->get();

        foreach ($norms as $norm) {
            $rows[$norm->mix_role] = [
                'material_id' => $norm->material_id,
                'material' => $norm->material->name,
                'qty' => Dec::qty(Dec::of($norm->qty_per_unit)->multipliedBy(Dec::of($item->qty))),
                'unit' => $norm->material->baseUnit->code,
                'unit_id' => $norm->material->base_unit_id,
            ];
        }

        return ['mode' => $rows === [] ? 'rmc' : 'ingredients', 'rows' => $rows];
    }

    /** Save how a line is entered. The change goes in the BOQ history with the reason. */
    public function save(BoqItem $item, array $data): void
    {
        DB::transaction(function () use ($item, $data) {
            $before = $this->describe($this->current($item));

            if ($data['mode'] === 'rmc') {
                BoqMaterialNorm::where('boq_item_id', $item->id)->whereNotNull('mix_role')->get()->each->delete();
            } else {
                $this->saveIngredients($item, $data);
            }

            $after = $this->describe($this->current($item->refresh()));

            if ($before !== $after) {
                BoqRevisions::with($data['reason'], 'concrete', fn () => BoqRevisions::note($item, ['concrete_mix' => [$before, $after]]));
            }
        });
    }

    private function saveIngredients(BoqItem $item, array $data): void
    {
        if (! Dec::of($item->qty)->isPositive()) {
            throw ValidationException::withMessages(['mode' => __('Enter the quantity of this item in the BOQ first. The ingredients are worked out per unit of it.')]);
        }

        $materials = array_map(fn (string $role) => (int) $data["{$role}_material_id"], array_keys(self::ROLES));

        if (count(array_unique($materials)) !== count($materials)) {
            throw ValidationException::withMessages(['cement_material_id' => __('Use a different material for each ingredient.')]);
        }

        $perUnit = [];

        foreach (array_keys(self::ROLES) as $role) {
            $material = Material::findOrFail($data["{$role}_material_id"]);
            $total = Dec::of($data["{$role}_qty"])->multipliedBy($this->factor($material, $data["{$role}_unit_id"] ?? null, $role));
            $perUnit[$role] = [$material, $total->dividedBy(Dec::of($item->qty), 6, RoundingMode::HalfUp)];

            if (! $perUnit[$role][1]->isPositive()) {
                throw ValidationException::withMessages(["{$role}_qty" => __('This quantity is too small for the size of the item.')]);
            }
        }

        // An ingredient that changed material, or a role that moved, is replaced rather than edited.
        BoqMaterialNorm::where('boq_item_id', $item->id)->whereNotNull('mix_role')->get()
            ->reject(fn (BoqMaterialNorm $norm) => isset($perUnit[$norm->mix_role]) && $perUnit[$norm->mix_role][0]->id === $norm->material_id)
            ->each->delete();

        foreach ($perUnit as $role => [$material, $quantity]) {
            BoqMaterialNorm::updateOrCreate(
                ['boq_item_id' => $item->id, 'material_id' => $material->id],
                [
                    'organization_id' => $item->organization_id,
                    'project_id' => $item->project_id,
                    'qty_per_unit' => (string) $quantity,
                    'mix_role' => $role,
                ],
            );
        }
    }

    /** How many base units one of the chosen unit is. The base unit itself, or none chosen, is 1. */
    private function factor(Material $material, mixed $unitId, string $role): BigDecimal
    {
        if (blank($unitId) || (int) $unitId === (int) $material->base_unit_id) {
            return Dec::of(1);
        }

        $conversion = MaterialUnitConversion::where('material_id', $material->id)->where('unit_id', $unitId)->first();

        if (! $conversion) {
            throw ValidationException::withMessages(["{$role}_unit_id" => __('This unit has no conversion for :material. Add it to the material first.', ['material' => $material->name])]);
        }

        return Dec::of($conversion->factor);
    }

    /** One line of text for the BOQ history. */
    private function describe(array $mix): string
    {
        if ($mix['mode'] === 'rmc') {
            return __('Ready-mix (RMC), by cubic metre');
        }

        return __('Mixed on site').': '.collect(self::ROLES)
            ->filter(fn (string $label, string $role) => isset($mix['rows'][$role]))
            ->map(fn (string $label, string $role) => __($label).': '.$mix['rows'][$role]['material'].', '.$mix['rows'][$role]['qty'].' '.$mix['rows'][$role]['unit'])
            ->implode('; ');
    }
}
