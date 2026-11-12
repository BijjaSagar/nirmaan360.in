<?php

namespace App\Services\RateHelper;

use App\Models\BoqItem;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Services\RateHelper\Contracts\BatchedRateSource;
use App\Services\RateHelper\Contracts\RateSource;
use App\Support\Money;

/**
 * What the review screen shows. Nothing on it comes from the stored shortlist except which rows to
 * look at: every description, unit, rate, date and name is read from the source row now, as the
 * person who is looking. A row that person cannot reach, or that has gone, is not shown at all.
 */
class RateReview
{
    public function __construct(private RateSources $sources) {}

    /**
     * @return array{
     *     sources: list<array{key:string,label:string,connected:bool,allowed:bool}>,
     *     lines: list<array<string, mixed>>,
     *     can_note: bool,
     *     stale_after_days: int,
     * }
     */
    public function for(RateSuggestionRun $run, User $viewer): array
    {
        $sources = $this->sources->open();
        $lines = $run->lines()->get();
        $items = BoqItem::query()->where('project_id', $run->project_id)->whereIn('id', $lines->pluck('boq_item_id'))->get()->keyBy('id');
        $staleAfter = (int) config('modules.rate_helper.stale_after_days', 180);

        $this->prime($sources, $viewer, $lines->all());

        return [
            'sources' => array_values(array_map(fn (RateSource $source) => [
                'key' => $source->key(),
                'label' => $source->label(),
                'connected' => $viewer->organization !== null && $source->isConnected($viewer->organization),
                'allowed' => $source->allows($viewer),
            ], $sources)),
            'lines' => $lines->map(fn (RateSuggestionLine $line) => $this->line($line, $items->get($line->boq_item_id), $sources, $viewer, $staleAfter))->all(),
            'can_note' => $this->canNote($viewer),
            'stale_after_days' => $staleAfter,
        ];
    }

    /** Noting a rate as today's current rate writes to the budget screen, which needs money.overview and the plan that has it. */
    public function canNote(User $viewer): bool
    {
        return Money::overview($viewer) && (bool) $viewer->organization?->allowsFeature('site_control');
    }

    /**
     * One candidate of a line as it stands now for this person, or null when it may not be shown:
     * its source is gone, the row is gone or out of reach, or its unit no longer matches the line.
     *
     * @param  array<string, RateSource>  $sources
     * @param  array<string, mixed>  $candidate  One entry of the stored shortlist.
     * @return array{ref:string,source:string,source_label:string,figure:RateFigure,score:?int,warnings:list<array<string, mixed>>,old:bool,same_work:?string,note:?string}|null
     */
    public function candidate(array $sources, User $viewer, BoqItem $item, array $candidate, int $staleAfter): ?array
    {
        $source = $sources[$candidate['source'] ?? ''] ?? null;

        if ($source === null || $viewer->organization === null || ! $source->isConnected($viewer->organization) || ! $source->allows($viewer)) {
            return null;
        }

        $figure = $source->rateOf($viewer, (int) ($candidate['row_id'] ?? 0));

        if ($figure === null || ! UnitGate::same($item->unit, $figure->unit)) {
            return null;
        }

        return [
            'ref' => (string) $candidate['ref'],
            'source' => $source->key(),
            'source_label' => $source->label(),
            'figure' => $figure,
            'score' => $candidate['score'] ?? null,
            // A reference figure is about the line itself, so there is no second description to compare.
            'warnings' => $figure->acceptable ? TextGuards::compare($item->description, $figure->description) : [],
            'old' => $figure->isOld($staleAfter),
            'same_work' => $candidate['same_work'] ?? null,
            'note' => $candidate['note'] ?? null,
        ];
    }

    /**
     * @param  array<string, RateSource>  $sources
     * @return array<string, mixed>
     */
    private function line(RateSuggestionLine $line, ?BoqItem $item, array $sources, User $viewer, int $staleAfter): array
    {
        $shown = [];

        if ($item !== null) {
            foreach ($line->candidates ?? [] as $candidate) {
                if ($entry = $this->candidate($sources, $viewer, $item, $candidate, $staleAfter)) {
                    $shown[] = $entry;
                }
            }
        }

        $accepted = $sources[$line->accepted_source ?? ''] ?? null;

        return [
            'line' => $line,
            'item' => $item,
            'lump_sum' => $item !== null && UnitGate::isLumpSum($item->unit),
            'candidates' => array_values(array_filter($shown, fn (array $entry) => $entry['figure']->acceptable)),
            'reference' => array_values(array_filter($shown, fn (array $entry) => ! $entry['figure']->acceptable)),
            'accepted_source_label' => $accepted?->label(),
        ];
    }

    /**
     * Reads every listed row of a source in one query where the source can.
     *
     * @param  array<string, RateSource>  $sources
     * @param  list<RateSuggestionLine>  $lines
     */
    private function prime(array $sources, User $viewer, array $lines): void
    {
        $wanted = [];

        foreach ($lines as $line) {
            foreach ($line->candidates ?? [] as $candidate) {
                $wanted[$candidate['source'] ?? ''][] = (int) ($candidate['row_id'] ?? 0);
            }
        }

        foreach ($wanted as $key => $rowIds) {
            if (($sources[$key] ?? null) instanceof BatchedRateSource) {
                $sources[$key]->prime($viewer, array_values(array_unique($rowIds)));
            }
        }
    }
}
