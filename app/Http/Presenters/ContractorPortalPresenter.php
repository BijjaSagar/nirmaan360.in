<?php

namespace App\Http\Presenters;

use App\Models\Party;

/**
 * The contractor portal's API shape. The service already builds each row from named keys; this
 * only drops the models the Blade views use, so the JSON carries plain fields.
 */
final class ContractorPortalPresenter
{
    public static function contractor(Party $party): array
    {
        return ['party_id' => $party->id, 'name' => $party->name];
    }

    /** @param  array<string, mixed>  $row  A contract row from ContractorPortalService. */
    public static function contract(array $row): array
    {
        unset($row['work_order'], $row['project']);

        return $row;
    }
}
