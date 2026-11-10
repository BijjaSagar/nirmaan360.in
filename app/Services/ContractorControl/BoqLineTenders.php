<?php

namespace App\Services\ContractorControl;

use App\Models\BoqItem;
use App\Models\ContractorBid;
use App\Models\ContractorBidItem;
use App\Models\ContractorTender;
use App\Models\ContractorTenderItem;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * The tenders a BOQ line has gone out in, and what each contractor quoted for that line.
 * Everything here carries prices: ContractorControlPresenter decides what a reader receives.
 */
class BoqLineTenders
{
    public function __construct(private WorkProgress $progress) {}

    /** Tenders belong to the contractor module, which not every plan or role has. */
    public function visibleTo(User $user): bool
    {
        return $user->can('contractor.view') && $user->organization->allowsFeature('site_control');
    }

    /**
     * What the BOQ table shows beside each line. A cancelled tender is left out, and of a
     * contractor's quotes only the latest counts.
     *
     * @return array<int, array{tenders: int, quotes: int, lowest_rate: ?string}> BOQ item id => marker
     */
    public function markers(Project $project): array
    {
        $tenders = ContractorTender::with(['items', 'bids' => fn ($bids) => $bids->whereNull('superseded_by_id'), 'bids.items'])
            ->where('project_id', $project->id)
            ->where('status', '!=', 'CANCELLED')
            ->get();

        $markers = [];

        foreach ($tenders as $tender) {
            foreach ($tender->items as $line) {
                $marker = $markers[$line->boq_item_id] ?? ['tenders' => 0, 'quotes' => 0, 'lowest_rate' => null];
                $marker['tenders']++;

                foreach ($tender->bids as $bid) {
                    $quoted = $this->quotedFor($bid, $line);

                    if ($quoted !== null) {
                        $marker['quotes']++;
                        $marker['lowest_rate'] = $this->lower($marker['lowest_rate'], $quoted->rate);
                    }
                }

                $markers[$line->boq_item_id] = $marker;
            }
        }

        return $markers;
    }

    /**
     * Every tender that holds this line, newest first, with each contractor's rate for the line
     * set against the BOQ rate the tender was made at.
     *
     * @return array{item: BoqItem, summary: array{tenders: int, open: int, quotes: int, lowest_rate: ?string, lowest_contractor: ?string}, tenders: list<array<string, mixed>>}
     */
    public function forLine(BoqItem $item): array
    {
        $tenders = ContractorTender::with(['workOrder', 'items.boqItem', 'bids.party', 'bids.items', 'bids.enteredBy'])
            ->where('project_id', $item->project_id)
            ->whereHas('items', fn ($lines) => $lines->where('boq_item_id', $item->id))
            ->latest('id')
            ->get();

        $rows = $tenders->map(fn (ContractorTender $tender) => $this->tenderRow($tender, $item))->all();

        return ['item' => $item, 'summary' => $this->summary($rows), 'tenders' => $rows];
    }

    /** @return array<string, mixed> */
    private function tenderRow(ContractorTender $tender, BoqItem $item): array
    {
        /** @var ContractorTenderItem $line */
        $line = $tender->items->firstWhere('boq_item_id', $item->id);
        $lowest = null;
        $quoted = [];

        foreach ($tender->bids as $bid) {
            $bidLine = $this->quotedFor($bid, $line);

            if ($bidLine === null) {
                continue;
            }

            $quoted[] = [$bid, $bidLine];

            if ($bid->isCurrent()) {
                $lowest = $this->lower($lowest, $bidLine->rate);
            }
        }

        $quotes = array_map(fn (array $pair) => $this->quoteRow($pair[0], $pair[1], $line, $lowest), $quoted);

        // The current quotes cheapest first, then the ones a newer quote replaced.
        usort($quotes, function (array $a, array $b) {
            if ($a['current'] !== $b['current']) {
                return $a['current'] ? -1 : 1;
            }

            if (! $a['current']) {
                return $b['bid']->id <=> $a['bid']->id;
            }

            return Dec::of($a['rate'])->compareTo(Dec::of($b['rate'])) ?: $a['bid']->id <=> $b['bid']->id;
        });

        // The quantity and BOQ rate are the ones copied when the tender was made, as on the tender's own page.
        return [
            'tender' => $tender,
            'qty' => Dec::qty($line->qty),
            'boq_rate' => Dec::money($line->boq_rate),
            'boq_amount' => $line->amount(),
            'lowest_rate' => $lowest,
            'quotes' => $quotes,
        ];
    }

    /** @return array<string, mixed> */
    private function quoteRow(ContractorBid $bid, ContractorBidItem $quoted, ContractorTenderItem $line, ?string $lowest): array
    {
        $boqRate = Dec::of($line->boq_rate);
        $rate = Dec::of($quoted->rate);
        $difference = $rate->minus($boqRate);

        return [
            'bid' => $bid,
            'contractor' => (string) $bid->party?->name,
            'rate' => Dec::money($rate),
            'amount' => Dec::money($quoted->amount),
            // Per unit, against the BOQ rate, and what that comes to over the line's quantity.
            'diff' => Dec::money($difference),
            'diff_amount' => Dec::money(Dec::of($quoted->amount)->minus(Dec::of($line->amount()))),
            'diff_pct' => $boqRate->isZero() ? null : $this->progress->percent($difference, $boqRate),
            'lowest' => $bid->isCurrent() && $lowest !== null && $rate->isEqualTo(Dec::of($lowest)),
            'selected' => (bool) $bid->is_selected,
            'current' => $bid->isCurrent(),
        ];
    }

    /**
     * The same counting as the marker on the BOQ table, so the two always agree.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{tenders: int, open: int, quotes: int, lowest_rate: ?string, lowest_contractor: ?string}
     */
    private function summary(array $rows): array
    {
        $summary = ['tenders' => 0, 'open' => 0, 'quotes' => 0, 'lowest_rate' => null, 'lowest_contractor' => null];

        foreach ($rows as $row) {
            if ($row['tender']->status === 'CANCELLED') {
                continue;
            }

            $summary['tenders']++;
            $summary['open'] += $row['tender']->isOpen() ? 1 : 0;

            foreach ($row['quotes'] as $quote) {
                if (! $quote['current']) {
                    continue;
                }

                $summary['quotes']++;

                if ($summary['lowest_rate'] === null || Dec::of($quote['rate'])->isLessThan(Dec::of($summary['lowest_rate']))) {
                    $summary['lowest_rate'] = $quote['rate'];
                    $summary['lowest_contractor'] = $quote['contractor'];
                }
            }
        }

        return $summary;
    }

    /** The rate a bid gives for one line of its tender. */
    private function quotedFor(ContractorBid $bid, ContractorTenderItem $line): ?ContractorBidItem
    {
        return $bid->items->firstWhere('contractor_tender_item_id', $line->id);
    }

    private function lower(?string $lowest, mixed $rate): string
    {
        $rate = Dec::of($rate);

        return Dec::money($lowest === null || $rate->isLessThan(BigDecimal::of($lowest)) ? $rate : $lowest);
    }
}
