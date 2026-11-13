<?php

namespace Tests\Feature\SlipReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\SlipRead;
use App\Services\SlipReading\SlipReadKind;
use App\Support\Dec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** A challan photo from the form to a reading the form is filled from, through the routes and the page job. */
class SlipReadFlowTest extends SlipReadingTestCase
{
    public function test_a_photo_is_held_for_sent_once_read_and_offered_to_the_form(): void
    {
        $store = $this->member($this->organization, 'store');
        $this->willAnswer($this->reply($this->challanForm()));

        $created = $this->readPhoto($store)->assertStatus(202)->assertJsonStructure(['id', 'status', 'credits_cap']);
        $this->assertSame('reading', $created->json('status'));
        $this->assertTrue(Dec::of($created->json('credits_cap'))->isPositive());

        $slip = SlipRead::withoutGlobalScopes()->findOrFail($created->json('id'));
        $read = $this->readOf($slip);
        $this->assertSame([SlipReadKind::KEY, DocumentRead::RUNNING, $this->project->id, $store->id], [$read->kind, $read->status, $read->project_id, $read->user_id]);
        $this->assertSame(SlipRead::READING, $slip->status);
        $this->assertCount(1, $this->queuedPages());

        $this->runPages($slip);

        // One call: the stored picture, refused rather than shrunk, with no tools, no thinking and a form with no money field.
        $this->assertCount(1, $this->sent);
        $body = $this->sent[0];
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame(['model', 'max_tokens', 'system', 'messages', 'output_config'], array_keys($body));
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
        $this->assertSame(['oversized_image' => 'error'], $body['messages'][0]['content'][0]['transformations']);
        $schema = json_encode($body['output_config']['format']['schema']);

        foreach (['rate', 'amount', 'driver', 'phone', 'total'] as $word) {
            $this->assertStringNotContainsString('"'.$word, $schema, "The form has no {$word} field.");
        }

        $this->assertStringNotContainsString($store->name, json_encode($body));
        $this->assertStringNotContainsString($this->project->name, json_encode($body));

        $slip->refresh();
        $this->assertSame(SlipRead::READY, $slip->status);
        $this->assertSame(DocumentRead::READY, $this->readOf($slip)->status);
        $this->assertTrue(Dec::of($this->readOf($slip)->credits_used)->isPositive());

        $shown = $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk();
        $shown->assertJsonPath('status', 'ready')->assertJsonPath('kind', 'challan')->assertJsonPath('doc_kind', 'delivery_challan')
            ->assertJsonPath('fields.challan_no.value', 'DC-0457')
            ->assertJsonPath('fields.challan_date.value', '2026-11-11')
            ->assertJsonPath('fields.vehicle_no.value', 'MH12AB1234')
            ->assertJsonPath('fields.supplier_gstin.value', self::GSTIN)
            ->assertJsonPath('lines.0.qty.value', '120.000')
            ->assertJsonPath('lines.0.unit.value', 'bag')
            ->assertJsonPath('lines.0.unit.unit_id', $this->bag->id)
            ->assertJsonPath('suggestions.party.id', $this->vendor->id)
            ->assertJsonPath('suggestions.party.by', 'gstin')
            ->assertJsonPath('suggestions.materials.0.id', $this->cement->id)
            ->assertJsonPath('can_recheck', false);

        // What the code parsed stays on the server, and a challan reading has no money in it at all.
        $this->assertArrayNotHasKey('parsed', $shown->json('fields.challan_no'));
        $this->assertDoesNotMatchRegularExpression('/"(rate|amount|bill_amount|grand_total|taxable_total)"/', $shown->getContent());
    }

    public function test_the_field_app_reads_the_same_way_and_a_repeat_returns_the_same_reading_to_its_person_only(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $this->willAnswer($this->reply($this->challanForm()));
        $uuid = (string) Str::uuid();

        $first = $this->readPhotoFromPhone($engineer, null, ['client_uuid' => $uuid])->assertStatus(202);
        $again = $this->readPhotoFromPhone($engineer, null, ['client_uuid' => $uuid])->assertOk();
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, SlipRead::withoutGlobalScopes()->where('client_uuid', $uuid)->count());
        $this->assertSame('mobile', $this->readOf(SlipRead::withoutGlobalScopes()->findOrFail($first->json('id')))->channel);

        // Someone else of the company sending the same key gets nothing of it.
        $this->readPhotoFromPhone($this->member($this->organization, 'store'), null, ['client_uuid' => $uuid])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        $slip = SlipRead::withoutGlobalScopes()->findOrFail($first->json('id'));
        $this->runPages($slip);

        Sanctum::actingAs($engineer);
        $this->getJson("/api/v1/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('fields.challan_no.value', 'DC-0457');

        // A supplier bill is read on the web only.
        $this->readPhotoFromPhone($engineer, null, ['kind' => 'bill'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_a_refused_hold_leaves_no_reading_and_the_same_request_starts_it_once_credits_are_there(): void
    {
        $store = $this->member($this->organization, 'store');
        $this->setCredits($this->organization, '0');
        $this->willAnswer($this->reply($this->challanForm()));
        $uuid = (string) Str::uuid();

        $this->readPhoto($store, null, ['client_uuid' => $uuid])->assertStatus(402)->assertJsonPath('code', 'no_credits');
        $this->assertSame(0, SlipRead::withoutGlobalScopes()->count());
        $this->assertSame([], $this->queuedPages());

        $this->setCredits($this->organization, '500');
        $id = $this->readPhoto($store, null, ['client_uuid' => $uuid])->assertStatus(202)->json('id');
        $this->assertSame(1, DocumentRead::withoutGlobalScopes()->count(), 'The draft read of the refused try is the one started.');
        $this->assertSame(DocumentRead::RUNNING, $this->readOf(SlipRead::withoutGlobalScopes()->findOrFail($id))->status);
    }

    public function test_a_picture_the_server_cannot_take_is_refused_with_a_sentence_and_nothing_is_sent(): void
    {
        $store = $this->member($this->organization, 'store');

        $this->readPhoto($store, null, ['image' => UploadedFile::fake()->createWithContent('note.jpg', 'not a picture at all')])
            ->assertUnprocessable()->assertJsonPath('code', 'not_an_image')->assertJsonStructure(['message']);

        $this->readPhoto($store, null, ['image' => $this->photo(300, 200)])->assertUnprocessable()->assertJsonPath('code', 'image_too_small');

        $this->assertSame(0, SlipRead::withoutGlobalScopes()->count());
        $this->assertSame([], $this->sent);
    }

    public function test_without_the_companys_consent_nothing_is_read_but_a_finished_reading_can_still_be_opened(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->readyReading($store);

        AiDocumentSettings::for($this->organization)->update(['enabled' => false]);

        $this->readPhoto($store)->assertStatus(409)->assertJsonPath('code', 'consent_required');
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('status', 'ready');
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/discard")->assertOk()->assertJsonPath('status', 'discarded');
    }

    public function test_without_the_challan_reader_deployed_the_route_says_the_readers_are_not_on(): void
    {
        config(['modules.slip_reading.read_kinds' => []]);

        $this->readPhoto($this->member($this->organization, 'store'))->assertStatus(409)->assertJsonPath('code', 'not_released');
        $this->assertSame(0, DocumentRead::withoutGlobalScopes()->count());
    }

    public function test_a_photo_with_nothing_readable_is_not_charged_and_offers_nothing(): void
    {
        $store = $this->member($this->organization, 'store');
        $empty = ['raw' => '', 'state' => 'absent'];
        $slip = $this->readyReading($store, $this->challanForm(['doc_kind' => 'unreadable', 'supplier_name' => $empty, 'supplier_gstin' => $empty, 'challan_no' => $empty, 'challan_date' => $empty, 'vehicle_no' => $empty, 'lines' => []]));

        $this->assertSame('0.0000', (string) $this->readOf($slip)->credits_used);
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('fields.challan_no.value', null)
            ->assertJsonPath('warnings.0.code', 'nothing_read');
    }

    public function test_an_answer_that_cannot_be_used_ends_the_reading_as_failed_without_a_charge(): void
    {
        $store = $this->member($this->organization, 'store');
        $this->willAnswer($this->reply(['doc_kind' => 'delivery_challan', 'lines' => 'not a list']));

        $slip = SlipRead::withoutGlobalScopes()->findOrFail($this->readPhoto($store)->assertStatus(202)->json('id'));
        $this->runPages($slip);

        $this->assertSame(SlipRead::FAILED, $slip->refresh()->status);
        $this->assertSame('0.0000', (string) $this->readOf($slip)->credits_used);
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('status', 'failed')
            ->assertJsonPath('read.message', 'The photo could not be read. You were not charged for it. Type the details yourself.');
    }

    public function test_the_stronger_model_is_offered_only_after_the_weights_do_not_add_up_and_values_the_two_readings_differ_on_are_not_offered(): void
    {
        $store = $this->member($this->organization, 'store');

        // A GSTIN that fails its check is a warning only, never a reason to read again.
        $warned = $this->readyReading($store, $this->challanForm(['supplier_gstin' => $this->cell('27ABCDE1234F1Z9')]));
        $this->actingAs($store)->getJson("/slip-reads/{$warned->id}")->assertOk()->assertJsonPath('can_recheck', false)
            ->assertJsonPath('fields.supplier_gstin.warnings', ['gstin_check_failed'])
            ->assertJsonPath('suggestions.party', null);
        $this->actingAs($store)->postJson("/slip-reads/{$warned->id}/recheck")->assertStatus(409)->assertJsonPath('code', 'not_offered');

        $weights = ['gross_weight' => $this->cell('18,450'), 'tare_weight' => $this->cell('6,200'), 'net_weight' => $this->cell('12,350'), 'weight_unit' => $this->cell('kg'), 'doc_kind' => 'weighbridge_slip', 'lines' => []];
        $slip = $this->readyReading($store, $this->challanForm($weights));

        $first = $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('can_recheck', true)
            ->assertJsonPath('fields.net_weight.value', null)
            ->assertJsonPath('fields.gross_weight.warnings', ['weights_do_not_add_up']);
        $this->assertNotNull($first->json('recheck_credits'));

        // The stronger model reads the gross weight differently, so it adds up now; the challan number is read differently too.
        $this->sent = [];
        $this->willAnswer($this->reply($this->challanForm([...$weights, 'gross_weight' => $this->cell('18,550'), 'challan_no' => $this->cell('DC-0451')]), 'claude-sonnet-5-5'));
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(202)->assertJsonPath('status', 'reading');
        $this->runPages($slip);

        $this->assertSame('claude-sonnet-5-5', $this->sent[0]['model']);
        $second = $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('rechecked', true)
            ->assertJsonPath('can_recheck', false)
            // Both readings agree on tare and net, and the second adds up: those are offered. Gross and the number are not.
            ->assertJsonPath('fields.net_weight.value', '12350.000')
            ->assertJsonPath('fields.tare_weight.value', '6200.000')
            ->assertJsonPath('fields.gross_weight.value', null)
            ->assertJsonPath('fields.challan_no.value', null)
            ->assertJsonPath('fields.vehicle_no.value', 'MH12AB1234');
        $this->assertContains('reads_disagree', $second->json('fields.challan_no.warnings'));
        $this->assertContains('reads_disagree', collect($second->json('warnings'))->pluck('code')->all());

        // Once is all.
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(409);
    }

    public function test_a_second_reading_that_fails_does_not_say_that_nothing_was_charged(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->readyReading($store, $this->challanForm(['gross_weight' => $this->cell('100'), 'tare_weight' => $this->cell('30'), 'net_weight' => $this->cell('60'), 'weight_unit' => $this->cell('kg')]));
        $charged = (string) $this->readOf($slip)->credits_used;
        $this->assertTrue(Dec::of($charged)->isPositive());

        $this->willAnswer($this->reply(['doc_kind' => 'weighbridge_slip', 'lines' => 'not a list'], 'claude-sonnet-5-5'));
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(202);
        $this->runPages($slip);

        $shown = $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('read.message', 'The second reading could not be used. Type the details yourself.');
        $this->assertSame($charged, $shown->json('credits'));
        $this->assertStringNotContainsString('not charged', $shown->getContent());
    }

    public function test_a_page_read_again_through_the_shared_reader_routes_counts_as_the_one_second_reading(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->readyReading($store);
        $read = $this->readOf($slip);
        $page = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->firstOrFail();
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertJsonPath('can_recheck', false);

        // Asked for by hand, around the reading's own "read again", with the challan number read differently.
        $this->willAnswer($this->reply($this->challanForm(['challan_no' => $this->cell('DC-0991')]), 'claude-sonnet-5-5'));
        $this->actingAs($store)->postJson("/document-reads/{$read->id}/pages/{$page->id}/retry", ['stronger' => true, 'cap' => '50'])->assertStatus(202);
        $this->runPages($slip);

        $shown = $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('rechecked', true)
            ->assertJsonPath('can_recheck', false)
            ->assertJsonPath('fields.challan_no.value', null)
            ->assertJsonPath('fields.vehicle_no.value', 'MH12AB1234');
        $this->assertContains('reads_disagree', $shown->json('fields.challan_no.warnings'));
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(409);
    }

    public function test_a_reading_that_failed_takes_no_answer_and_no_charge_when_its_page_is_read_again_elsewhere(): void
    {
        $store = $this->member($this->organization, 'store');
        $this->willAnswer($this->reply(['doc_kind' => 'delivery_challan', 'lines' => 'not a list']));
        $slip = SlipRead::withoutGlobalScopes()->findOrFail($this->readPhoto($store)->assertStatus(202)->json('id'));
        $this->runPages($slip);
        $this->assertSame(SlipRead::FAILED, $slip->refresh()->status);

        $read = $this->readOf($slip);
        $page = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->firstOrFail();
        $this->willAnswer($this->reply($this->challanForm()));
        $this->actingAs($store)->postJson("/document-reads/{$read->id}/pages/{$page->id}/retry", ['cap' => '50'])->assertStatus(202);
        $this->runPages($slip);

        $slip->refresh();
        $this->assertSame([SlipRead::FAILED, null, null], [$slip->status, $slip->fields, $slip->rechecked_at]);
        $this->assertSame('0.0000', (string) $this->readOf($slip)->credits_used);
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('status', 'failed');
    }

    public function test_a_company_on_a_trial_is_never_offered_the_stronger_model(): void
    {
        $this->organization->update(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(20)]);
        $store = $this->member($this->organization, 'store');
        $slip = $this->readyReading($store, $this->challanForm(['gross_weight' => $this->cell('100'), 'tare_weight' => $this->cell('30'), 'net_weight' => $this->cell('60')]));

        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('can_recheck', false);
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(409)->assertJsonPath('code', 'not_offered');
    }

    public function test_discarding_a_reading_under_way_cancels_its_read_and_gives_back_the_hold(): void
    {
        $store = $this->member($this->organization, 'store');
        $this->willAnswer($this->reply($this->challanForm()));
        $slip = SlipRead::withoutGlobalScopes()->findOrFail($this->readPhoto($store)->assertStatus(202)->json('id'));

        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/discard")->assertOk()->assertJsonPath('status', 'discarded');

        $read = $this->readOf($slip);
        $this->assertSame(DocumentRead::CANCELLED, $read->status);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertSame(DocumentReadPage::SKIPPED, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->value('status'));

        // A finished reading is closed as discarded.
        $done = $this->readyReading($store);
        $this->actingAs($store)->postJson("/slip-reads/{$done->id}/discard")->assertOk();
        $this->assertSame(DocumentRead::DISCARDED, $this->readOf($done)->status);
    }

    public function test_when_the_reads_files_are_deleted_what_was_read_goes_and_which_fields_were_confirmed_stays(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->storedReading($store, ['status' => SlipRead::USED, 'confirmed' => ['fields' => ['challan_no'], 'typed_over' => [], 'by' => $store->id]]);

        app(SlipReadKind::class)->purge($this->readOf($slip));

        $slip->refresh();
        $this->assertNull($slip->fields);
        $this->assertNull($slip->lines);
        $this->assertNull($slip->warnings);
        $this->assertNull($slip->suggestions);
        $this->assertSame(['challan_no'], $slip->confirmed['fields']);
    }

    public function test_no_row_or_log_keeps_the_photo_itself(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->readyReading($store);
        $page = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $slip->document_read_id)->firstOrFail();
        $base64 = base64_encode((string) Storage::get($page->image_path));

        foreach (['slip_reads', 'document_read_pages', 'audit_logs', 'jobs'] as $table) {
            $this->assertStringNotContainsString(substr($base64, 100, 200), json_encode(DB::table($table)->get()), $table);
        }
    }
}
