<?php

namespace App\Http\Presenters;

use App\Models\BoqItem;
use App\Support\Dec;

/** Expects a row loaded with the withLedger scope. */
final class BoqItemPresenter
{
    public static function present(BoqItem $item): array
    {
        return [
            'id' => $item->id,
            'project_id' => $item->project_id,
            'section' => $item->section?->name,
            'code' => $item->code,
            'description' => $item->description,
            'unit' => $item->unit,
            'qty' => $item->qty,
            'rate' => $item->rate,
            'sort' => (int) $item->sort,
            ...self::ledger($item),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    public static function balance(BoqItem $item): array
    {
        return ['id' => $item->id, ...self::ledger($item)];
    }

    private static function ledger(BoqItem $item): array
    {
        return [
            'measured_qty' => Dec::qty($item->measured_qty ?? 0),
            'approved_qty' => Dec::qty($item->approved_qty ?? 0),
            'balance_qty' => $item->balanceQty(),
        ];
    }
}
