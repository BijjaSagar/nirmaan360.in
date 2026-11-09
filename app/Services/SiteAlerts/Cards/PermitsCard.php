<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSetup\PermitService;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use Carbon\CarbonInterface;

/**
 * The permission checklist from project_setup: how many approvals are in, and the fees as a head
 * of their own for people with money.overview. Left out until the project lists a permission.
 */
class PermitsCard implements DashboardCard
{
    public function __construct(private PermitService $permits) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('permit.view')) {
            return;
        }

        $counts = $this->permits->counts($project);

        if (($counts['total'] ?? 0) === 0) {
            return;
        }

        $rows = [];
        $money = $viewer->can('money.overview');

        if ($money) {
            $rows[] = ['label' => __('Permission fees'), 'value' => inr($this->permits->totalFee($project))];
        }

        $open = ($counts['applied'] ?? 0) + ($counts['not_started'] ?? 0);

        yield new Card(
            key: 'permits',
            title: __('Permissions'),
            order: 45,
            value: __(':approved of :total approved', ['approved' => $counts['approved'] ?? 0, 'total' => $counts['total']]),
            note: __(':applied applied, :waiting not started, :rejected rejected', ['applied' => $counts['applied'] ?? 0, 'waiting' => $counts['not_started'] ?? 0, 'rejected' => $counts['rejected'] ?? 0]),
            tone: match (true) {
                ($counts['rejected'] ?? 0) > 0 => Card::BAD,
                $open > 0 => Card::WARN,
                default => Card::OK,
            },
            rows: $rows,
            href: route('project-setup.permits.index', $project),
            action: __('Permissions'),
            money: $money,
        );
    }
}
