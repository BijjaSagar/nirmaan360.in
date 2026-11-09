<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\DeliveryChallan;
use App\Models\Project;
use App\Models\User;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Support\Figure;
use Carbon\CarbonInterface;

/**
 * Material used against the BOQ, from material_control's three-way check: project wastage, the
 * materials with open flags, and challans still without a bill. Quantities only.
 */
class MaterialCard implements DashboardCard
{
    private const SHOWN = 5;

    public function __construct(private MaterialCheckService $check) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('material_control.view')) {
            return;
        }

        $result = $this->check->check($project);

        if ($result['rows'] === []) {
            return;
        }

        $flagged = array_values(array_filter($result['rows'], fn (array $row) => $row['open_flag_count'] > 0));

        $rows = array_map(fn (array $row) => [
            'label' => $row['material'],
            // The module's own wording for the first open gap.
            'sub' => collect($row['flags'])->firstWhere('open', true)['message'] ?? null,
            'value' => Figure::trim($row['used']).' / '.Figure::trim($row['estimate']).' '.$row['unit'],
            'tone' => Card::WARN,
        ], array_slice($flagged, 0, self::SHOWN));

        if ($viewer->can('material_control.bills')) {
            $bills = DeliveryChallan::where('project_id', $project->id)->where('status', 'POSTED')->where('bill_status', 'pending')->count();

            if ($bills > 0) {
                $rows[] = ['label' => __('Challans without bills'), 'value' => (string) $bills, 'href' => route('material_control.bills.index', $project), 'tone' => Card::WARN];
            }
        }

        $wastage = $result['wastage_pct'];

        yield new Card(
            key: 'material',
            title: __('Material against BOQ'),
            order: 55,
            value: $wastage === null ? '—' : Figure::trim($wastage).'%',
            note: $result['open_flags'] > 0
                ? __('Wastage so far, :flags open material flags to explain', ['flags' => $result['open_flags']])
                : __('Wastage so far, no open material flags'),
            tone: match (true) {
                $result['open_flags'] > 0 => Card::WARN,
                $wastage === null => null,
                default => Card::OK,
            },
            rows: $rows,
            href: route('material_control.index', $project),
            action: __('Material control'),
            wide: $rows !== [],
        );
    }
}
