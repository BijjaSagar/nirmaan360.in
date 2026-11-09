<?php

namespace Tests\Feature\SiteControl;

use App\Models\Party;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\SiteControl\WorkProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\BuildsTenant;
use Tests\TestCase;

class WorkProgressTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->tenant();
        $this->actingAs($this->member($this->organization, 'owner'));
        $this->project = $this->makeProject($this->organization);
    }

    private function done($item, string $qty, array $extra = []): ProgressEntry
    {
        return ProgressEntry::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'boq_item_id' => $item->id, 'work_date' => '2026-11-10', 'qty_done' => $qty, ...$extra,
        ]);
    }

    private function contract($item, string $qty, string $rate, string $status = 'ACTIVE'): WorkOrder
    {
        $party = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Contractor '.uniqid()]);
        $order = WorkOrder::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'party_id' => $party->id,
            'number' => 'WO-'.uniqid(), 'title' => 'Flooring', 'status' => $status,
        ]);
        WorkOrderItem::create(['organization_id' => $this->organization->id, 'work_order_id' => $order->id, 'boq_item_id' => $item->id, 'qty' => $qty, 'rate' => $rate]);

        return $order;
    }

    public function test_item_percent_matches_the_flooring_example(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $this->done($item, '50');

        $this->assertSame('50.00', app(WorkProgress::class)->itemPercent($item));
    }

    public function test_a_correction_reduces_the_quantity(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $this->done($item, '60');
        $this->done($item, '-10');

        $this->assertSame('50.00', app(WorkProgress::class)->itemPercent($item));
    }

    public function test_floor_quantities_are_kept_apart(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $floor = ProjectFloor::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => '2nd floor']);
        $this->done($item, '30', ['project_floor_id' => $floor->id]);
        $this->done($item, '20');

        $this->assertSame('30.000', (string) app(WorkProgress::class)->completedQty($item->id, $floor->id));
        $this->assertSame('50.000', (string) app(WorkProgress::class)->completedQty($item->id));
    }

    public function test_project_percent_is_weighted_by_value_and_overruns_do_not_inflate_it(): void
    {
        $costly = $this->makeBoqItem($this->project, '10.000', '9000.00');
        $cheap = $this->makeBoqItem($this->project, '100.000', '100.00');
        $this->done($costly, '5');
        $this->done($cheap, '500');

        // (5 × 9000 + 100 × 100) ÷ (10 × 9000 + 100 × 100) = 55000 ÷ 100000
        $this->assertSame('55.00', app(WorkProgress::class)->projectPercent($this->project));
    }

    public function test_work_order_percent_uses_its_own_rates_and_tagged_work(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $first = $this->contract($item, '40', '300.00');
        $second = $this->contract($item, '60', '300.00');

        $this->done($item, '20', ['work_order_id' => $first->id]);
        $this->done($item, '10');

        $progress = app(WorkProgress::class);
        $this->assertSame('50.00', $progress->workOrderPercent($first));
        $this->assertSame('0.00', $progress->workOrderPercent($second));
    }

    public function test_untagged_work_counts_for_the_only_contract_on_the_line(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $order = $this->contract($item, '100', '300.00');
        $this->contract($item, '100', '300.00', 'CLOSED');
        $this->done($item, '70');

        $this->assertSame('70.00', app(WorkProgress::class)->workOrderPercent($order));
    }

    public function test_a_closed_contract_does_not_also_count_the_work_the_active_one_took_over(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $closed = $this->contract($item, '100', '300.00', 'CLOSED');
        $active = $this->contract($item, '100', '300.00');
        $this->done($item, '70');

        $progress = app(WorkProgress::class);

        $this->assertSame('70.00', $progress->workOrderPercent($active));
        $this->assertSame('0.00', $progress->workOrderPercent($closed));
        $this->assertSame('0.00', $progress->workOrderEarned($closed));
        $this->assertSame('0.00', $progress->workOrderLinePercents($closed)[$closed->items()->first()->id]);
    }

    public function test_a_closed_contract_alone_on_its_line_keeps_its_work(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $closed = $this->contract($item, '100', '300.00', 'CLOSED');
        $this->done($item, '100');

        $this->assertSame('100.00', app(WorkProgress::class)->workOrderPercent($closed));
    }

    public function test_time_used_and_lag_match_the_engineers_example(): void
    {
        $this->project->update(['start_date' => '2026-01-01', 'planned_days' => 180]);
        $item = $this->makeBoqItem($this->project, '100.000', '500.00');
        $this->done($item, '35');

        $today = Carbon::parse('2026-04-01');
        $progress = app(WorkProgress::class);

        $this->assertSame('50.00', $progress->timeUsedPercent($this->project->fresh(), $today));
        $this->assertSame('15.00', $progress->scheduleLag($this->project->fresh(), $today));
    }

    public function test_unknown_duration_gives_no_time_figure(): void
    {
        $this->project->update(['planned_days' => null]);

        $this->assertNull(app(WorkProgress::class)->timeUsedPercent($this->project->fresh()));
    }

    public function test_site_staff_do_not_get_money_permissions(): void
    {
        foreach (['engineer', 'store', 'viewer'] as $role) {
            $user = $this->member($this->organization, $role);
            $this->assertFalse($user->can('money.view'), $role);
            $this->assertFalse($user->can('money.overview'), $role);
        }

        $this->assertTrue($this->member($this->organization, 'pm')->can('money.overview'));
        $this->assertFalse($this->member($this->organization, 'accounts')->can('money.overview'));
    }
}
