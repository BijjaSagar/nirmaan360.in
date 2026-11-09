<?php

namespace Tests\Feature\Procurement;

use App\Models\Grn;
use App\Models\MaterialIssue;
use App\Models\PurchaseOrder;
use App\Models\StockLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcurementApiTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    private const API = '/api/v1';

    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->order = $this->issuedOrder();
        Sanctum::actingAs($this->member($this->organization, 'store'));
    }

    private function grnBody(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'purchase_order_id' => $this->order->id,
            'grn_date' => '2026-11-03',
            'challan_no' => 'CH-1',
            'items' => [['purchase_order_item_id' => $this->order->items[0]->id, 'qty' => 60, 'unit_id' => $this->bag->id]],
            ...$overrides,
        ];
    }

    private function issueBody(string|int|float $qty = 40, array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'issue_date' => '2026-11-03',
            'items' => [['material_id' => $this->cement->id, 'qty' => $qty, 'activity' => 'Slab']],
            ...$overrides,
        ];
    }

    private function push(array ...$items): TestResponse
    {
        return $this->postJson(self::API.'/procurement/push', ['items' => $items])->assertOk();
    }

    private function outbox(string $type, array $data, array $overrides = []): array
    {
        $uuid = $data['client_uuid'] ?? (string) Str::uuid();
        unset($data['client_uuid']);

        return ['type' => $type, 'client_uuid' => $uuid, 'project_id' => $this->project->id, 'data' => $data, ...$overrides];
    }

    public function test_a_grn_posts_and_a_replay_returns_the_same_row(): void
    {
        $body = $this->grnBody();

        $created = $this->postJson(self::API."/projects/{$this->project->id}/grns", $body)->assertCreated()->json('data');
        $replay = $this->postJson(self::API."/projects/{$this->project->id}/grns", $body)->assertOk()->json('data');

        $this->assertSame($created['id'], $replay['id']);
        $this->assertSame($body['client_uuid'], $created['client_uuid']);
        $this->assertSame('3000.000', $this->balanceOf($this->cement));
        $this->assertSame(1, Grn::count());
    }

    public function test_a_grn_needs_a_client_uuid(): void
    {
        $this->postJson(self::API."/projects/{$this->project->id}/grns", $this->grnBody(['client_uuid' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_uuid');
    }

    public function test_a_replay_still_returns_the_original_after_the_po_has_filled(): void
    {
        $url = self::API."/projects/{$this->project->id}/grns";
        $first = $this->grnBody();
        $this->postJson($url, $first)->assertCreated();
        $this->postJson($url, $this->grnBody(['items' => [['purchase_order_item_id' => $this->order->items[0]->id, 'qty' => 40, 'unit_id' => $this->bag->id]]]))->assertCreated();
        $this->assertSame('RECEIVED', $this->order->fresh()->status);

        // The phone never saw its first response and tries again.
        $this->postJson($url, $first)->assertOk();
        $this->assertSame('5000.000', $this->balanceOf($this->cement));
    }

    public function test_a_grn_over_the_po_says_why(): void
    {
        $this->postJson(self::API."/projects/{$this->project->id}/grns", $this->grnBody(['items' => [['purchase_order_item_id' => $this->order->items[0]->id, 'qty' => 101, 'unit_id' => $this->bag->id]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('over_receipt');
    }

    public function test_an_issue_posts_and_a_replay_takes_stock_once(): void
    {
        $this->stockIn($this->cement, '100');
        $body = $this->issueBody();
        $url = self::API."/projects/{$this->project->id}/material-issues";

        $created = $this->postJson($url, $body)->assertCreated()->json('data');
        $replay = $this->postJson($url, $body)->assertOk()->json('data');

        $this->assertSame($created['id'], $replay['id']);
        $this->assertSame('60.000', $this->balanceOf($this->cement));
    }

    public function test_an_issue_over_stock_says_why(): void
    {
        $this->stockIn($this->cement, '10');

        $this->postJson(self::API."/projects/{$this->project->id}/material-issues", $this->issueBody(11))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        $this->assertSame('10.000', $this->balanceOf($this->cement));
    }

    public function test_a_uuid_from_another_project_is_refused(): void
    {
        $other = $this->makeProject($this->organization, ['name' => 'Site 2']);
        $this->stockIn($this->cement, '100');
        $body = $this->issueBody();
        $this->postJson(self::API."/projects/{$this->project->id}/material-issues", $body)->assertCreated();

        $this->postJson(self::API."/projects/{$other->id}/material-issues", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_uuid');
    }

    public function test_worked_example_offline_outbox_applies_in_order_and_reports_each_item(): void
    {
        // The device received 60 bags (3000 kg), then issued 2500 kg, then tried to issue
        // 600 kg more while offline. Only 500 kg is left, so that one comes back refused.
        $grn = $this->outbox('grn', $this->grnBody());
        $issue = $this->outbox('material_issue', $this->issueBody(2500));
        $tooMuch = $this->outbox('material_issue', $this->issueBody(600));

        $results = $this->push($grn, $issue, $tooMuch)->json('results');

        $this->assertSame('created', $results[0]['status']);
        $this->assertSame('POSTED', $results[0]['server_status']);
        $this->assertSame('created', $results[1]['status']);
        $this->assertSame('error', $results[2]['status']);
        $this->assertArrayHasKey('stock', $results[2]['errors']);
        $this->assertSame('500.000', $this->balanceOf($this->cement));
    }

    public function test_worked_example_replaying_a_whole_outbox_changes_nothing(): void
    {
        $grn = $this->outbox('grn', $this->grnBody());
        $issue = $this->outbox('material_issue', $this->issueBody(2500));

        $first = $this->push($grn, $issue)->json('results');
        $second = $this->push($grn, $issue)->json('results');

        $this->assertSame(['created', 'created'], array_column($first, 'status'));
        $this->assertSame(['duplicate', 'duplicate'], array_column($second, 'status'));
        $this->assertSame(array_column($first, 'id'), array_column($second, 'id'));
        $this->assertSame('500.000', $this->balanceOf($this->cement));
        $this->assertSame(2, StockLedgerEntry::count());
    }

    public function test_one_bad_item_does_not_stop_the_rest(): void
    {
        $this->stockIn($this->cement, '100');
        $results = $this->push(
            ['type' => 'nonsense', 'client_uuid' => (string) Str::uuid(), 'project_id' => $this->project->id, 'data' => []],
            $this->outbox('material_issue', $this->issueBody(10)),
        )->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('created', $results[1]['status']);
    }

    public function test_push_checks_permission_for_each_item(): void
    {
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $results = $this->push($this->outbox('material_issue', $this->issueBody(1)))->json('results');

        $this->assertSame('forbidden', $results[0]['status']);
        $this->assertSame('permission', $results[0]['reason']);
    }

    public function test_push_refuses_another_companys_project(): void
    {
        $other = $this->tenant('Other Co');
        Sanctum::actingAs($this->member($other, 'owner'));

        $results = $this->push($this->outbox('material_issue', $this->issueBody(1)))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertArrayHasKey('project_id', $results[0]['errors']);
        $this->assertSame(0, MaterialIssue::withoutGlobalScopes()->count());
    }

    public function test_snapshot_gives_the_device_what_it_needs_offline(): void
    {
        $this->stockIn($this->cement, '75');

        $json = $this->getJson(self::API."/projects/{$this->project->id}/procurement/snapshot")->assertOk()->json();

        $this->assertSame($this->store->id, $json['stores'][0]['id']);
        $this->assertCount(2, $json['materials']);
        $this->assertSame('75.000', $json['stock'][0]['balance']);
        $this->assertSame($this->order->id, $json['purchase_orders'][0]['id']);
        $this->assertSame('5000.000', $json['purchase_orders'][0]['items'][0]['remaining_base_qty']);
    }

    public function test_viewer_can_read_but_not_post(): void
    {
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->getJson(self::API."/projects/{$this->project->id}/grns")->assertOk();
        $this->postJson(self::API."/projects/{$this->project->id}/grns", $this->grnBody())->assertForbidden();
        $this->postJson(self::API."/projects/{$this->project->id}/material-issues", $this->issueBody())->assertForbidden();
    }

    public function test_a_plan_without_the_module_is_refused(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $project = $this->makeProject($free);
        Sanctum::actingAs($this->member($free, 'owner'));

        $this->getJson(self::API."/projects/{$project->id}/procurement/snapshot")->assertForbidden();
        $this->postJson(self::API."/projects/{$project->id}/grns", $this->grnBody())->assertForbidden();
    }

    public function test_another_companys_project_is_not_found(): void
    {
        Sanctum::actingAs($this->member($this->tenant('Other Co'), 'owner'));

        $this->getJson(self::API."/projects/{$this->project->id}/grns")->assertNotFound();
        $this->postJson(self::API."/projects/{$this->project->id}/grns", $this->grnBody())->assertNotFound();
        $this->postJson(self::API."/projects/{$this->project->id}/material-issues", $this->issueBody())->assertNotFound();
    }

    public function test_foreign_ids_from_another_company_are_refused(): void
    {
        $other = $this->tenant('Other Co');
        $theirUnit = $this->makeUnit($other, 'kg');
        $theirMaterial = $this->makeMaterial($other, 'X', 'Their cement', $theirUnit);
        $this->stockIn($this->cement, '10');

        $this->postJson(self::API."/projects/{$this->project->id}/material-issues", $this->issueBody(1, [
            'items' => [['material_id' => $theirMaterial->id, 'qty' => 1, 'activity' => 'Slab']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.material_id');
    }

    public function test_a_store_from_another_project_is_refused(): void
    {
        $otherStore = $this->makeStore($this->makeProject($this->organization, ['name' => 'Site 2']), 'Other');

        $this->postJson(self::API."/projects/{$this->project->id}/material-issues", $this->issueBody(1, ['store_id' => $otherStore->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');
    }
}
