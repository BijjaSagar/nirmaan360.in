<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Services\SiteAlerts\TradeMatcher;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use App\Support\Figure;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * One card per focus trade, RCC and brick work by default, because that is where most money is
 * lost. Quantities only: done against BOQ for the biggest lines. Shown even when the BOQ has no
 * such line yet, so the space is always there.
 */
class TradeCards implements DashboardCard
{
    public function __construct(private TradeMatcher $matcher, private WorkProgress $progress) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        $order = 60;

        foreach (config('modules.site_alerts.focus_trades', []) as $key => $trade) {
            $items = $this->matcher->items($project, $trade);
            $label = __($trade['label']);
            $total = BigDecimal::zero();
            $earned = BigDecimal::zero();
            $lines = [];

            foreach ($items as $item) {
                $qty = Dec::of($item->qty);
                $rate = Dec::of($item->rate);
                $done = $this->progress->completedQty($item->id);
                $counted = $done->isNegative() ? BigDecimal::zero() : ($done->isGreaterThan($qty) ? $qty : $done);
                $total = $total->plus($qty->multipliedBy($rate));
                $earned = $earned->plus($counted->multipliedBy($rate));
                $lines[] = ['item' => $item, 'done' => $done, 'value' => $qty->multipliedBy($rate)];
            }

            usort($lines, fn (array $a, array $b) => $b['value']->compareTo($a['value']));
            $percent = $this->progress->percent($earned, $total);

            yield new Card(
                key: 'trade:'.$key,
                title: $label,
                order: $order++,
                value: $items->isEmpty() ? '—' : Figure::trim($percent).'%',
                note: $items->isEmpty()
                    ? __('No :trade lines in the BOQ yet.', ['trade' => $label])
                    : __(':count BOQ lines, done against BOQ quantity', ['count' => $items->count()]),
                tone: $items->isEmpty() ? null : Card::OK,
                meters: $items->isEmpty() ? [] : [['label' => __('Work done'), 'percent' => $percent, 'tone' => Card::OK]],
                rows: array_map(fn (array $line) => $this->row($line['item'], $line['done']), array_slice($lines, 0, (int) config('modules.site_alerts.trade_card_lines', 4))),
            );
        }
    }

    private function row(BoqItem $item, BigDecimal $done): array
    {
        $over = $done->isGreaterThan(Dec::of($item->qty));

        return [
            'label' => Str::limit($item->description, 60),
            'sub' => $item->code,
            'value' => Figure::trim(Dec::qty($done)).' / '.Figure::trim(Dec::qty($item->qty)).' '.$item->unit,
            'tone' => $over ? Card::BAD : null,
        ];
    }
}
