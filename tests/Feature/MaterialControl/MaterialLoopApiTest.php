<?php

namespace Tests\Feature\MaterialControl;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialFlagReason;
use App\Models\MaterialShortDelivery;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\MaterialCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The field app closing the material loop: a written reason on a flag, a challan in full, the supplier's
 * bill attached from site, a short delivery reported later, and the accountant's bills-pending list.
 */
class MaterialLoopApiTest extends TestCase
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

    private function signIn(string $role, ?User $user = null): User
    {
        $user ??= $this->member($this->organization, $role);
        Sanctum::actingAs($user);

        return $user;
    }

    /** Someone who may only open the projects given. */
    private function limitedTo(string $role, Project ...$projects): User
    {
        $user = $this->member($this->organization, $role);
        app(TeamAccessService::class)->applyProjects($user, true, array_map(fn (Project $project) => $project->id, $projects));

        return $user->refresh();
    }

    /** Over used by 5 bags: BOQ 100 for the work done, 105 used. */
    private function overUsed(): void
    {
        $item = $this->boqFor($this->bags, '100', '1');
        $this->workDone($item, '100');
        $this->deliver($this->bags, '105');
        $this->useOn($item, $this->bags, '105');
    }

    private function reasonUrl(?Project $project = null): string
    {
        return self::API.'/projects/'.($project ?? $this->project)->id.'/material-control/reasons';
    }

    private function checkFlag(string $type): ?array
    {
        $row = collect($this->getJson(self::API.'/projects/'.$this->project->id.'/material-control/check')->assertOk()->json('data.rows'))
            ->firstWhere('material_id', $this->bags->id);

        return collect($row['flags'])->firstWhere('type', $type);
    }

    private function billed(DeliveryChallan $challan): void
    {
        // A posted challan only changes on its bill side, as on the web.
        $challan->update(['bill_status' => 'attached', 'bill_no' => 'INV-77', 'bill_date' => '2026-11-04', 'bill_amount' => '98765.43', 'bill_path' => 'org/bill.jpg', 'bill_attached_by' => $this->owner->id, 'bill_attached_at' => now()]);
    }

    // G2: a reason for a flag

    public function test_a_site_engineer_answers_a_flag_and_a_retry_returns_the_first_reason(): void
    {
        $this->overUsed();
        $engineer = $this->signIn('engineer');
        $uuid = (string) Str::uuid();
        $payload = ['client_uuid' => $uuid, 'material_id' => $this->bags->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'Slab 2 had an extra drop beam.'];

        $this->assertTrue($this->checkFlag(MaterialCheckService::OVER_USED)['open']);

        $first = $this->postJson($this->reasonUrl(), $payload)->assertCreated();
        $first->assertJsonPath('data.client_uuid', $uuid)
            ->assertJsonPath('data.flag_type', 'over_used')
            ->assertJsonPath('data.gap_qty', '5.000')
            ->assertJsonPath('data.created_by', $engineer->name);

        $repeat = $this->postJson($this->reasonUrl(), $payload)->assertOk();
        $repeat->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, MaterialFlagReason::where('client_uuid', $uuid)->count());

        $flag = $this->checkFlag(MaterialCheckService::OVER_USED);
        $this->assertFalse($flag['open']);
        $this->assertSame('Slab 2 had an extra drop beam.', $flag['reason']);
        $this->assertSame($engineer->name, $flag['reason_by']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'auditable_type' => MaterialFlagReason::class, 'auditable_id' => $first->json('data.id')]);
    }

    public function test_the_flag_opens_again_when_the_gap_grows_past_the_reason(): void
    {
        $this->overUsed();
        $this->signIn('store');
        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'Spillage at the mixer.'])->assertCreated();
        $this->assertFalse($this->checkFlag('over_used')['open']);

        $this->actingAs($this->owner);
        $item = $this->project->boqItems()->firstOrFail();
        $this->deliver($this->bags, '3');
        $this->useOn($item, $this->bags, '3');

        $this->signIn('store');
        $flag = $this->checkFlag('over_used');
        $this->assertTrue($flag['open']);
        $this->assertSame('8.000', $flag['gap']);
        $this->assertNull($flag['reason']);

        // A retry of the first reason, a day later, still answers with the first reason and opens nothing new.
        $this->travel(1)->days();
        $old = MaterialFlagReason::firstOrFail();
        $this->postJson($this->reasonUrl(), ['client_uuid' => $old->client_uuid, 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'Spillage at the mixer.'])
            ->assertOk()->assertJsonPath('data.id', $old->id);
        $this->assertSame(1, MaterialFlagReason::count());
    }

    public function test_a_reason_for_a_gap_that_is_not_there_is_refused(): void
    {
        $this->overUsed();
        $this->signIn('qs');

        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'unaccounted', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('flag_type');
        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'nonsense', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('flag_type');
        $this->postJson($this->reasonUrl(), ['material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');
        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertSame(0, MaterialFlagReason::count());
    }

    public function test_only_people_who_may_explain_a_gap_can_write_one(): void
    {
        $this->overUsed();
        $payload = fn () => ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'Why'];

        foreach (['accounts', 'viewer'] as $role) {
            $this->signIn($role);
            $this->postJson($this->reasonUrl(), $payload())->assertForbidden();
        }

        foreach (['pm', 'qs', 'engineer', 'store'] as $role) {
            $this->signIn($role);
            $this->postJson($this->reasonUrl(), $payload())->assertCreated();
        }
    }

    public function test_a_reason_stays_inside_its_company_and_its_projects(): void
    {
        $this->overUsed();
        $other = $this->makeProject($this->organization, ['name' => 'Other site']);

        // Another company cannot reach the project, and cannot name its material.
        $outsider = $this->tenant('Other Co');
        $this->signIn('owner', $this->member($outsider, 'owner'));
        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])->assertNotFound();

        $theirProject = $this->makeProject($outsider);
        $this->postJson($this->reasonUrl($theirProject), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('material_id');

        // Someone limited to the other project cannot reach this one.
        $this->signIn('engineer', $this->limitedTo('engineer', $other));
        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid(), 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])->assertNotFound();

        // A client_uuid already used on this project cannot be replayed onto another.
        $this->signIn('engineer');
        $uuid = (string) Str::uuid();
        $this->postJson($this->reasonUrl(), ['client_uuid' => $uuid, 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])->assertCreated();
        $this->postJson($this->reasonUrl($other), ['client_uuid' => $uuid, 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');

        // And a limited person replaying it from a project they can open does not learn it exists.
        $this->signIn('engineer', $this->limitedTo('engineer', $other));
        $this->postJson($this->reasonUrl($other), ['client_uuid' => $uuid, 'material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');
        $this->assertSame(1, MaterialFlagReason::withoutGlobalScopes()->count());
    }

    // G3: the challan in full

    public function test_a_challan_reads_in_full_with_names_remarks_shorts_and_the_bill_flag(): void
    {
        $supplier = $this->makeVendor('Patil Sand Supply');
        $challan = $this->deliver($this->bags, '38', $supplier, ['challan_qty' => '40', 'challan_no' => 'DC-881', 'remarks' => 'Two bags torn, left at gate.']);
        $this->billed($challan);

        $engineer = $this->signIn('engineer');
        $url = self::API.'/delivery-challans/'.$challan->id;

        $site = $this->getJson($url)->assertOk();
        $site->assertJsonPath('data.id', $challan->id)
            ->assertJsonPath('data.supplier', 'Patil Sand Supply')
            ->assertJsonPath('data.material', 'Cement')
            ->assertJsonPath('data.unit', 'bag')
            ->assertJsonPath('data.store', $this->store->name)
            ->assertJsonPath('data.remarks', 'Two bags torn, left at gate.')
            ->assertJsonPath('data.created_by', $this->owner->name)
            ->assertJsonPath('data.bill_status', 'attached')
            ->assertJsonPath('data.bill_attached_by', $this->owner->name)
            ->assertJsonPath('data.shorts.0.short_qty', '2.000')
            ->assertJsonPath('data.shorts.0.at_entry', true)
            ->assertJsonPath('data.challan_no', 'DC-881')
            ->assertJsonMissingPath('data.bill_no')
            ->assertJsonMissingPath('data.bill_amount')
            ->assertJsonMissingPath('data.has_bill_file')
            ->assertDontSee('98765');

        $this->signIn('accounts');
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.bill_no', 'INV-77')
            ->assertJsonPath('data.bill_amount', '98765.43')
            ->assertJsonPath('data.has_bill_file', true);

        $this->assertNotSame($engineer->id, $this->owner->id);
    }

    public function test_a_challan_is_out_of_reach_of_another_company_and_of_people_limited_to_other_projects(): void
    {
        $challan = $this->deliver($this->bags, '10');
        $url = self::API.'/delivery-challans/'.$challan->id;

        $this->signIn('owner', $this->member($this->tenant('Other Co'), 'owner'));
        $this->getJson($url)->assertNotFound();

        $other = $this->makeProject($this->organization, ['name' => 'Other site']);
        $this->signIn('engineer', $this->limitedTo('engineer', $other));
        $this->getJson($url)->assertNotFound();
        $this->postJson($url.'/short', ['client_uuid' => (string) Str::uuid(), 'short_qty' => '1', 'found_on' => today()->toDateString()])->assertNotFound();
        $this->post($url.'/bill', ['bill' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])->assertNotFound();

        $this->signIn('engineer', $this->limitedTo('engineer', $this->project));
        $this->getJson($url)->assertOk();
    }

    // G3: attaching the bill

    public function test_a_site_engineer_attaches_a_bill_photo_but_cannot_set_its_figures_or_open_it(): void
    {
        $challan = $this->deliver($this->bags, '40');
        $this->signIn('engineer');
        $url = self::API.'/delivery-challans/'.$challan->id.'/bill';
        $payload = ['bill' => UploadedFile::fake()->image('bill.jpg'), 'bill_no' => 'INV-1', 'bill_amount' => '5000'];

        $this->post($url, $payload, ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.bill_status', 'attached')
            ->assertJsonMissingPath('data.bill_amount')
            ->assertJsonMissingPath('data.has_bill_file');

        $challan->refresh();
        $this->assertNotNull($challan->bill_path);
        $this->assertNull($challan->bill_no);
        $this->assertNull($challan->bill_amount);
        Storage::assertExists($challan->bill_path);

        // The same upload sent again leaves one bill behind.
        $first = $challan->bill_path;
        $this->post($url, ['bill' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])->assertOk();
        $challan->refresh();
        Storage::assertMissing($first);
        Storage::assertExists($challan->bill_path);
        $this->assertCount(1, Storage::allFiles('org-'.$this->organization->id.'/material-challans/'.$this->project->id));

        // The file itself is for money.view.
        $this->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_type' => DeliveryChallan::class, 'auditable_id' => $challan->id]);
    }

    public function test_the_accountant_attaches_a_pdf_with_its_figures_and_opens_it(): void
    {
        $challan = $this->deliver($this->bags, '40');
        $this->signIn('accounts');
        $url = self::API.'/delivery-challans/'.$challan->id.'/bill';

        $this->post($url, [
            'bill' => UploadedFile::fake()->create('bill.pdf', 120, 'application/pdf'),
            'bill_no' => 'GT/4471', 'bill_date' => today()->toDateString(), 'bill_amount' => '15200.50',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.bill_no', 'GT/4471')
            ->assertJsonPath('data.bill_amount', '15200.50')
            ->assertJsonPath('data.has_bill_file', true);

        $this->get($url)->assertOk();

        // A number alone is enough for someone who may enter it.
        $second = $this->deliver($this->bags, '5');
        $this->postJson(self::API.'/delivery-challans/'.$second->id.'/bill', ['bill_no' => 'GT/4472'])->assertOk()->assertJsonPath('data.bill_status', 'attached');
    }

    public function test_a_bill_is_refused_without_a_file_from_someone_who_cannot_enter_its_number_and_on_a_reversed_challan(): void
    {
        $challan = $this->deliver($this->bags, '40');
        $url = self::API.'/delivery-challans/'.$challan->id.'/bill';

        $this->signIn('engineer');
        $this->postJson($url, ['bill_no' => 'INV-1'])->assertStatus(422)->assertJsonValidationErrors('bill');
        $this->post($url, ['bill' => UploadedFile::fake()->create('bill.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('bill');

        foreach (['store', 'qs', 'viewer'] as $role) {
            $this->signIn($role);
            $this->post($url, ['bill' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        }

        $this->actingAs($this->owner);
        app(DeliveryChallanService::class)->reverse($challan, 'Wrong site', $this->owner);

        $this->signIn('engineer');
        $this->post($url, ['bill' => UploadedFile::fake()->image('bill.jpg')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('pending', $challan->refresh()->bill_status);
        $this->assertSame([], Storage::allFiles('org-'.$this->organization->id.'/material-challans/'.$this->project->id));
    }

    // G3: a short delivery found later

    public function test_a_store_keeper_reports_a_short_later_and_a_retry_takes_stock_once(): void
    {
        $challan = $this->deliver($this->bags, '40');
        $store = $this->signIn('store');
        $uuid = (string) Str::uuid();
        $url = self::API.'/delivery-challans/'.$challan->id.'/short';
        $payload = ['client_uuid' => $uuid, 'short_qty' => '3', 'found_on' => today()->toDateString(), 'note' => 'Counted at unloading.'];

        $first = $this->postJson($url, $payload)->assertCreated();
        $first->assertJsonPath('data.client_uuid', $uuid)
            ->assertJsonPath('data.short_qty', '3.000')
            ->assertJsonPath('data.at_entry', false)
            ->assertJsonPath('data.created_by', $store->name)
            ->assertJsonPath('challan.shorts.0.id', $first->json('data.id'));

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        // A retry sent the next day is not refused for its date.
        $this->travel(1)->days();
        $this->postJson($url, [...$payload, 'found_on' => today()->subDays(1)->toDateString()])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->travelBack();

        $this->assertSame(1, MaterialShortDelivery::where('client_uuid', $uuid)->count());
        $this->assertSame('37.000', $this->balanceOf($this->bags));
        $this->assertSame('3.000', $this->rowFor($this->bags)['short_delivered']);
    }

    public function test_a_short_is_checked_like_on_the_web(): void
    {
        $challan = $this->deliver($this->bags, '40');
        $url = self::API.'/delivery-challans/'.$challan->id.'/short';

        $this->signIn('engineer');
        $this->postJson($url, ['short_qty' => '1', 'found_on' => today()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('client_uuid');
        $this->postJson($url, ['client_uuid' => (string) Str::uuid(), 'short_qty' => '41', 'found_on' => today()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('short_qty');
        $this->postJson($url, ['client_uuid' => (string) Str::uuid(), 'short_qty' => '1', 'found_on' => today()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('found_on');

        foreach (['accounts', 'qs', 'viewer'] as $role) {
            $this->signIn($role);
            $this->postJson($url, ['client_uuid' => (string) Str::uuid(), 'short_qty' => '1', 'found_on' => today()->toDateString()])->assertForbidden();
        }

        // A client_uuid used on one challan cannot be replayed onto another.
        $this->signIn('pm');
        $uuid = (string) Str::uuid();
        $this->postJson($url, ['client_uuid' => $uuid, 'short_qty' => '1', 'found_on' => today()->toDateString()])->assertCreated();
        $another = $this->deliver($this->bags, '10');
        $this->postJson(self::API.'/delivery-challans/'.$another->id.'/short', ['client_uuid' => $uuid, 'short_qty' => '1', 'found_on' => today()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');

        $this->signIn('owner', $this->member($this->tenant('Other Co'), 'owner'));
        $this->postJson($url, ['client_uuid' => (string) Str::uuid(), 'short_qty' => '1', 'found_on' => today()->toDateString()])->assertNotFound();

        $this->assertSame(1, MaterialShortDelivery::withoutGlobalScopes()->count());
    }

    // G3: bills pending

    public function test_the_bills_list_shows_waiting_challans_with_their_age_and_figures_only_for_money_view(): void
    {
        $old = $this->deliver($this->bags, '10');
        DeliveryChallan::whereKey($old->id)->update(['challan_date' => today()->subDays(9)->toDateString()]);
        $fresh = $this->deliver($this->bags, '20', null, ['challan_date' => today()->subDays(2)->toDateString()]);
        $billed = $this->deliver($this->bags, '30');
        $this->billed($billed);
        $reversed = $this->deliver($this->bags, '5');
        app(DeliveryChallanService::class)->reverse($reversed, 'Wrong site', $this->owner);

        $url = self::API.'/projects/'.$this->project->id.'/material-control/bills';

        $this->signIn('accounts');
        $pending = $this->getJson($url)->assertOk();
        $pending->assertJsonPath('meta.show', 'pending')
            ->assertJsonPath('meta.pending_count', 2)
            ->assertJsonPath('meta.alert_after_days', 7)
            ->assertJsonPath('meta.total', 2);
        $rows = collect($pending->json('data'))->keyBy('id');
        $this->assertSame([$fresh->id, $old->id], $rows->keys()->all());
        $this->assertSame(9, $rows[$old->id]['days_waiting']);
        $this->assertTrue($rows[$old->id]['overdue']);
        $this->assertSame(2, $rows[$fresh->id]['days_waiting']);
        $this->assertFalse($rows[$fresh->id]['overdue']);
        $this->assertSame('Cement', $rows[$old->id]['material']);

        $attached = $this->getJson($url.'?show=attached')->assertOk();
        $attached->assertJsonPath('meta.show', 'attached')->assertJsonPath('meta.pending_count', 2)
            ->assertJsonPath('data.0.id', $billed->id)
            ->assertJsonPath('data.0.bill_amount', '98765.43')
            ->assertJsonPath('data.0.days_waiting', null);

        $this->signIn('engineer');
        $this->getJson($url.'?show=attached')->assertOk()
            ->assertJsonPath('data.0.id', $billed->id)
            ->assertJsonMissingPath('data.0.bill_amount')
            ->assertJsonMissingPath('data.0.bill_no')
            ->assertDontSee('98765');

        foreach (['store', 'qs', 'viewer'] as $role) {
            $this->signIn($role);
            $this->getJson($url)->assertForbidden();
        }

        $this->signIn('owner', $this->member($this->tenant('Other Co'), 'owner'));
        $this->getJson($url)->assertNotFound();

        $other = $this->makeProject($this->organization, ['name' => 'Other site']);
        $this->signIn('accounts', $this->limitedTo('accounts', $other));
        $this->getJson($url)->assertNotFound();
        $this->getJson(self::API.'/projects/'.$other->id.'/material-control/bills')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_new_routes_follow_the_site_control_plan(): void
    {
        $challan = $this->deliver($this->bags, '10');
        $this->organization->update(['plan' => 'free']);
        $this->signIn('pm');

        $this->getJson(self::API.'/delivery-challans/'.$challan->id)->assertForbidden();
        $this->getJson(self::API.'/projects/'.$this->project->id.'/material-control/bills')->assertForbidden();
        $this->postJson($this->reasonUrl(), ['client_uuid' => (string) Str::uuid()])->assertForbidden();
    }
}
