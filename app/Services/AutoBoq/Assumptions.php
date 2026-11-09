<?php

namespace App\Services\AutoBoq;

use App\Models\AutoBoqAssumption;
use App\Models\Organization;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * The typical figures the calculation leans on when a drawing cannot give the number: steel per
 * cubic metre of each element, the working space dug around a footing, PCC thickness, and so on.
 * They are starting values. Each company's engineer changes them to match how the company builds,
 * and the company's values are kept per key, so a new default never overwrites a chosen one.
 */
final class Assumptions
{
    /** key => [default, unit, group, label, note]. Labels and notes are translated where shown. */
    public const CATALOGUE = [
        'excavation_margin' => ['0.300', 'm', 'earthwork', 'Working space around each footing', 'Added on every side of a footing to get the size of the pit.'],
        'pcc_offset' => ['0.100', 'm', 'earthwork', 'PCC projection beyond the footing', 'Added on every side of a footing to get the size of the PCC bed.'],
        'pcc_thickness' => ['0.100', 'm', 'earthwork', 'PCC thickness under footings', ''],
        'pcc_floor_thickness' => ['0.100', 'm', 'earthwork', 'PCC bed under ground-floor flooring', 'Laid over the plinth filling. Enter 0 to leave it out.'],
        'steel_footing' => ['80.000', 'kg/cum', 'steel', 'Steel in footings', ''],
        'steel_column' => ['160.000', 'kg/cum', 'steel', 'Steel in columns', ''],
        'steel_beam' => ['150.000', 'kg/cum', 'steel', 'Steel in beams', ''],
        'steel_slab' => ['90.000', 'kg/cum', 'steel', 'Steel in slabs', ''],
        'steel_stair' => ['120.000', 'kg/cum', 'steel', 'Steel in staircases', ''],
        'steel_chajja' => ['100.000', 'kg/cum', 'steel', 'Steel in chajjas and lofts', ''],
        'stair_thickness' => ['0.300', 'm', 'staircase', 'Staircase concrete per square metre of plan area', 'The waist slab plus half the steps, as one equivalent thickness.'],
        'stair_centering' => ['1.500', 'sqm/sqm', 'staircase', 'Staircase centering per square metre of plan area', 'The sloping soffit, the risers and the sides.'],
        'carpet_share' => ['0.850', 'ratio', 'finishing', 'Share of floor area that is floored', 'Walls, ducts and shafts take the rest. 0.85 means 85%.'],
    ];

    /** Headings of the assumptions table, in order. */
    public const GROUPS = ['earthwork' => 'Earthwork and PCC', 'steel' => 'Steel', 'staircase' => 'Staircase', 'finishing' => 'Finishing'];

    public const MAX = '99999';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** @return array<string, string> key => default value */
    public static function defaults(): array
    {
        return array_map(fn (array $row) => $row[0], self::CATALOGUE);
    }

    /**
     * The company's values: its own where it has set one, the default everywhere else.
     *
     * @return array<string, string>
     */
    public function forOrganization(Organization|int $organization): array
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $own = AutoBoqAssumption::withoutGlobalScopes()->where('organization_id', $id)->pluck('value', 'key');
        $values = [];

        foreach (self::CATALOGUE as $key => $row) {
            $values[$key] = Dec::qty($own[$key] ?? $row[0]);
        }

        return $values;
    }

    /** Which keys the company has changed from the default. @return list<string> */
    public function changed(Organization|int $organization): array
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return AutoBoqAssumption::withoutGlobalScopes()->where('organization_id', $id)->whereIn('key', self::keys())->pluck('key')->all();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = ['values' => ['required', 'array']];

        foreach (self::keys() as $key) {
            $rules["values.{$key}"] = ['required', 'numeric', 'min:0', 'max:'.self::MAX];
        }

        return $rules;
    }

    /**
     * Save the company's values. A value equal to the default is still kept as the company's own,
     * so a later change of default does not move a figure the engineer looked at and accepted.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Organization $organization, array $values, User $user): void
    {
        DB::transaction(function () use ($organization, $values, $user) {
            foreach (self::keys() as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $row = AutoBoqAssumption::withoutGlobalScopes()->firstOrNew(['organization_id' => $organization->id, 'key' => $key]);
                $row->value = Dec::qty(is_string($values[$key]) ? trim($values[$key]) : $values[$key]);
                $row->updated_by = $user->id;
                $row->save();
            }
        });
    }
}
