<?php

namespace App\Services\BoqScan;

/**
 * Pairs the rows of the first reading of a page with the amounts of the second. Pure code.
 *
 * The two readings are separate requests, so neither knows the other's row numbers. They are put
 * together by what both copied from the page:
 * 1. an item number that appears exactly once in each reading;
 * 2. among the rest, in order from the top when both have the same number left;
 * 3. otherwise by height on the page, nearest first, within a small distance.
 * A row with no partner has no amount. An amount with no partner is reported, for a person to place.
 */
final class AmountPairing
{
    /**
     * @param  list<array{key: string, y: int}>  $rows  First reading, top to bottom: the item number key (ItemNumber::key) and the middle of the row in parts per thousand.
     * @param  list<array{key: string, y: int}>  $amounts  Second reading, the same.
     * @param  int  $within  Largest height difference for step 3, in parts per thousand.
     * @return array{pairs: array<int, int>, extra: list<int>} Row index => amount index, and the amount indexes left over.
     */
    public static function pair(array $rows, array $amounts, int $within): array
    {
        $pairs = [];
        $rowKeys = array_count_values(array_filter(array_column($rows, 'key'), fn ($key) => $key !== ''));
        $amountKeys = array_count_values(array_filter(array_column($amounts, 'key'), fn ($key) => $key !== ''));

        foreach ($rows as $r => $row) {
            $key = $row['key'];

            if ($key !== '' && ($rowKeys[$key] ?? 0) === 1 && ($amountKeys[$key] ?? 0) === 1) {
                $pairs[$r] = array_search($key, array_column($amounts, 'key'), true);
            }
        }

        $taken = array_flip($pairs);
        $restRows = array_values(array_filter(array_keys($rows), fn (int $r) => ! isset($pairs[$r])));
        $restAmounts = array_values(array_filter(array_keys($amounts), fn (int $a) => ! isset($taken[$a])));

        if ($restRows !== [] && count($restRows) === count($restAmounts)) {
            foreach ($restRows as $i => $r) {
                $pairs[$r] = $restAmounts[$i];
            }

            $restAmounts = [];
        } elseif ($restRows !== [] && $restAmounts !== []) {
            $candidates = [];

            foreach ($restRows as $r) {
                foreach ($restAmounts as $a) {
                    $distance = abs((int) $rows[$r]['y'] - (int) $amounts[$a]['y']);

                    if ($distance <= $within) {
                        $candidates[] = [$distance, $r, $a];
                    }
                }
            }

            sort($candidates);
            $usedAmounts = [];

            foreach ($candidates as [, $r, $a]) {
                if (! isset($pairs[$r]) && ! isset($usedAmounts[$a])) {
                    $pairs[$r] = $a;
                    $usedAmounts[$a] = true;
                }
            }

            $restAmounts = array_values(array_filter($restAmounts, fn (int $a) => ! isset($usedAmounts[$a])));
        }

        ksort($pairs);

        return ['pairs' => $pairs, 'extra' => $restAmounts];
    }
}
