<?php

namespace Tests\Feature\Workforce;

use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\DprWorkItem;
use App\Models\LabourAllocation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Workforce\ProductivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\BuildsWorkforce;
use Tests\TestCase;

class ProductivityTest extends TestCase
{
    use BuildsTenant, BuildsWorkforce, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function dpr(Project $project, string $date, string $status, BoqItem $item, string $qty): Dpr
    {
        $dpr = Dpr::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'report_date' => $date, 'status' => $status]);
        DprWorkItem::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'boq_item_id' => $item->id, 'qty' => $qty]);

        return $dpr;
    }

    /** @return object{organization:Organization, project:Project, user:User, slab:BoqItem, wall:BoqItem} */
    private function site(): object
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);

        return (object) [
            'organization' => $organization,
            'project' => $project,
            'user' => $this->member($organization, 'pm'),
            'slab' => $this->makeBoqItem($project),
            'wall' => $this->makeBoqItem($project),
        ];
    }

    public function test_productivity_is_work_done_divided_by_man_days_per_activity(): void
    {
        $site = $this->site();
        $service = app(ProductivityService::class);
        [$a, $b, $c] = [$this->makeWorker($site->organization, 'A'), $this->makeWorker($site->organization, 'B'), $this->makeWorker($site->organization, 'C')];

        // Slab: 30 cum approved on day 1, 10 cum in review on day 2, 100 cum in a draft that must not count.
        $this->dpr($site->project, '2026-11-02', 'APPROVED', $site->slab, '30.000');
        $this->dpr($site->project, '2026-11-03', 'IN_REVIEW', $site->slab, '10.000');
        $this->dpr($site->project, '2026-11-04', 'DRAFT', $site->slab, '100.000');
        // Wall: work reported but no labour allocated yet.
        $this->dpr($site->project, '2026-11-02', 'APPROVED', $site->wall, '12.500');

        // Attendance: A and B full days on 2 Nov, C a half day, and A again on 3 Nov: 3.5 man-days in all.
        $this->mark($site->project, $a, '2026-11-02');
        $this->mark($site->project, $b, '2026-11-02');
        $this->mark($site->project, $c, '2026-11-02', 'half');
        $this->mark($site->project, $a, '2026-11-03');

        $service->allocate($site->project, '2026-11-02', [
            ['worker_id' => $a->id, 'boq_item_id' => $site->slab->id, 'man_days' => '1'],
            ['worker_id' => $b->id, 'boq_item_id' => $site->slab->id, 'man_days' => '0.75'],
            ['worker_id' => $c->id, 'boq_item_id' => $site->slab->id, 'man_days' => '0.5'],
        ], $site->user);
        $service->allocate($site->project, '2026-11-03', [['worker_id' => $a->id, 'boq_item_id' => $site->slab->id, 'man_days' => '0.75']], $site->user);

        $report = $service->report($site->project, '2026-11-01', '2026-11-30');
        $slab = $report['rows']->firstWhere(fn ($row) => $row['item']->id === $site->slab->id);
        $wall = $report['rows']->firstWhere(fn ($row) => $row['item']->id === $site->wall->id);

        // 40 cum over 3 man-days, to three decimals: 13.333 cum per man-day.
        $this->assertSame('40.000', $slab['work_done']);
        $this->assertSame('3.000', $slab['man_days']);
        $this->assertSame('13.333', $slab['productivity']);

        $this->assertSame('12.500', $wall['work_done']);
        $this->assertSame('0.000', $wall['man_days']);
        $this->assertNull($wall['productivity']);

        $this->assertSame('3.500', $report['attendance_days']);
        $this->assertSame('3.000', $report['allocated_days']);
        $this->assertSame('0.500', $report['unallocated_days']);
    }

    public function test_the_period_limits_both_sides(): void
    {
        $site = $this->site();
        $service = app(ProductivityService::class);
        $worker = $this->makeWorker($site->organization);
        $this->mark($site->project, $worker, '2026-10-15');
        $this->mark($site->project, $worker, '2026-11-02');
        $service->allocate($site->project, '2026-10-15', [['worker_id' => $worker->id, 'boq_item_id' => $site->slab->id, 'man_days' => '1']], $site->user);
        $service->allocate($site->project, '2026-11-02', [['worker_id' => $worker->id, 'boq_item_id' => $site->slab->id, 'man_days' => '1']], $site->user);
        $this->dpr($site->project, '2026-10-15', 'APPROVED', $site->slab, '8.000');
        $this->dpr($site->project, '2026-11-02', 'APPROVED', $site->slab, '9.000');

        $row = $service->report($site->project, '2026-11-01', '2026-11-30')['rows']->first();

        $this->assertSame('9.000', $row['work_done']);
        $this->assertSame('1.000', $row['man_days']);
        $this->assertSame('9.000', $row['productivity']);
    }

    public function test_a_worker_cannot_give_an_activity_more_than_the_day_allows(): void
    {
        $site = $this->site();
        $service = app(ProductivityService::class);
        $half = $this->makeWorker($site->organization, 'Half Day Man');
        $this->mark($site->project, $half, '2026-11-02', 'half');

        $service->allocate($site->project, '2026-11-02', [['worker_id' => $half->id, 'boq_item_id' => $site->slab->id, 'man_days' => '0.25']], $site->user);

        try {
            $service->allocate($site->project, '2026-11-02', [
                ['worker_id' => $half->id, 'boq_item_id' => $site->wall->id, 'man_days' => '0.25'],
                ['worker_id' => $half->id, 'boq_item_id' => $site->wall->id, 'man_days' => '0.25'],
            ], $site->user);
            $this->fail('A half day cannot be given more than half a man-day.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Half Day Man', $exception->errors()['allocations'][0]);
        }

        // The failed batch saved nothing, not even its first row.
        $this->assertSame(1, LabourAllocation::withoutGlobalScopes()->count());
    }

    public function test_only_workers_marked_present_that_day_can_be_allocated(): void
    {
        $site = $this->site();
        $absent = $this->makeWorker($site->organization, 'Absent');
        $unmarked = $this->makeWorker($site->organization, 'Unmarked');
        $this->mark($site->project, $absent, '2026-11-02', 'absent');

        foreach ([$absent, $unmarked] as $worker) {
            try {
                app(ProductivityService::class)->allocate($site->project, '2026-11-02', [['worker_id' => $worker->id, 'boq_item_id' => $site->slab->id, 'man_days' => '1']], $site->user);
                $this->fail('Allocation without attendance must be refused.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, LabourAllocation::withoutGlobalScopes()->count());
    }

    public function test_allocating_over_http_checks_the_activity_belongs_to_this_project(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization);
        $this->mark($site->project, $worker, '2026-11-02');
        $otherProject = $this->makeProject($site->organization);
        $foreignItem = $this->makeBoqItem($otherProject);
        $rivalItem = $this->makeBoqItem($this->makeProject($this->tenant('Rival Co')));

        foreach ([$foreignItem, $rivalItem] as $item) {
            $this->actingAs($site->user)->post("/projects/{$site->project->id}/productivity/allocations", [
                'date' => '2026-11-02', 'allocations' => [$worker->id => ['boq_item_id' => $item->id, 'man_days' => '1']],
            ])->assertSessionHasErrors('allocations.'.$worker->id.'.boq_item_id');
        }

        $this->actingAs($site->user)->post("/projects/{$site->project->id}/productivity/allocations", [
            'date' => '2026-11-02', 'allocations' => [$worker->id => ['boq_item_id' => $site->slab->id, 'man_days' => '1']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, LabourAllocation::withoutGlobalScopes()->count());
    }

    public function test_the_screen_shows_the_report_and_respects_permission_and_tenant(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'Visible Vik');
        $this->mark($site->project, $worker, now()->toDateString());
        $this->dpr($site->project, now()->toDateString(), 'APPROVED', $site->slab, '6.000');
        app(ProductivityService::class)->allocate($site->project, now()->toDateString(), [['worker_id' => $worker->id, 'boq_item_id' => $site->slab->id, 'man_days' => '0.5']], $site->user);

        $this->actingAs($site->user)->get("/projects/{$site->project->id}/productivity")
            ->assertOk()->assertSee('Work done per man-day')->assertSee('12.000')->assertSee('Visible Vik');

        // Accounts has no attendance permission. Another company cannot even find the project.
        $this->actingAs($this->member($site->organization, 'accounts'))->get("/projects/{$site->project->id}/productivity")->assertForbidden();
        $this->actingAs($this->member($this->tenant('Rival Co'), 'owner'))->get("/projects/{$site->project->id}/productivity")->assertNotFound();
    }

    public function test_allocations_can_be_removed_but_not_across_companies(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization);
        $this->mark($site->project, $worker, '2026-11-02');
        $allocation = app(ProductivityService::class)->allocate($site->project, '2026-11-02', [['worker_id' => $worker->id, 'boq_item_id' => $site->slab->id, 'man_days' => '1']], $site->user)->first();

        $this->actingAs($this->member($this->tenant('Rival Co'), 'owner'))->delete("/labour-allocations/{$allocation->id}")->assertNotFound();
        $this->actingAs($this->member($site->organization, 'viewer'))->delete("/labour-allocations/{$allocation->id}")->assertForbidden();
        $this->assertSame(1, LabourAllocation::withoutGlobalScopes()->count());

        $this->actingAs($site->user)->delete("/labour-allocations/{$allocation->id}")->assertRedirect();
        $this->assertSame(0, LabourAllocation::withoutGlobalScopes()->count());
    }
}
