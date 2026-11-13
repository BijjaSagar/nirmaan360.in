<?php

namespace Tests\Feature\Procurement;

use App\Models\Indent;
use App\Services\Procurement\IndentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** An indent in the phone's approvals inbox: GET /approvals and POST /approvals/{id}/act. */
class IndentApprovalApiTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function waiting(): Indent
    {
        $item = $this->makeBoqItem($this->project);
        $indent = app(IndentService::class)->create($this->project, [
            'store_id' => $this->store->id,
            'required_by' => '2026-12-01',
            'remarks' => 'For the level 6 slab',
            'items' => [
                ['material_id' => $this->cement->id, 'qty' => '100', 'unit_id' => $this->bag->id, 'boq_item_id' => $item->id, 'remarks' => 'OPC only'],
                ['material_id' => $this->steel->id, 'qty' => '2.5', 'unit_id' => $this->mt->id],
            ],
        ], $this->member($this->organization, 'engineer'));
        app(IndentService::class)->submit($indent, $indent->creator);

        return $indent->refresh();
    }

    public function test_the_project_manager_sees_what_is_asked_for(): void
    {
        $indent = $this->waiting();
        Sanctum::actingAs($this->member($this->organization, 'pm'));

        $row = $this->getJson('/api/v1/approvals')->assertOk()->json('data.0');
        $doc = $row['document'];

        $this->assertSame('indent', $row['doc_type']);
        $this->assertSame($indent->number, $doc['number']);
        $this->assertSame('Main store', $doc['store']);
        $this->assertSame('2026-12-01', $doc['required_by']);
        $this->assertSame('For the level 6 slab', $doc['remarks']);
        $this->assertSame($indent->creator->name, $doc['created_by_name']);
        $this->assertCount(2, $doc['items']);
        $this->assertSame(['material' => 'OPC 53 cement', 'material_code' => 'CEM-53', 'qty' => '100.000', 'unit' => 'bag'], array_intersect_key($doc['items'][0], array_flip(['material', 'material_code', 'qty', 'unit'])));
        $this->assertSame('OPC only', $doc['items'][0]['remarks']);
        $this->assertSame('RCC M25 in slab', $doc['items'][0]['boq_item']['description']);
        $this->assertNull($doc['items'][1]['boq_item']);
        $this->assertSame('MT', $doc['items'][1]['unit']);
    }

    public function test_the_manager_sends_it_back_from_the_phone(): void
    {
        $indent = $this->waiting();
        Sanctum::actingAs($this->member($this->organization, 'pm'));

        $this->postJson('/api/v1/approvals/'.$indent->currentApproval->id.'/act', ['action' => 'return', 'remark' => 'Quantity looks high'])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED');

        $this->assertSame('RETURNED', $indent->fresh()->status);
    }

    public function test_another_company_never_sees_the_indent(): void
    {
        $approvalId = $this->waiting()->currentApproval->id;
        Sanctum::actingAs($this->member($this->tenant('Other Co'), 'owner'));

        $this->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/approvals/'.$approvalId.'/act', ['action' => 'approve'])->assertNotFound();
    }
}
