<?php

namespace App\Services;

use App\Models\BoqItem;
use App\Support\Dec;

/**
 * Each BOQ line's value (BOQ quantity × rate) and the value of what is still to be measured
 * (balance quantity × rate), with totals for each section and for the whole BOQ. The web BOQ page
 * and the app's BOQ list both read it, so the figures always agree. It is all money: callers
 * work it out only for someone who may see money.
 */
final class BoqValues
{
    /**
     * @param  iterable<BoqItem>  $items  Loaded with the withLedger scope and the section.
     * @return array{lines: array<int, array{amount:string, balance:string}>, sections: array<string, array{amount:string, balance:string}>, grand: array{amount:string, balance:string}}
     */
    public static function of(iterable $items): array
    {
        $lines = [];
        $sections = [];
        $grand = ['amount' => Dec::of(0), 'balance' => Dec::of(0)];

        foreach ($items as $item) {
            $amount = Dec::of($item->amount());
            $balance = Dec::of(self::balance($item));
            $section = (string) $item->section?->name;

            $lines[$item->id] = ['amount' => Dec::money($amount), 'balance' => Dec::money($balance)];
            $sections[$section]['amount'] = ($sections[$section]['amount'] ?? Dec::of(0))->plus($amount);
            $sections[$section]['balance'] = ($sections[$section]['balance'] ?? Dec::of(0))->plus($balance);
            $grand['amount'] = $grand['amount']->plus($amount);
            $grand['balance'] = $grand['balance']->plus($balance);
        }

        $money = fn (array $pair) => ['amount' => Dec::money($pair['amount']), 'balance' => Dec::money($pair['balance'])];

        return ['lines' => $lines, 'sections' => array_map($money, $sections), 'grand' => $money($grand)];
    }

    /** The value of what is still to be measured on one line. */
    public static function balance(BoqItem $item): string
    {
        return Dec::money(Dec::of($item->balanceQty())->multipliedBy(Dec::of($item->rate)));
    }
}
