<?php

namespace Tests\Feature\SiteProgress;

use App\Models\Party;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\SiteControl\WorkProgress;
use App\Services\SiteProgress\ProgressEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

class ContractTaggingTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private $organization;

    private User $engineer;

    private Project $project;

    private $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->tenant();
        $this->engineer = $this->member($this->organization, 'engineer');
        $this->actingAs($this->engineer);
        $this->project = $this->makeProject($this->organization);
        $this->item = $this->makeBoqItem($this->project, '100.000', '500.00');
    }

    private function contract(string $status = 'ACTIVE', string $qty = '50'): WorkOrder
    {
        $party = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Contractor '.Str::random(4)]);
        $order = WorkOrder::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'party_id' => $party->id,
            'number' => 'WO-'.Str::random(5), 'title' => 'Flooring', 'status' => $status,
        ]);
        WorkOrderItem::create(['organization_id' => $this->organization->id, 'work_order_id' => $order->id, 'boq_item_id' => $this->item->id, 'qty' => $qty, 'rate' => '300.00']);

        return $order;
    }

    private function record(array $data): ProgressEntry
    {
        return app(ProgressEntryService::class)->record($this->project, ['boq_item_id' => $this->item->id, 'qty_done' => '10', ...$data], $this->engineer);
    }

    public function test_work_is_credited_to_the_only_active_contract(): void
    {
        $order = $this->contract();
        $this->contract('CLOSED');

        $this->assertSame($order->id, $this->record([])->work_order_id);
    }

    public function test_a_shared_line_needs_a_contractor_and_then_credits_the_right_one(): void
    {
        $first = $this->contract();
        $second = $this->contract();

        try {
            $this->record([]);
            $this->fail('A shared line must ask whose work it is.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order_id', $exception->errors());
        }

        $this->record(['work_order_id' => $first->id, 'qty_done' => '25']);

        $progress = app(WorkProgress::class);
        $this->assertSame('50.00', $progress->workOrderPercent($first));
        $this->assertSame('0.00', $progress->workOrderPercent($second));
    }

    public function test_a_contractor_not_on_the_line_is_refused(): void
    {
        $this->contract();
        $elsewhere = WorkOrder::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'party_id' => Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Other'])->id,
            'number' => 'WO-OTHER', 'title' => 'Paint', 'status' => 'ACTIVE',
        ]);

        $this->expectException(ValidationException::class);
        $this->record(['work_order_id' => $elsewhere->id]);
    }

    public function test_work_with_no_contract_stays_uncredited(): void
    {
        $this->assertNull($this->record([])->work_order_id);
    }

    public function test_a_pending_remark_with_no_quantity_does_not_need_a_contractor(): void
    {
        $this->contract();
        $this->contract();

        $entry = $this->record(['qty_done' => null, 'is_pending' => true, 'remark' => 'Outside passage wiring not done']);

        $this->assertNull($entry->work_order_id);
    }

    public function test_the_form_lists_contractors_only_for_shared_lines(): void
    {
        $this->withoutVite();
        $first = $this->contract();
        $second = $this->contract();

        $lines = app(ProgressEntryService::class)->sharedLines($this->project);

        $this->assertSame([$first->id, $second->id], array_column($lines[$this->item->id], 'id'));
        $this->get(route('site-progress.index', $this->project))->assertOk()->assertSee($first->party->name);
    }
}
