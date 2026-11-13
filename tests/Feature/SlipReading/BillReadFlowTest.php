<?php

namespace Tests\Feature\SlipReading;

use App\Models\DeliveryChallan;
use App\Models\DocumentRead;
use App\Models\SlipRead;
use App\Services\Finance\VendorBillService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\SlipReading\BillReadKind;
use App\Services\SlipReading\SlipReadKind;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

/**
 * A supplier bill photo from a bill form to a reading the form is filled from, through the routes
 * and the page job, and the two bill forms that offer it: the vendor bill form and a challan's bill.
 */
class BillReadFlowTest extends SlipReadingTestCase
{
    private const BUTTON = 'Read details from the bill';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withBillReader();
    }

    private function postedChallan(): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '10', 'challan_date' => '2026-11-11',
        ], $this->owner);
    }

    public function test_a_bill_photo_is_read_once_by_the_bill_model_and_offered_to_the_form(): void
    {
        $accounts = $this->member($this->organization, 'accounts');
        $this->willAnswer($this->reply($this->billForm(), 'claude-sonnet-5-5'));

        $created = $this->readBill($accounts)->assertStatus(202);
        $this->assertTrue(Dec::of($created->json('credits_cap'))->isPositive());
        $slip = SlipRead::withoutGlobalScopes()->findOrFail($created->json('id'));
        $this->assertSame([BillReadKind::KEY, DocumentRead::RUNNING, 'web'], [$this->readOf($slip)->kind, $this->readOf($slip)->status, $this->readOf($slip)->channel]);

        $this->runPages($slip);

        // One call: the stored picture with the bill's own form, no tools and no thinking, and nothing about the company.
        $this->assertCount(1, $this->sent);
        $body = $this->sent[0];
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(['model', 'max_tokens', 'system', 'messages', 'output_config'], array_keys($body));
        $this->assertSame(['oversized_image' => 'error'], $body['messages'][0]['content'][0]['transformations']);
        $this->assertSame(BillReadKind::schema(), $body['output_config']['format']['schema']);
        $this->assertStringNotContainsString($this->project->name, json_encode($body));
        $this->assertStringNotContainsString($accounts->name, json_encode($body));

        $this->assertSame(SlipRead::READY, $slip->refresh()->status);

        $shown = $this->actingAs($accounts)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('status', 'ready')->assertJsonPath('kind', 'bill')->assertJsonPath('doc_kind', 'tax_invoice')
            ->assertJsonPath('fields.bill_no.value', 'INV/0912')
            ->assertJsonPath('fields.bill_date.value', '2026-11-10')
            ->assertJsonPath('fields.taxable_total.value', '60000.00')
            ->assertJsonPath('fields.gst_pct.value', '18')
            ->assertJsonPath('fields.supply_type.value', 'intra')
            ->assertJsonPath('fields.grand_total.value', '70800.00')
            ->assertJsonPath('lines.0.amount.value', '60000.00')
            ->assertJsonPath('suggestions.party.id', $this->vendor->id)
            ->assertJsonPath('suggestions.materials', [])
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('can_recheck', false);

        $this->assertArrayNotHasKey('parsed', $shown->json('fields.grand_total'));
        $this->assertArrayNotHasKey('parsed', $shown->json('lines.0.rate'));
    }

    public function test_a_bill_photo_and_its_reading_are_for_someone_who_sees_money_and_enters_bills(): void
    {
        foreach (['store', 'engineer', 'qs', 'viewer'] as $role) {
            $this->readBill($this->member($this->organization, $role))->assertForbidden();
        }

        foreach (['accounts', 'pm', 'owner'] as $role) {
            $this->readBill($this->member($this->organization, $role))->assertStatus(202);
        }

        // The field app reads challans only.
        $this->readPhotoFromPhone($this->member($this->organization, 'accounts'), null, ['kind' => 'bill'])->assertUnprocessable()->assertJsonValidationErrors('kind');

        $accounts = $this->member($this->organization, 'accounts');
        $slip = $this->readyBill($accounts);

        $this->actingAs($this->member($this->organization, 'pm'))->getJson("/slip-reads/{$slip->id}")->assertForbidden();
        $this->actingAs($this->owner)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('fields.grand_total.value', '70800.00');
        Sanctum::actingAs($this->member($this->organization, 'store'));
        $this->getJson("/api/v1/slip-reads/{$slip->id}")->assertForbidden();

        // Another company finds neither the project nor the reading.
        $rivalOwner = $this->member($this->tenant('Narmada Infra'), 'owner');
        $this->actingAs($rivalOwner)->getJson("/slip-reads/{$slip->id}")->assertNotFound();
        $this->readBill($rivalOwner)->assertNotFound();
    }

    public function test_the_stronger_model_is_offered_for_a_bill_only_after_its_figures_do_not_add_up_and_only_when_one_is_named(): void
    {
        $accounts = $this->member($this->organization, 'accounts');
        $wrongTotal = $this->billForm(['grand_total' => $this->cell('70,880.00')]);

        // As shipped, the bill's figures name no stronger model: nothing to offer, even when the total does not add up.
        $slip = $this->readyBill($accounts, $wrongTotal);
        $this->actingAs($accounts)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('can_recheck', false)->assertJsonPath('fields.grand_total.value', null);
        $this->actingAs($accounts)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(409)->assertJsonPath('code', 'not_offered');

        config(['modules.document_reading.kinds.bill.passes.main.stronger' => 'claude-opus-5-5']);

        // A GSTIN warning is never a reason; a total that does not add up is.
        $warned = $this->readyBill($accounts, $this->billForm(['supplier_gstin' => $this->cell('27ABCDE1234F1Z9')]));
        $this->actingAs($accounts)->getJson("/slip-reads/{$warned->id}")->assertOk()->assertJsonPath('can_recheck', false);

        $slip = $this->readyBill($accounts, $wrongTotal);
        $this->actingAs($accounts)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('can_recheck', true);

        $this->sent = [];
        $this->willAnswer($this->reply($this->billForm(['bill_no' => $this->cell('INV/0812')]), 'claude-opus-5-5'));
        $this->actingAs($accounts)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(202);
        $this->runPages($slip);

        $this->assertSame('claude-opus-5-5', $this->sent[0]['model']);
        $this->actingAs($accounts)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('rechecked', true)
            ->assertJsonPath('can_recheck', false)
            ->assertJsonPath('fields.bill_no.value', null)
            ->assertJsonPath('fields.grand_total.value', null)
            ->assertJsonPath('fields.taxable_total.value', '60000.00')
            ->assertJsonPath('fields.gst_pct.value', '18');
    }

    public function test_the_vendor_bill_form_offers_the_reader_to_whoever_may_use_it_while_it_is_on(): void
    {
        $accounts = $this->member($this->organization, 'accounts');
        $form = fn () => $this->actingAs($accounts->fresh())->get(route('finance.project.vendor-bills.create', $this->project))->assertOk();

        $page = $form()->assertSee(self::BUTTON)
            ->assertSee('Each field shows the text as read, beside the photo. You confirm each field against the photo.')
            ->assertSee('data-reader-capture', false)
            ->assertSee('slip_bill_photo', false);
        $this->assertMatchesRegularExpression('/Read details from the bill \(up to \d+ credits\)/', $page->getContent());
        // The picture input is not sent with the form: the bill keeps no copy of it.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="[^"]*"[^>]*id="slip_bill_photo"|<input[^>]*id="slip_bill_photo"[^>]*name=/', $page->getContent());

        // Its editing form too.
        $bill = app(VendorBillService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'bill_no' => 'INV/0700', 'bill_date' => '2026-11-01', 'cost_head' => 'Materials',
            'supply_type' => 'intra', 'taxable_amount' => '1000', 'gst_pct' => '18',
        ], $accounts);
        $this->actingAs($accounts)->get(route('finance.project.vendor-bills.edit', $bill))->assertOk()->assertSee(self::BUTTON);

        // Not while the bill reader is not deployed, the readers are not released, or the plan has no AI.
        config(['modules.slip_reading.read_kinds' => [SlipReadKind::class]]);
        $form()->assertDontSee(self::BUTTON)->assertDontSee('data-reader-capture', false);
        $this->withBillReader();

        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $form()->assertDontSee(self::BUTTON);
        $this->release();

        $this->organization->update(['plan' => 'contractor']);
        $form()->assertDontSee(self::BUTTON);
        $this->organization->update(['plan' => 'business']);

        $form()->assertSee(self::BUTTON);
    }

    public function test_a_challans_bill_form_offers_the_reader_only_to_someone_who_sees_money(): void
    {
        $challan = $this->postedChallan();

        $this->actingAs($this->member($this->organization, 'accounts'))->get(route('material_control.challans.show', $challan))->assertOk()
            ->assertSee(self::BUTTON)->assertSee('Attach bill');

        // The engineer may attach a bill file but sees no money, so no bill figures and no reader.
        $this->actingAs($this->member($this->organization, 'engineer'))->get(route('material_control.challans.show', $challan))->assertOk()
            ->assertSee('Attach bill')->assertDontSee(self::BUTTON)->assertDontSee('data-reader-capture', false);
    }

    public function test_a_challans_bill_filled_from_a_photo_says_so_to_those_who_see_its_figures(): void
    {
        $challan = $this->postedChallan();
        $this->owner->update(['name' => 'Rohan Deshmukh']);
        $slip = $this->storedReading($this->owner, [], null, SlipRead::BILL);

        $this->actingAs($this->owner)->post(route('material_control.challans.bill.store', $challan), [
            'bill' => UploadedFile::fake()->image('bill.jpg'), 'bill_no' => 'INV/0912', 'bill_date' => '2026-11-10', 'bill_amount' => '70500',
            'read_id' => $slip->id, 'read_confirmed' => ['bill_no', 'bill_date'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->member($this->organization, 'accounts'))->get(route('material_control.challans.show', $challan))->assertOk()
            ->assertSee('Filled from a photo. Confirmed field by field by Rohan Deshmukh on 13 Nov 2026')
            ->assertSee('Typed over by hand: Amount');

        $this->actingAs($this->member($this->organization, 'engineer'))->get(route('material_control.challans.show', $challan))->assertOk()
            ->assertDontSee('Filled from a photo');
    }

    public function test_the_bill_screens_are_in_the_persons_language(): void
    {
        $accounts = $this->member($this->organization, 'accounts');

        $accounts->update(['locale' => 'hi']);
        $this->actingAs($accounts)->get(route('finance.project.vendor-bills.create', $this->project))->assertOk()
            ->assertSee('बिल से विवरण पढ़ें')->assertDontSee(self::BUTTON);

        $accounts->update(['locale' => 'mr']);
        $this->actingAs($accounts)->get(route('material_control.challans.show', $this->postedChallan()))->assertOk()
            ->assertSee('बिलावरून तपशील वाचा')->assertDontSee(self::BUTTON);
    }
}
