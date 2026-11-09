<?php

namespace App\Http\Presenters;

use App\Models\PettyCashEntry;

final class PettyEntryPresenter
{
    public static function present(PettyCashEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'client_uuid' => $entry->client_uuid,
            'petty_cash_wallet_id' => $entry->petty_cash_wallet_id,
            'type' => $entry->type,
            'amount' => $entry->amount,
            'category' => $entry->category,
            'description' => $entry->description,
            'entry_date' => $entry->entry_date?->toDateString(),
            'created_by' => $entry->created_by,
            'has_bill' => $entry->bill_path !== null,
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }
}
