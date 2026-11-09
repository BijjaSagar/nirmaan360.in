<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Project;
use App\Models\User;
use App\Services\ContractorControl\WorkPaymentRatio;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Support\Figure;
use Carbon\CarbonInterface;

/**
 * Work against payment per contract, from contractor_control. Paid % and the gap are money and
 * show only with money.view; everyone else with contractor.view sees each contract's work %.
 */
class ContractorCard implements DashboardCard
{
    private const SHOWN = 5;

    public function __construct(private WorkPaymentRatio $ratio) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('contractor.view')) {
            return;
        }

        $rows = $this->ratio->forProject($project);

        if ($rows->isEmpty()) {
            return;
        }

        $money = $viewer->can('money.view');
        $ahead = $rows->where('status', 'red')->count();
        $over = $rows->where('over_limit', true)->count();

        yield new Card(
            key: 'contractors',
            title: __('Work against payment'),
            order: 35,
            value: $money ? (string) $ahead : (string) $rows->count(),
            note: $money
                ? __(':ahead of :total contracts paid ahead of work, :over over the limit', ['ahead' => $ahead, 'total' => $rows->count(), 'over' => $over])
                : __(':total contracts, work done against each', ['total' => $rows->count()]),
            tone: match (true) {
                ! $money => null,
                $over > 0 => Card::BAD,
                $ahead > 0 => Card::WARN,
                default => Card::OK,
            },
            rows: $rows->take(self::SHOWN)->map(fn (array $row) => [
                'label' => $row['party']?->name ?? '—',
                'sub' => $row['work_order']->number.' · '.$row['work_order']->title,
                // The module's own "Paid 90%, Work 70%" wording.
                'value' => $money ? $row['label'] : Figure::trim($row['work_pct']).'%',
                'href' => route('contractor-control.contracts.show', $row['work_order']),
                'tone' => $money ? ($row['over_limit'] ? Card::BAD : ($row['status'] === 'red' ? Card::WARN : Card::OK)) : null,
            ])->values()->all(),
            href: route('contractor-control.board', $project),
            action: __('Work vs payment'),
            wide: true,
            money: $money,
        );
    }
}
