<?php

namespace App\Http\Presenters;

use App\Models\BoqItem;
use App\Services\BoqValues;
use App\Support\Dec;
use App\Support\Money;

/** Expects a row loaded with the withLedger scope. The rate and the values go out as null to anyone who may not see money. */
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
            'rate' => Money::orNull($item->rate),
            // Quantity × rate, and the value still to be measured: money, so null to anyone who may not see it.
            'amount' => Money::visible() ? $item->amount() : null,
            'balance_amount' => Money::visible() ? BoqValues::balance($item) : null,
            'sort' => (int) $item->sort,
            ...self::ledger($item),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The totals for the app's BOQ list, from BoqValues: the whole BOQ and each section in BOQ order.
     * A line with no section counts under section null.
     *
     * @param  array{sections: array<string, array{amount:string, balance:string}>, grand: array{amount:string, balance:string}}  $values
     */
    public static function totals(array $values): array
    {
        return [
            'amount' => $values['grand']['amount'],
            'balance_amount' => $values['grand']['balance'],
            'sections' => collect($values['sections'])->map(fn (array $pair, string $name) => [
                'section' => $name === '' ? null : $name,
                'amount' => $pair['amount'],
                'balance_amount' => $pair['balance'],
            ])->values()->all(),
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
