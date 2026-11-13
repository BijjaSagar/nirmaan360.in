<?php

namespace Tests\Feature\SlipReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\SlipRead;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\Finance\VendorBillService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * Saving a vendor bill filled from a photo of the bill: every field the reading filled must be
 * changed or confirmed against the photo, on create and on update, whatever the screen did. The bill
 * is saved as a draft and goes through its approvals as before; its page says it was filled from a
 * photo and which fields were typed over.
 */
class VendorBillLinkTest extends SlipReadingTestCase
{
    private const EVERY_FIELD = ['party_id', 'bill_no', 'bill_date', 'taxable_amount', 'gst_pct', 'supply_type'];

    private User $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withBillReader();
        $this->accounts = $this->member($this->organization, 'accounts');
    }

    /** The vendor bill form as the reading filled it; the cost head and the TDS are the person's own. */
    private function filled(SlipRead $slip, array $overrides = []): array
    {
        return [
            'party_id' => $this->vendor->id,
            'bill_no' => 'INV/0912',
            'bill_date' => '2026-11-10',
            'cost_head' => 'Materials',
            'supply_type' => 'intra',
            'taxable_amount' => '60000.00',
            'gst_pct' => '18',
            'tds_pct' => '1',
            'tds_section' => '194C',
            'client_uuid' => (string) Str::uuid(),
            'read_id' => $slip->id,
            'read_confirmed' => [],
            ...$overrides,
        ];
    }

    private function save(array $data, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->accounts)->post(route('finance.project.vendor-bills.store', $this->project), $data);
    }

    private function change(VendorBill $bill, array $data, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->accounts)->patch(route('finance.project.vendor-bills.update', $bill), $data);
    }

    private function handBill(string $number = 'INV/0700'): VendorBill
    {
        return app(VendorBillService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'bill_no' => $number, 'bill_date' => '2026-11-01', 'cost_head' => 'Materials',
            'supply_type' => 'intra', 'taxable_amount' => '1000', 'gst_pct' => '18',
        ], $this->accounts);
    }

    public function test_every_field_the_photo_filled_must_be_confirmed_or_changed_and_the_tds_is_never_asked_about(): void
    {
        $slip = $this->storedReading($this->accounts, [], null, SlipRead::BILL);

        // Equal to the reading, written differently: still the reading's value, so still to be confirmed.
        $this->save($this->filled($slip, ['bill_no' => ' inv/0912 ', 'taxable_amount' => '60000']))
            ->assertSessionHasErrors(array_fill_keys(self::EVERY_FIELD, 'Confirm this against the photo.'))
            ->assertSessionDoesntHaveErrors(['tds_pct', 'tds_section', 'cost_head', 'due_date']);

        $this->assertSame(0, VendorBill::count());
        $this->assertSame(SlipRead::READY, $slip->refresh()->status);
    }

    public function test_a_confirmed_bill_is_saved_as_a_draft_and_the_reading_keeps_names_of_fields_only(): void
    {
        $slip = $this->storedReading($this->accounts, [], null, SlipRead::BILL);
        $data = $this->filled($slip, ['taxable_amount' => '59000', 'read_confirmed' => array_values(array_diff(self::EVERY_FIELD, ['taxable_amount']))]);

        $this->save($data)->assertSessionHasNoErrors()->assertRedirect();

        $bill = VendorBill::sole();
        $this->assertSame(['DRAFT', '59000.00', $this->accounts->id], [$bill->status, $bill->taxable_amount, $bill->created_by]);

        $slip->refresh();
        $this->assertSame([SlipRead::USED, $bill->id, null], [$slip->status, $slip->vendor_bill_id, $slip->delivery_challan_id]);
        $this->assertSame('vendor_bill', $slip->confirmed['context']);
        $this->assertEqualsCanonicalizing(['party_id', 'bill_no', 'bill_date', 'gst_pct', 'supply_type'], $slip->confirmed['fields']);
        $this->assertSame(['taxable_amount'], $slip->confirmed['typed_over']);
        $this->assertSame($this->accounts->id, $slip->confirmed['by']);
        $this->assertSame(DocumentRead::CONFIRMED, $this->readOf($slip)->status);

        // Field names and counts only: no value read from the photo is kept with the record or the log.
        $this->assertStringNotContainsString('0912', json_encode($slip->confirmed));
        $audit = AuditLog::withoutGlobalScopes()->where('action', 'ai.read_confirmed')->sole();
        $this->assertSame(['vendor_bill', $bill->id, 5, 1], [$audit->new_values['context'], $audit->new_values['record_id'], $audit->new_values['fields_confirmed'], $audit->new_values['fields_typed_over']]);
        $this->assertStringNotContainsString('0912', json_encode($audit->new_values));

        // The same form sent again by the same person is a retry: the bill it made, nothing new.
        $this->save($data)->assertSessionHasNoErrors();
        $this->assertSame(1, VendorBill::count());
    }

    public function test_editing_a_draft_from_a_photo_needs_the_same_confirmations_and_the_reading_fills_one_bill_only(): void
    {
        $bill = $this->handBill();
        $slip = $this->storedReading($this->accounts, [], null, SlipRead::BILL);
        $form = $this->filled($slip, ['client_uuid' => null]);

        $this->change($bill, $form)->assertSessionHasErrors(array_fill_keys(self::EVERY_FIELD, 'Confirm this against the photo.'));
        $this->assertSame('INV/0700', $bill->refresh()->bill_no);

        $this->change($bill, [...$form, 'read_confirmed' => self::EVERY_FIELD])->assertSessionHasNoErrors();
        $this->assertSame(['INV/0912', '60000.00'], [$bill->refresh()->bill_no, $bill->taxable_amount]);
        $this->assertSame([SlipRead::USED, $bill->id], [$slip->refresh()->status, $slip->vendor_bill_id]);

        // Saving the same bill again with its reading is a retry; another bill cannot take the reading.
        $this->change($bill, [...$form, 'read_confirmed' => self::EVERY_FIELD])->assertSessionHasNoErrors();
        $other = $this->handBill('INV/0701');
        $this->change($other, [...$form, 'bill_no' => 'INV/0913', 'read_confirmed' => self::EVERY_FIELD])
            ->assertSessionHasErrors(['read_id' => 'This photo reading can no longer be used. Read the photo again or type the details yourself.']);
        $this->assertSame('INV/0701', $other->refresh()->bill_no);
    }

    public function test_only_the_persons_own_ready_bill_reading_of_this_project_can_fill_the_form(): void
    {
        $refused = 'This photo reading cannot be used for this form.';
        $confirmed = ['read_confirmed' => self::EVERY_FIELD];

        // Someone else's reading, even the owner's.
        $this->save($this->filled($this->storedReading($this->owner, [], null, SlipRead::BILL), $confirmed))->assertSessionHasErrors(['read_id' => $refused]);
        // A reading of a challan.
        $this->save($this->filled($this->storedReading($this->accounts), $confirmed))->assertSessionHasErrors(['read_id' => $refused]);
        // A reading made in another project.
        $this->save($this->filled($this->storedReading($this->accounts, [], $this->otherProject, SlipRead::BILL), $confirmed))->assertSessionHasErrors(['read_id' => $refused]);
        // A reading that was discarded.
        $this->save($this->filled($this->storedReading($this->accounts, ['status' => SlipRead::DISCARDED], null, SlipRead::BILL), $confirmed))
            ->assertSessionHasErrors(['read_id' => 'This photo reading can no longer be used. Read the photo again or type the details yourself.']);

        // Another company's reading is not found at all.
        $rival = $this->tenant('Narmada Infra');
        $rivalProject = $this->makeProject($rival);
        $foreign = SlipRead::withoutGlobalScopes()->create([
            'organization_id' => $rival->id, 'project_id' => $rivalProject->id, 'document_read_id' => 0, 'kind' => SlipRead::BILL,
            'status' => SlipRead::READY, 'user_id' => $this->accounts->id, 'client_uuid' => (string) Str::uuid(),
        ]);
        $this->save($this->filled($foreign, $confirmed))->assertSessionHasErrors('read_id');

        $this->assertSame(0, VendorBill::count());
    }

    public function test_each_warning_that_asks_for_a_tick_is_ticked_before_saving(): void
    {
        $slip = $this->storedReading($this->accounts, ['warnings' => [
            ['code' => 'same_photo', 'params' => [], 'tick' => true],
            ['code' => 'bill_already_entered', 'params' => ['number' => 'INV/0912'], 'tick' => false],
        ]], null, SlipRead::BILL);

        $this->save($this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]))
            ->assertSessionHasErrors(['read_confirmed' => 'Tick each warning about this photo before saving.']);

        $this->save($this->filled($slip, ['read_confirmed' => [...self::EVERY_FIELD, 'warning:same_photo']]))->assertSessionHasNoErrors();
        $this->assertSame(['same_photo'], $slip->refresh()->confirmed['warnings']);
    }

    public function test_a_bill_reading_needs_someone_who_sees_money_and_a_person_to_save_it(): void
    {
        $slip = $this->storedReading($this->accounts, [], null, SlipRead::BILL);
        $data = $this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]);

        // The person who read it no longer sees money.
        $this->accounts->forceFill(['role' => 'store'])->save();

        try {
            app(VendorBillService::class)->create($this->project, $data, $this->accounts->refresh());
            $this->fail('A bill reading was used by someone who does not see money.');
        } catch (ValidationException $exception) {
            $this->assertSame(['read_id' => ['This photo reading cannot be used for this form.']], $exception->errors());
        }

        // A change made with nobody signed in cannot use a reading.
        $bill = $this->handBill();
        Auth::logout();

        try {
            app(VendorBillService::class)->update($bill, $data);
            $this->fail('A bill reading was used with nobody signed in.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('read_id', $exception->errors());
        }

        $this->assertSame(SlipRead::READY, $slip->refresh()->status);
    }

    public function test_a_bill_without_a_reading_is_saved_as_before(): void
    {
        $data = $this->filled($this->storedReading($this->accounts, [], null, SlipRead::BILL), ['read_id' => null, 'read_confirmed' => null]);

        $this->save($data)->assertSessionHasNoErrors();
        $this->assertSame(1, VendorBill::count());
        $this->assertSame(0, SlipRead::withoutGlobalScopes()->where('status', SlipRead::USED)->count());
    }

    public function test_the_bills_page_tells_its_approvers_it_was_filled_from_a_photo_and_what_was_typed_over(): void
    {
        $this->accounts->update(['name' => 'Meera Kulkarni']);
        $slip = $this->storedReading($this->accounts, [], null, SlipRead::BILL);
        $this->save($this->filled($slip, ['taxable_amount' => '59000', 'gst_pct' => '12', 'read_confirmed' => ['party_id', 'bill_no', 'bill_date', 'supply_type']]))->assertSessionHasNoErrors();
        $bill = VendorBill::sole();

        $approver = $this->member($this->organization, 'pm');
        $this->actingAs($approver)->get(route('finance.project.vendor-bills.show', $bill))->assertOk()
            ->assertSee('Filled from a photo. Confirmed field by field by Meera Kulkarni on 13 Nov 2026')
            ->assertSee('Typed over by hand: Taxable value, GST rate');

        $approver->update(['locale' => 'mr']);
        $this->actingAs($approver)->get(route('finance.project.vendor-bills.show', $bill))->assertOk()
            ->assertSee('फोटोवरून भरले. Meera Kulkarni यांनी 13 Nov 2026 रोजी प्रत्येक फील्डची स्वतंत्रपणे खात्री केली')
            ->assertSee('हाताने बदलून लिहिले: करपात्र मूल्य, GST दर');

        // A bill typed by hand says nothing of the kind.
        $this->actingAs($approver->fresh())->get(route('finance.project.vendor-bills.show', $this->handBill()))->assertOk()->assertDontSee('Meera Kulkarni');
    }

    public function test_a_bill_changed_by_hand_after_it_was_filled_from_a_photo_tells_its_approvers_what_changed(): void
    {
        $this->accounts->update(['name' => 'Meera Kulkarni']);
        $slip = $this->storedReading($this->accounts, [], null, SlipRead::BILL);
        $form = $this->filled($slip, ['read_confirmed' => self::EVERY_FIELD]);
        $this->save($form)->assertSessionHasNoErrors();
        $bill = VendorBill::sole();
        $byHand = array_diff_key($form, array_flip(['read_id', 'read_confirmed', 'client_uuid']));

        // Saved again as it was: nothing changed, so nothing is said.
        $this->change($bill, $byHand)->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('changed_later', $slip->refresh()->confirmed);

        // Returned and edited by hand on the usual form.
        $this->travel(1)->days();
        $this->change($bill, [...$byHand, 'bill_no' => 'INV/0912-A', 'taxable_amount' => '72000', 'description' => 'Second lot'])->assertSessionHasNoErrors();
        $this->assertSame(['fields' => ['bill_no', 'taxable_amount'], 'by' => $this->accounts->id], array_intersect_key($slip->refresh()->confirmed['changed_later'], ['fields' => 1, 'by' => 1]));

        $approver = $this->member($this->organization, 'pm');
        $this->actingAs($approver)->get(route('finance.project.vendor-bills.show', $bill))->assertOk()
            ->assertSee('Filled from a photo. Confirmed field by field by Meera Kulkarni on 13 Nov 2026')
            ->assertSee('Changed by hand later by Meera Kulkarni on 14 Nov 2026: Vendor bill no., Taxable value');

        $approver->update(['locale' => 'hi']);
        $this->actingAs($approver)->get(route('finance.project.vendor-bills.show', $bill))->assertOk()
            ->assertSee('बाद में Meera Kulkarni ने 14 Nov 2026 को हाथ से बदला');
    }
}
