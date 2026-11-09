<?php

namespace App\Services\Crm;

use App\Models\CrmTender;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** FR-CRM-03: how many tenders were won and lost in a financial year, and why. Sums use exact decimals. */
class WinLossReport
{
    /**
     * @return array{
     *   year:int, label:string, years:list<int>,
     *   counts:array{won:int,lost:int,no_bid:int},
     *   values:array{won:string,lost:string},
     *   win_rate_count:?string, win_rate_value:?string,
     *   price_gap:array{percent:?string,tenders:int},
     *   reasons:array<string,list<array{label:string,count:int,value:string,share:?string}>>,
     *   clients:list<array{name:string,won:int,lost:int,rate:?string}>,
     *   awaiting:array{count:int,value:string},
     *   tenders:Collection<int,CrmTender>
     * }
     */
    public function build(int $organizationId, int $startYear): array
    {
        [$from, $to] = FinancialYear::range($startYear);

        $closed = CrmTender::query()
            ->with(['reason', 'party'])
            ->where('organization_id', $organizationId)
            ->whereIn('stage', CrmTender::CLOSED_STAGES)
            ->whereDate('closed_on', '>=', $from)
            ->whereDate('closed_on', '<=', $to)
            ->orderByDesc('closed_on')
            ->orderByDesc('id')
            ->get();

        $byStage = $closed->groupBy('stage');
        $won = $byStage->get('won', collect());
        $lost = $byStage->get('lost', collect());

        $wonValue = $this->sum($won);
        $lostValue = $this->sum($lost);

        $awaiting = CrmTender::query()->where('organization_id', $organizationId)->where('stage', 'submitted')->get();

        return [
            'year' => $startYear,
            'label' => FinancialYear::label($startYear),
            'years' => $this->years($organizationId),
            'counts' => ['won' => $won->count(), 'lost' => $lost->count(), 'no_bid' => $byStage->get('no_bid', collect())->count()],
            'values' => ['won' => Dec::money($wonValue), 'lost' => Dec::money($lostValue)],
            'win_rate_count' => $this->percent($won->count(), $won->count() + $lost->count()),
            'win_rate_value' => $this->percent($wonValue, $wonValue->plus($lostValue)),
            'price_gap' => $this->priceGap($lost),
            'reasons' => [
                'won' => $this->byReason($won),
                'lost' => $this->byReason($lost),
                'no_bid' => $this->byReason($byStage->get('no_bid', collect())),
            ],
            'clients' => $this->byClient($closed),
            'awaiting' => ['count' => $awaiting->count(), 'value' => Dec::money($this->sum($awaiting))],
            'tenders' => $closed,
        ];
    }

    /** Financial years that have closed tenders, newest first, always including the current one. */
    public function years(int $organizationId): array
    {
        return CrmTender::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('closed_on')
            ->pluck('closed_on')
            ->map(fn ($date) => FinancialYear::startYearOf($date))
            ->push(FinancialYear::current())
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /** @param Collection<int,CrmTender> $tenders */
    private function sum(Collection $tenders): BigDecimal
    {
        return $tenders->reduce(fn ($sum, CrmTender $tender) => $sum->plus(Dec::of($tender->headlineValue())), Dec::of(0));
    }

    /** One decimal place, or null when there is nothing to divide by. */
    private function percent(mixed $part, mixed $whole): ?string
    {
        $whole = Dec::of($whole);

        if (! $whole->isPositive()) {
            return null;
        }

        return (string) Dec::of($part)->multipliedBy(100)->dividedBy($whole, 1, RoundingMode::HalfUp);
    }

    /**
     * How much dearer we were than the winner on lost tenders where both prices are known:
     * the average of (our price - winning price) / winning price.
     *
     * @param  Collection<int,CrmTender>  $lost
     * @return array{percent:?string,tenders:int}
     */
    private function priceGap(Collection $lost): array
    {
        $comparable = $lost->filter(fn (CrmTender $tender) => $tender->bid_value !== null
            && Dec::of($tender->bid_value)->isPositive()
            && $tender->winning_value !== null
            && Dec::of($tender->winning_value)->isPositive());

        if ($comparable->isEmpty()) {
            return ['percent' => null, 'tenders' => 0];
        }

        $total = $comparable->reduce(function ($sum, CrmTender $tender) {
            $winning = Dec::of($tender->winning_value);

            return $sum->plus(Dec::of($tender->bid_value)->minus($winning)->multipliedBy(100)->dividedBy($winning, 6, RoundingMode::HalfUp));
        }, Dec::of(0));

        return ['percent' => (string) $total->dividedBy($comparable->count(), 1, RoundingMode::HalfUp), 'tenders' => $comparable->count()];
    }

    /**
     * @param  Collection<int,CrmTender>  $tenders
     * @return list<array{label:string,count:int,value:string,share:?string}>
     */
    private function byReason(Collection $tenders): array
    {
        return $tenders
            ->groupBy(fn (CrmTender $tender) => $tender->reason?->label ?? '')
            ->map(fn (Collection $group, string $label) => [
                'label' => $label === '' ? __('No reason recorded') : $label,
                'count' => $group->count(),
                'value' => Dec::money($this->sum($group)),
                'share' => $this->percent($group->count(), $tenders->count()),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Won and lost by the authority that issued the tender, busiest first.
     *
     * @param  Collection<int,CrmTender>  $closed
     * @return list<array{name:string,won:int,lost:int,rate:?string}>
     */
    private function byClient(Collection $closed): array
    {
        return $closed
            ->whereIn('stage', ['won', 'lost'])
            ->groupBy(fn (CrmTender $tender) => mb_strtolower(trim($tender->authority)))
            ->map(function (Collection $group) {
                $won = $group->where('stage', 'won')->count();
                $lost = $group->where('stage', 'lost')->count();

                return ['name' => $group->first()->authority, 'won' => $won, 'lost' => $lost, 'rate' => $this->percent($won, $won + $lost)];
            })
            ->sortByDesc(fn (array $row) => $row['won'] + $row['lost'])
            ->values()
            ->take(10)
            ->all();
    }
}
