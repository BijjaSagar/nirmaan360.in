<?php

namespace Tests\Feature\FieldOps;

use App\Models\ChecklistTemplate;
use App\Models\Ncr;
use App\Services\Assets\HireBillService;
use App\Services\Assets\MaintenanceService;
use App\Services\Assets\PlantLogService;
use App\Services\Qhs\ChecklistService;
use App\Services\Qhs\NcrService;
use App\Services\Qhs\QualityTestService;
use App\Services\Qhs\RfiService;
use App\Services\Qhs\SafetyService;
use Illuminate\Support\Facades\Auth;

/** Every screen of the module opens with realistic data, for the owner and for a read-only viewer. */
class EveryPageRendersTest extends FieldOpsTestCase
{
    public function test_every_screen_renders(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $project = $w->project;
        $vendor = $this->vendor($w->organization);

        $asset = $this->hiredAsset($w->owner, ['vendor_id' => $vendor->id, 'project_id' => $project->id, 'hire_basis' => 'hour', 'min_hours_per_month' => '100', 'hire_type' => 'wet']);
        $logs = app(PlantLogService::class);
        $logs->record(['asset_id' => $asset->id, 'log_date' => today()->subDays(3)->toDateString(), 'hours' => 8], $w->owner);
        $logs->issueFuel(['asset_id' => $asset->id, 'issue_date' => today()->subDays(3)->toDateString(), 'litres' => 40, 'rate' => 95], $w->owner);
        $breakdown = $logs->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => now()->subDay()->format('Y-m-d\TH:i'), 'description' => 'Hydraulic hose burst'], $w->owner);
        $logs->resolveBreakdown($breakdown, ['resolved_at' => now()->format('Y-m-d\TH:i'), 'repair_cost' => 3500]);
        $item = app(MaintenanceService::class)->add(['asset_id' => $asset->id, 'title' => 'Engine oil', 'interval_usage' => 250, 'interval_days' => 90], $w->owner);
        app(MaintenanceService::class)->markDone($item, ['done_on' => today()->toDateString(), 'cost' => 4200], $w->owner);
        $bill = app(HireBillService::class)->generate(['asset_id' => $asset->id, 'period_from' => today()->subDays(10)->toDateString(), 'period_to' => today()->toDateString(), 'gst_pct' => 18, 'tds_pct' => 2], $w->owner);

        $ncr = app(NcrService::class)->create($project, ['title' => 'Honeycombing at C7', 'category' => 'workmanship', 'severity' => 'major', 'rework_cost' => 15000], $w->owner);
        $test = app(QualityTestService::class)->create($project, ['test_type' => 'cube', 'element' => 'Slab L3', 'grade' => 'M25', 'cast_date' => today()->subDays(10)->toDateString()], $w->owner);
        $incident = app(SafetyService::class)->report($project, ['kind' => 'incident', 'severity' => 'medium', 'injury' => 'lost_time', 'lost_days' => 2, 'occurred_at' => now()->subHour()->format('Y-m-d\TH:i'), 'description' => 'Fell from ladder'], $w->owner);
        app(SafetyService::class)->holdToolboxTalk($project, ['talk_date' => today()->toDateString(), 'topic' => 'Working at height', 'conducted_by' => 'Ravi', 'attendees' => "Anil\nSunil"], $w->owner);
        app(ChecklistService::class)->installStandard($w->owner);
        $template = ChecklistTemplate::where('name', 'Pour card')->first();
        $inspection = app(ChecklistService::class)->start($project, ['checklist_template_id' => $template->id, 'location' => 'Slab L3'], $w->owner);
        $rfi = app(RfiService::class)->create($project, ['subject' => 'Beam depth at gridline B', 'question' => 'Drawing S-12 shows 450, S-14 shows 500.', 'priority' => 'high'], $w->owner);

        $urls = [
            route('assets.index'), route('assets.create'), route('assets.show', $asset), route('assets.show', [$asset, 'tab' => 'fuel']), route('assets.edit', $asset),
            route('assets.tag', $asset), route('assets.tags'), route('machine-logs.index'), route('asset-maintenance.index'),
            route('hire-bills.index'), route('hire-bills.create'), route('hire-bills.show', $bill), route('checklists.index'),
            route('ncrs.index', $project), route('ncrs.show', $ncr),
            route('quality-tests.index', $project), route('quality-tests.show', $test),
            route('incidents.index', $project), route('incidents.show', $incident), route('incidents.toolbox.index', $project),
            route('inspections.index', $project), route('inspections.show', $inspection),
            route('rfis.index', $project), route('rfis.create', $project), route('rfis.show', $rfi), route('rfis.edit', $rfi),
            route('dashboard'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->post(route('hire-bills.preview'), ['asset_id' => $asset->id, 'period_from' => today()->subDays(10)->toDateString(), 'period_to' => today()->subDays(5)->toDateString()])->assertOk()->assertSee('Net payable');
    }

    public function test_screens_open_for_a_viewer_without_edit_controls(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner);
        $ncr = app(NcrService::class)->create($w->project, ['title' => 'Cracks', 'category' => 'material', 'severity' => 'minor'], $w->owner);
        $viewer = $this->member($w->organization, 'viewer');

        Auth::forgetUser();
        $this->actingAs($viewer);

        $this->get(route('assets.index'))->assertOk()->assertDontSee('Add asset');
        $this->get(route('assets.show', $asset))->assertOk()->assertDontSee('Save log');
        $this->get(route('ncrs.show', $ncr))->assertOk()->assertDontSee('Mark resolved');
        $this->assertSame(1, Ncr::count());
    }
}
