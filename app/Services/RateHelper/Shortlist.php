<?php

namespace App\Services\RateHelper;

use App\Models\BoqItem;
use App\Models\User;
use App\Services\RateHelper\Contracts\RateSource;

/**
 * Builds the shortlist for one BOQ line from the sources: the best matches by description across
 * all of them, then any reference figure. Plain code throughout; the order is the code score alone.
 */
class Shortlist
{
    /**
     * @param  array<string, RateSource>  $sources  From RateSources::open(), shared across the lines of one run.
     * @return list<array{ref:string,source:string,row_id:int,score:?int,same_work:null,note:null}>
     *                                                                                              What is stored on the line. It holds no rate, no description and no project or tender name.
     */
    public function for(array $sources, User $viewer, BoqItem $item): array
    {
        // A lump-sum line has no rate to look for.
        if (UnitGate::key($item->unit) === null || $viewer->organization === null) {
            return [];
        }

        $scored = [];
        $reference = [];
        $order = 0;

        foreach ($sources as $source) {
            if (! $source->isConnected($viewer->organization) || ! $source->allows($viewer)) {
                continue;
            }

            foreach ($source->candidates($viewer, $item) as $candidate) {
                if ($candidate->score === null) {
                    $reference[] = $candidate;
                } else {
                    $scored[] = [$candidate, $order++];
                }
            }
        }

        // Best score first. Equal scores keep the order of the sources, and within a source the newest row.
        usort($scored, fn (array $a, array $b) => [$b[0]->score, $a[1]] <=> [$a[0]->score, $b[1]]);
        $kept = array_slice(array_column($scored, 0), 0, max(0, (int) config('modules.rate_helper.shortlist_size', 5)));

        $list = [];

        foreach ([...$kept, ...$reference] as $index => $candidate) {
            $list[] = [
                'ref' => 'C'.($index + 1),
                'source' => $candidate->source,
                'row_id' => $candidate->rowId,
                'score' => $candidate->score,
                // Filled in by a later ranking, when there is one. Never a rate.
                'same_work' => null,
                'note' => null,
            ];
        }

        return $list;
    }
}
