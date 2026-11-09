<?php

namespace Tests\Feature\Procurement;

use App\Models\BoqItem;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\User;
use App\Notifications\ProcurementAlert;
use App\Services\MeasurementService;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\MaterialReturnService;
use App\Services\Procurement\ReconciliationService;
use App\Services\Procurement\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    private BoqItem $boq;

    private Material $bagged;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->boq = $this->makeBoqItem($this->project);
        // Counted in bags, so the worked examples read naturally.
        $this->bagged = $this->makeMaterial($this->organization, 'CEM-B', 'Cement in bags', $this->bag);
    }

    private function norm(string $perUnit = '8.4', string $wastage = '2'): void
    {
        app(MaterialCatalogService::class)->saveNorm($this->project, [
            'boq_item_id' => $this->boq->id,
            'material_id' => $this->bagged->id,
            'qty_per_unit' => $perUnit,
            'wastage_pct' => $wastage,
        ]);
    }

    private function issueToBoq(string $qty): MaterialIssue
    {
        return $this->issue($this->bagged, $qty, ['boq_item_id' => $this->boq->id, 'activity' => null]);
    }

    private function row(string $basis = 'measured'): array
    {
        return app(ReconciliationService::class)->consumption($this->project, $basis)[0];
    }

    public function test_worked_example_wastage_over_the_allowance_is_flagged(): void
    {
        // 50 cum measured at 8.4 bags/cum: 420 bags theoretical, 428.4 allowed at 2%.
        // 450 bags issued is 30 over, which is 7.14% and above the 2% allowance.
        $this->draftEntry($this->project, $this->boq, $this->owner);
        $this->norm();
        $this->stockIn($this->bagged, '1000');
        $this->issueToBoq('450');

        $row = $this->row();

        $this->assertSame('50.000', $row['executed']);
        $this->assertSame('420.000', $row['theoretical']);
        $this->assertSame('428.400', $row['allowed']);
        $this->assertSame('450.000', $row['actual']);
        $this->assertSame('30.000', $row['variance']);
        $this->assertSame('7.14', $row['variance_pct']);
        $this->assertSame('wastage', $row['status']);
    }

    public function test_worked_example_within_the_allowance_is_fine(): void
    {
        // 428.4 bags is exactly at the allowed limit.
        $this->draftEntry($this->project, $this->boq, $this->owner);
        $this->norm();
        $this->stockIn($this->bagged, '1000');
        $this->issueToBoq('428.4');

        $this->assertSame('ok', $this->row()['status']);
    }

    public function test_using_less_than_the_norm_is_reported_as_under(): void
    {
        $this->draftEntry($this->project, $this->boq, $this->owner);
        $this->norm();
        $this->stockIn($this->bagged, '1000');
        $this->issueToBoq('400');

        $row = $this->row();
        $this->assertSame('under', $row['status']);
        $this->assertSame('-20.000', $row['variance']);
    }

    public function test_material_issued_before_any_work_is_not_called_wastage(): void
    {
        $this->norm();
        $this->stockIn($this->bagged, '1000');
        $this->issueToBoq('100');

        $row = $this->row();
        $this->assertSame('no_work', $row['status']);
        $this->assertNull($row['variance_pct']);
    }

    public function test_issue_with_no_norm_is_listed_so_someone_can_set_one(): void
    {
        $this->stockIn($this->bagged, '1000');
        $this->issueToBoq('10');

        $row = $this->row();
        $this->assertSame('no_norm', $row['status']);
        $this->assertSame('10.000', $row['actual']);
        $this->assertNull($row['theoretical']);
    }

    public function test_worked_example_returns_and_reversals_net_off_consumption(): void
    {
        // Issue 450, return 20, reverse an extra 5: net 430 against 428.4 allowed.
        $this->draftEntry($this->project, $this->boq, $this->owner);
        $this->norm();
        $this->stockIn($this->bagged, '1000');
        $first = $this->issueToBoq('450');
        $mistake = $this->issueToBoq('5');

        app(MaterialReturnService::class)->returnToStore($first, [
            'return_date' => '2026-11-04',
            'items' => [['line_id' => $first->items()->firstOrFail()->id, 'qty' => '20']],
        ], $this->owner);
        app(MaterialIssueService::class)->reverse($mistake, 'Duplicate slip', $this->owner);

        $row = $this->row();
        $this->assertSame('430.000', $row['actual']);
        $this->assertSame('wastage', $row['status']);
        $this->assertSame('570.000', $this->balanceOf($this->bagged));
    }

    public function test_the_work_basis_can_be_chosen(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $this->approveEntry($this->draftEntry($this->project, $this->boq, $engineer), $engineer, $this->owner);
        $this->draftEntry($this->project, $this->boq, $engineer, ['depth' => '0.1']);
        $this->norm('1', '0');

        // Approved: 50 cum. Measured adds a 10 cum draft: 60 cum.
        $this->assertSame('50.000', $this->row('approved')['executed']);
        $this->assertSame('60.000', $this->row('measured')['executed']);
    }

    public function test_activity_only_issues_are_listed_separately(): void
    {
        $this->stockIn($this->bagged, '100');
        $this->issue($this->bagged, '12', ['activity' => 'Site office']);

        $rows = app(ReconciliationService::class)->unallocated($this->project);

        $this->assertCount(1, $rows);
        $this->assertSame('12.000', $rows[0]['actual']);
        $this->assertSame([], app(ReconciliationService::class)->consumption($this->project));
    }

    public function test_worked_example_reorder_alert_fires_once_on_crossing(): void
    {
        Notification::fake();
        $store = $this->member($this->organization, 'store');
        $this->stockIn($this->cement, '100');
        app(MaterialCatalogService::class)->setReorderLevel($this->store, $this->cement, '50');

        // 100 -> 60 stays above 50: no alert.
        $this->issue($this->cement, '40');
        Notification::assertNothingSent();

        // 60 -> 40 crosses below 50: one alert.
        $this->issue($this->cement, '20');
        Notification::assertSentTo($store, ProcurementAlert::class);
        Notification::assertSentToTimes($store, ProcurementAlert::class, 1);

        // 40 -> 30 is already below: no new alert.
        $this->issue($this->cement, '10');
        Notification::assertSentToTimes($store, ProcurementAlert::class, 1);
    }

    public function test_reorder_list_shows_what_is_short(): void
    {
        $this->stockIn($this->cement, '30');
        app(MaterialCatalogService::class)->setReorderLevel($this->store, $this->cement, '50');
        app(MaterialCatalogService::class)->setReorderLevel($this->store, $this->steel, '10');

        $rows = app(ReconciliationService::class)->belowReorder($this->project, app(StockLedgerService::class)->projectBalances($this->project));

        $this->assertCount(2, $rows);
        $cement = $rows->firstWhere('material.id', $this->cement->id);
        $this->assertSame('30.000', $cement['balance']);
        $this->assertSame('20.000', $cement['shortfall']);
    }

    public function test_worked_example_wastage_alert_fires_once_when_the_limit_is_passed(): void
    {
        Notification::fake();
        $pm = $this->member($this->organization, 'pm');
        $this->draftEntry($this->project, $this->boq, $this->owner);
        $this->norm();
        $this->stockIn($this->bagged, '1000');

        // 420 is within the 428.4 allowed.
        $this->issueToBoq('420');
        Notification::assertNothingSent();

        // 440 passes it: one alert.
        $this->issueToBoq('20');
        Notification::assertSentToTimes($pm, ProcurementAlert::class, 1);

        // Still over: no repeat.
        $this->issueToBoq('5');
        Notification::assertSentToTimes($pm, ProcurementAlert::class, 1);
    }

    public function test_alerts_only_go_to_active_people_with_the_permission(): void
    {
        Notification::fake();
        $engineer = $this->member($this->organization, 'engineer');
        $inactive = User::factory()->create(['organization_id' => $this->organization->id, 'role' => 'store', 'is_active' => false]);
        $this->stockIn($this->cement, '100');
        app(MaterialCatalogService::class)->setReorderLevel($this->store, $this->cement, '50');

        $this->issue($this->cement, '60');

        Notification::assertNotSentTo($engineer, ProcurementAlert::class);
        Notification::assertNotSentTo($inactive, ProcurementAlert::class);
    }

    public function test_a_deduction_entry_reduces_the_work_done(): void
    {
        $this->draftEntry($this->project, $this->boq, $this->owner);
        app(MeasurementService::class)->create($this->project, [
            'boq_item_id' => $this->boq->id,
            'measured_on' => '2026-11-02',
            'nos' => '1',
            'length' => '10',
            'breadth' => '1',
            'depth' => '0.5',
            'is_deduction' => true,
        ], $this->owner);
        $this->norm('1', '0');

        // 50 cum less a 5 cum deduction.
        $this->assertSame('45.000', $this->row()['executed']);
    }
}
