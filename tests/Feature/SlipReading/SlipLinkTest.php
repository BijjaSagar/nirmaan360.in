<?php

namespace Tests\Feature\SlipReading;

use App\Models\AuditLog;
use App\Models\DeliveryChallan;
use App\Models\DocumentRead;
use App\Models\Party;
use App\Models\SlipRead;
use App\Models\User;
use App\Services\MaterialControl\DeliveryChallanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Saving a challan, or a challan's bill, filled from a photo reading: every field the reading filled
 * must be changed or confirmed against the photo, and the server checks it whatever the screen did.
 */
class SlipLinkTest extends SlipReadingTestCase
{
    private User $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->member($this->organization, 'store');
    }

    /** The challan form as the reading filled it, the counted quantity typed by the person. */
    private function filled(SlipRead $slip, array $overrides = []): array
    {
        return [
            'party_id' => $this->vendor->id,
            'material_id' => $this->cement->id,
            'unit_id' => $this->bag->id,
            'qty' => '118',
            'challan_qty' => '120',
            'challan_date' => '2026-11-11',
            'challan_no' => 'DC-0457',
            'vehicle_no' => 'MH12AB1234',
            'client_uuid' => (string) Str::uuid(),
            'read_id' => $slip->id,
            'read_confirmed' => [],
            ...$overrides,
        ];
    }

    private const EVERY_FIELD = ['party_id', 'material_id', 'unit_id', 'challan_qty', 'challan_date', 'challan_no', 'vehicle_no'];

    private function save(array $data, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->store)->post(route('material_control.challans.store', $this->project), $data);
    }

    public function test_every_field_the_photo_filled_must_be_confirmed_or_changed_and_the_counted_quantity_is_never_asked_about(): void
    {
        $slip = $this->storedReading($this->store);

        // Equal to the reading, written differently: still the reading's value, so still to be confirmed.
        $this->save($this->filled($slip, ['challan_no' => ' dc-0457 ', 'vehicle_no' => 'mh-12 ab-1234', 'challan_qty' => '120.0']))
            ->assertSessionHasErrors([...array_fill_keys(self::EVERY_FIELD, 'Confirm this against the photo.')])
            ->assertSessionDoesntHaveErrors(['qty', 'supplier_name']);

        $this->assertSame(0, DeliveryChallan::count());
        $this->assertSame(SlipRead::READY, $slip->refresh()->status);
    }

    public function test_twelve_and_a_half_against_a_reading_of_twelve_point_five_hundred_is_the_same_value(): void
    {
        $slip = $this->storedReading($this->store, ['lines' => [[
            'description' => ['raw' => 'OPC 53', 'value' => 'OPC 53', 'state' => 'read', 'warnings' => []],
            'qty' => ['raw' => '12.500', 'parsed' => '12.500', 'value' => '12.500', 'state' => 'read', 'warnings' => []],
            'unit' => ['raw' => 'MT', 'value' => 'MT', 'state' => 'read', 'warnings' => [], 'known' => true, 'unit_id' => $this->mt->id],
        ]]]);

        $this->save($this->filled($slip, ['challan_qty' => '12.5', 'read_confirmed' => array_diff(self::EVERY_FIELD, ['challan_qty'])]))
            ->assertSessionHasErrors(['challan_qty' => 'Confirm this against the photo.']);
    }

    public function test_a_confirmed_form_is_saved_and_the_reading_records_names_of_fields_only(): void
    {
        $slip = $this->storedReading($this->store);

        $this->save($this->filled($slip, ['challan_qty' => '125', 'read_confirmed' => array_diff(self::EVERY_FIELD, ['challan_qty'])]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $challan = DeliveryChallan::sole();
        $this->assertSame(['118.000', '125.000', $this->vendor->id], [$challan->qty, $challan->challan_qty, $challan->party_id]);

        $slip->refresh();
        $this->assertSame(SlipRead::USED, $slip->status);
        $this->assertSame($challan->id, $slip->delivery_challan_id);
        $this->assertSame($challan->client_uuid, $slip->used_client_uuid);
        $this->assertEqualsCanonicalizing(['party_id', 'material_id', 'unit_id', 'challan_date', 'challan_no', 'vehicle_no'], $slip->confirmed['fields']);
        $this->assertSame(['challan_qty'], $slip->confirmed['typed_over']);
        $this->assertSame($this->store->id, $slip->confirmed['by']);
        $this->assertSame(DocumentRead::CONFIRMED, $this->readOf($slip)->status);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'ai.read_confirmed')->sole();
        $this->assertSame(['fields_confirmed' => 6, 'fields_typed_over' => 1], array_intersect_key($audit->new_values, ['fields_confirmed' => 1, 'fields_typed_over' => 1]));
        $this->assertStringNotContainsString('DC-0457', json_encode($audit->new_values));
        $this->assertStringNotContainsString('MH12AB1234', json_encode($audit->new_values));

        // A reading is used once.
        $this->save($this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]))->assertSessionHasErrors('read_id');
        $this->assertSame(1, DeliveryChallan::count());
    }

    public function test_the_supplier_name_as_read_is_never_saved_without_the_persons_own_action(): void
    {
        $slip = $this->storedReading($this->store, ['suggestions' => ['party' => null, 'materials' => []]]);
        $form = $this->filled($slip, ['party_id' => null, 'supplier_name' => 'Godavari Cement Traders', 'read_confirmed' => ['unit_id', 'challan_qty', 'challan_date', 'challan_no', 'vehicle_no']]);

        $this->save($form)->assertSessionHasErrors(['supplier_name' => 'Confirm this against the photo.']);
        $this->assertSame(0, Party::where('name', 'Godavari Cement Traders')->where('id', '!=', $this->vendor->id)->count());

        // "Use as a new supplier" is the person's own action.
        $this->save([...$form, 'read_confirmed' => [...$form['read_confirmed'], 'supplier_name']])->assertSessionHasNoErrors();
        $this->assertSame(['supplier_name'], array_values(array_intersect(['supplier_name'], $slip->refresh()->confirmed['fields'])));
    }

    public function test_the_unit_counts_only_when_it_is_a_unit_of_the_chosen_material(): void
    {
        $slip = $this->storedReading($this->store);
        $rest = ['party_id', 'material_id', 'challan_qty', 'challan_date', 'challan_no', 'vehicle_no'];

        // Bags are a unit of cement: left as read, the unit must be confirmed.
        $this->save($this->filled($slip, ['read_confirmed' => $rest]))->assertSessionHasErrors(['unit_id' => 'Confirm this against the photo.']);

        // The base unit chosen instead: changed by the person.
        $this->save($this->filled($slip, ['unit_id' => null, 'read_confirmed' => $rest]))->assertSessionHasNoErrors();
        $this->assertContains('unit_id', $slip->refresh()->confirmed['typed_over']);
    }

    public function test_a_unit_the_chosen_material_does_not_have_was_never_offered(): void
    {
        $slip = $this->storedReading($this->store, ['suggestions' => ['party' => ['id' => $this->vendor->id, 'name' => $this->vendor->name, 'by' => 'gstin'], 'materials' => []]]);

        // Steel has no bags, so the reading filled no unit for it.
        $this->save($this->filled($slip, ['material_id' => $this->steel->id, 'unit_id' => null, 'read_confirmed' => ['party_id', 'challan_qty', 'challan_date', 'challan_no', 'vehicle_no']]))
            ->assertSessionHasNoErrors();
        $this->assertNotContains('unit_id', [...$slip->refresh()->confirmed['fields'], ...$slip->confirmed['typed_over']]);
    }

    public function test_without_a_photo_of_its_own_the_challan_keeps_a_copy_of_the_photo_that_was_read(): void
    {
        $slip = $this->storedReading($this->store);
        $this->save($this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]))->assertSessionHasNoErrors();

        $challan = DeliveryChallan::sole();
        $this->assertNotNull($challan->photo_path);
        $this->assertStringStartsWith('org-'.$this->organization->id.'/material-challans/'.$this->project->id.'/', $challan->photo_path);
        Storage::assertExists($challan->photo_path);
        $this->assertStringNotContainsString('document-reads', $challan->photo_path);

        // With a photo of its own, that one is kept and nothing is copied.
        $second = $this->storedReading($this->store);
        $this->save($this->filled($second, ['read_confirmed' => self::EVERY_FIELD, 'photo' => UploadedFile::fake()->image('own.jpg')]))->assertSessionHasNoErrors();
        $this->assertCount(2, Storage::allFiles('org-'.$this->organization->id.'/material-challans/'.$this->project->id));
    }

    public function test_a_phone_retry_of_a_saved_challan_returns_it_and_is_not_refused(): void
    {
        $slip = $this->storedReading($this->store);
        $form = $this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]);
        Sanctum::actingAs($this->store);

        $first = $this->postJson("/api/v1/projects/{$this->project->id}/material-control/challans", $form)->assertCreated();
        $again = $this->postJson("/api/v1/projects/{$this->project->id}/material-control/challans", $form)->assertOk();

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, DeliveryChallan::count());
    }

    public function test_only_the_persons_own_ready_reading_of_this_project_can_fill_the_form(): void
    {
        $everything = ['read_confirmed' => self::EVERY_FIELD];

        $someoneElses = $this->storedReading($this->member($this->organization, 'store'));
        $this->save($this->filled($someoneElses, $everything))->assertSessionHasErrors(['read_id' => 'This photo reading cannot be used for this form.']);

        $otherProject = $this->storedReading($this->store, [], $this->otherProject);
        $this->save($this->filled($otherProject, $everything))->assertSessionHasErrors('read_id');

        $bill = $this->storedReading($this->store, [], null, SlipRead::BILL);
        $this->save($this->filled($bill, $everything))->assertSessionHasErrors('read_id');

        foreach ([SlipRead::DISCARDED, SlipRead::FAILED, SlipRead::READING] as $status) {
            $slip = $this->storedReading($this->store, ['status' => $status]);
            $this->save($this->filled($slip, $everything))->assertSessionHasErrors(['read_id' => 'This photo reading can no longer be used. Read the photo again or type the details yourself.']);
        }

        // A reading whose read expired while nobody used it.
        $expired = $this->storedReading($this->store);
        DocumentRead::withoutGlobalScopes()->whereKey($expired->document_read_id)->update(['status' => DocumentRead::EXPIRED]);
        $this->save($this->filled($expired, $everything))->assertSessionHasErrors('read_id');

        // Another company's reading is not found at all.
        $rival = $this->tenant('Narmada Infra');
        $rivalStore = $this->member($rival, 'store');
        $rivalProject = $this->makeProject($rival);
        $theirs = $this->storedReading($rivalStore, [], $rivalProject);
        $this->save($this->filled($theirs, $everything))->assertSessionHasErrors('read_id');

        $this->assertSame(0, DeliveryChallan::count());
    }

    public function test_each_warning_about_the_photo_is_ticked_before_saving(): void
    {
        $slip = $this->storedReading($this->store, ['warnings' => [
            ['code' => 'possible_duplicate', 'params' => ['number' => 'DC-0003'], 'tick' => true],
            ['code' => 'not_a_challan', 'params' => ['doc_kind' => 'other'], 'tick' => false],
        ]]);

        $this->save($this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]))->assertSessionHasErrors(['read_confirmed' => 'Tick each warning about this photo before saving.']);
        $this->save($this->filled($slip, ['read_confirmed' => [...self::EVERY_FIELD, 'warning:possible_duplicate']]))->assertSessionHasNoErrors();
        $this->assertSame(['possible_duplicate'], $slip->refresh()->confirmed['warnings']);
    }

    public function test_a_form_without_a_reading_is_saved_as_before(): void
    {
        app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '10', 'challan_date' => '2026-11-11',
        ], $this->store);

        $this->assertSame(1, DeliveryChallan::count());
        $this->assertSame(0, SlipRead::withoutGlobalScopes()->where('status', SlipRead::USED)->count());
    }

    public function test_a_bill_attached_to_a_challan_from_its_photo_needs_each_figure_confirmed(): void
    {
        $owner = $this->owner;
        $challan = app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '10', 'challan_date' => '2026-11-11',
        ], $owner);
        $slip = $this->storedReading($owner, [], null, SlipRead::BILL);
        $form = ['bill_no' => 'inv/0912', 'bill_date' => '2026-11-10', 'bill_amount' => '70800', 'read_id' => $slip->id];

        $this->actingAs($owner)->post(route('material_control.challans.bill.store', $challan), $form)
            ->assertSessionHasErrors(['bill_no' => 'Confirm this against the photo.', 'bill_date' => 'Confirm this against the photo.', 'bill_amount' => 'Confirm this against the photo.']);
        $this->assertNull($challan->refresh()->bill_no);

        $this->actingAs($owner)->post(route('material_control.challans.bill.store', $challan), [...$form, 'bill_amount' => '70500', 'read_confirmed' => ['bill_no', 'bill_date']])
            ->assertSessionHasNoErrors();

        $challan->refresh();
        $this->assertSame(['inv/0912', '70500.00'], [$challan->bill_no, $challan->bill_amount]);
        $slip->refresh();
        $this->assertSame([SlipRead::USED, $challan->id], [$slip->status, $slip->delivery_challan_id]);
        $this->assertSame(['bill_amount'], $slip->confirmed['typed_over']);

        // Sending the same bill again for the same challan is a retry, not a second use.
        $this->actingAs($owner)->post(route('material_control.challans.bill.store', $challan), [...$form, 'read_confirmed' => ['bill_no', 'bill_date', 'bill_amount']])
            ->assertSessionHasNoErrors();

        // A bill reading cannot fill a challan that is not the one it went to.
        $other = app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '5', 'challan_date' => '2026-11-11',
        ], $owner);
        $this->actingAs($owner)->post(route('material_control.challans.bill.store', $other), [...$form, 'read_confirmed' => ['bill_no', 'bill_date', 'bill_amount']])
            ->assertSessionHasErrors('read_id');
    }

    public function test_the_bill_figures_of_a_reading_are_taken_only_from_someone_who_sees_money(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $challan = app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '10', 'challan_date' => '2026-11-11',
        ], $engineer);
        $slip = $this->storedReading($engineer, [], null, SlipRead::BILL);

        // Without money.view the bill's figures are not in the form at all, so neither is a reading of them.
        $this->actingAs($engineer)->post(route('material_control.challans.bill.store', $challan), ['bill' => UploadedFile::fake()->image('bill.jpg'), 'read_id' => $slip->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(SlipRead::READY, $slip->refresh()->status);
    }
}
