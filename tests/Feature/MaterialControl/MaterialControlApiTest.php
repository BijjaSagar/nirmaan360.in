<?php

namespace Tests\Feature\MaterialControl;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialStockCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaterialControlApiTest extends TestCase
{
    use BuildsMaterialControl, RefreshDatabase;

    private const API = '/api/v1';

    private Material $bags;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->setUpControl();
        $this->bags = $this->bulk('CEM', 'Cement', 'bag');
    }

    private function signIn(string $role): User
    {
        $user = $this->member($this->organization, $role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function challanUrl(): string
    {
        return self::API.'/projects/'.$this->project->id.'/material-control/challans';
    }

    public function test_a_supervisor_posts_a_challan_with_a_photo_and_a_retry_changes_nothing(): void
    {
        $this->signIn('engineer');
        $uuid = (string) Str::uuid();
        $payload = [
            'client_uuid' => $uuid, 'supplier_name' => 'Gupta Traders', 'material_id' => $this->bags->id, 'qty' => '40',
            'challan_date' => today()->toDateString(), 'vehicle_no' => 'MH12AB1234', 'photo' => UploadedFile::fake()->image('c.jpg'),
        ];

        $first = $this->post($this->challanUrl(), $payload, ['Accept' => 'application/json']);
        $first->assertCreated()->assertJsonPath('data.client_uuid', $uuid)->assertJsonPath('data.has_photo', true)->assertJsonPath('data.bill_status', 'pending');

        $photoPath = DeliveryChallan::where('client_uuid', $uuid)->value('photo_path');

        // A retry may not resend the file, and must not fail or post again.
        $repeat = $this->postJson($this->challanUrl(), ['client_uuid' => $uuid]);
        $repeat->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, DeliveryChallan::where('client_uuid', $uuid)->count());
        $this->assertSame($photoPath, DeliveryChallan::where('client_uuid', $uuid)->value('photo_path'));
        $this->assertSame('40.000', $this->balanceOf($this->bags));
    }

    public function test_a_challan_needs_a_client_uuid(): void
    {
        $this->signIn('store');

        $this->postJson($this->challanUrl(), ['supplier_name' => 'Gupta', 'material_id' => $this->bags->id, 'qty' => '1', 'challan_date' => today()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');
    }

    public function test_choosing_a_marked_supplier_returns_a_warning(): void
    {
        $this->signIn('store');
        $supplier = $this->makeVendor('Patil Sand Supply');
        $this->actingAs($this->owner);
        $this->deliver($this->bags, '3', $supplier, ['challan_qty' => '4']);
        $this->deliver($this->bags, '3', $supplier, ['challan_qty' => '4']);

        $this->signIn('store');

        $this->postJson($this->challanUrl(), [
            'client_uuid' => (string) Str::uuid(), 'party_id' => $supplier->id, 'material_id' => $this->bags->id, 'qty' => '1', 'challan_date' => today()->toDateString(),
        ])->assertCreated()->assertJsonPath('supplier_warning', fn ($warning) => str_contains((string) $warning, 'short 2 times'));

        $this->getJson(self::API.'/material-control/suppliers')->assertOk()->assertJsonPath('data.0.warn', true);
    }

    public function test_site_staff_never_receive_bill_details_or_money(): void
    {
        $item = $this->boqFor($this->bags, '100', '1');
        $this->workDone($item, '100');
        $this->deliver($this->bags, '105');
        $this->useOn($item, $this->bags, '105');
        $challan = DeliveryChallan::where('project_id', $this->project->id)->firstOrFail();
        $challan->update(['bill_status' => 'attached', 'bill_no' => 'INV-55', 'bill_amount' => '123456.00']);

        foreach (['engineer', 'store'] as $role) {
            $this->signIn($role);

            $challans = $this->getJson($this->challanUrl())->assertOk();
            $challans->assertJsonPath('data.0.bill_status', 'attached')->assertJsonMissingPath('data.0.bill_amount')->assertJsonMissingPath('data.0.bill_no')->assertDontSee('123456');

            $check = $this->getJson(self::API.'/projects/'.$this->project->id.'/material-control/check')->assertOk();
            $check->assertJsonMissingPath('data.rows.0.rate')->assertJsonMissingPath('data.rows.0.wastage_value');
        }

        $this->signIn('accounts');
        $this->getJson($this->challanUrl())->assertOk()->assertJsonPath('data.0.bill_amount', '123456.00');
        $this->getJson(self::API.'/projects/'.$this->project->id.'/material-control/check')->assertOk()->assertJsonPath('data.rows.0.material_id', $this->bags->id);
    }

    public function test_consumption_posts_with_client_uuid_and_a_retry_is_safe(): void
    {
        $this->deliver($this->bags, '50');
        $item = $this->makeBoqItem($this->project);
        $this->signIn('engineer');
        $uuid = (string) Str::uuid();
        $url = self::API.'/projects/'.$this->project->id.'/material-control/consumption';
        $payload = ['client_uuid' => $uuid, 'material_id' => $this->bags->id, 'qty' => '20', 'boq_item_id' => $item->id, 'stage' => 'Slab 1', 'used_on' => today()->toDateString()];

        $first = $this->postJson($url, $payload);
        $first->assertCreated()->assertJsonPath('data.items.0.boq_item_id', $item->id)->assertJsonPath('data.items.0.activity', 'Slab 1');

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, MaterialIssue::where('client_uuid', $uuid)->count());
        $this->assertSame('30.000', $this->balanceOf($this->bags));

        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $first->json('data.id'));
    }

    public function test_consumption_over_stock_is_refused_with_the_reason(): void
    {
        $this->deliver($this->bags, '10');
        $item = $this->makeBoqItem($this->project);
        $this->signIn('store');

        $this->postJson(self::API.'/projects/'.$this->project->id.'/material-control/consumption', [
            'client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'qty' => '11', 'boq_item_id' => $item->id, 'used_on' => today()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('stock');
    }

    public function test_a_stock_check_posts_with_client_uuid_and_a_retry_is_safe(): void
    {
        $this->deliver($this->bags, '50');
        $this->signIn('store');
        $uuid = (string) Str::uuid();
        $url = self::API.'/projects/'.$this->project->id.'/material-control/stock-checks';
        $payload = ['client_uuid' => $uuid, 'material_id' => $this->bags->id, 'physical_qty' => '45', 'check_date' => today()->toDateString()];

        $first = $this->postJson($url, $payload);
        $first->assertCreated()->assertJsonPath('data.variance_qty', '-5.000')->assertJsonPath('data.book_qty', '50.000');

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, MaterialStockCheck::where('client_uuid', $uuid)->count());
        $this->assertSame('45.000', $this->balanceOf($this->bags));
    }

    public function test_a_viewer_cannot_post_and_another_company_cannot_reach_the_project(): void
    {
        $this->signIn('viewer');
        $this->postJson($this->challanUrl(), ['client_uuid' => (string) Str::uuid()])->assertForbidden();

        Sanctum::actingAs($this->member($this->tenant('Other Co'), 'owner'));
        $this->getJson($this->challanUrl())->assertNotFound();
    }
}
