<?php

namespace Tests\Feature\TenderReading;

use App\Jobs\TenderReading\PrepareTenderText;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\TenderReading\TenderReads;
use App\Services\TenderReading\TenderTextPreparer;
use App\Support\PlatformSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Who may start, answer and watch a tender read, from which company, and how often. */
class TenderReadRoutesTest extends TenderReadingTestCase
{
    public function test_starting_a_read_needs_crm_manage_and_agents_use(): void
    {
        $document = $this->storeDocument($this->tender);

        // qs holds crm.view and agents.use, but not crm.manage.
        $this->startRead($this->qs, $document)->assertForbidden();
        $this->startRead($this->member($this->organization, 'engineer'), $document)->assertForbidden();

        $this->startRead($this->pm, $document)->assertCreated();
        Queue::assertPushed(PrepareTenderText::class, 1);
    }

    public function test_a_repeated_client_uuid_gives_back_the_first_read(): void
    {
        $document = $this->storeDocument($this->tender);
        $uuid = (string) Str::uuid();

        $first = $this->startRead($this->owner, $document, null, ['client_uuid' => $uuid])->assertCreated()->json('id');
        $again = $this->startRead($this->owner, $document, null, ['client_uuid' => $uuid])->assertOk()->json('id');

        $this->assertSame($first, $again);

        // The same key sent for another document stands for nothing there.
        $this->startRead($this->owner, $this->storeDocument($this->tender, null, 'other.pdf'), null, ['client_uuid' => $uuid])
            ->assertStatus(422)->assertJsonValidationErrors(['client_uuid' => 'Record not found on the server.']);

        $this->assertSame(1, DocumentRead::withoutGlobalScopes()->count());
        Queue::assertPushed(PrepareTenderText::class, 1);
    }

    public function test_a_form_post_is_taken_to_the_reading_screen(): void
    {
        $document = $this->storeDocument($this->tender);

        $response = $this->actingAs($this->owner)->post(route('tender-reads.store', ['tender' => $this->tender, 'document' => $document]), ['client_uuid' => (string) Str::uuid()]);
        $read = DocumentRead::withoutGlobalScopes()->sole();

        $response->assertRedirect(route('tender-reads.view', $read));

        // With a key saved, the prepare sends each run's text to be counted, and the screen says so.
        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('Taking the text out of the PDF, page by page.')
            ->assertSee('The text is sent to the AI service only to count its length, so the price is close. Nothing is read in this step.')
            ->assertDontSee('Nothing is sent to the AI service in this step.');

        app(TenderTextPreparer::class)->prepare($read->id);
        $this->assertGreaterThan(0, $this->counted, 'The prepare did send the text to be counted.');

        // A key removed after the read was opened: nothing is counted, and nothing is sent.
        $second = $this->startRead($this->owner, $this->storeDocument($this->tender, null, 'second.pdf'))->assertCreated()->json('id');
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        $counted = $this->counted;
        $this->actingAs($this->owner)->get(route('tender-reads.view', $second))->assertOk()
            ->assertSee('Nothing is sent to the AI service in this step.')
            ->assertDontSee('only to count its length');
        app(TenderTextPreparer::class)->prepare($second);
        $this->assertSame($counted, $this->counted);
    }

    public function test_a_document_of_another_tender_is_not_found(): void
    {
        $other = $this->makeTender($this->organization, 'Second sample tender');
        $document = $this->storeDocument($other);

        $this->startRead($this->owner, $document, $this->tender)->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_another_companys_tender_read_and_sheet_are_not_found(): void
    {
        $read = $this->preparedRead();
        $other = $this->tenant('Godavari Infra');
        $outsider = $this->member($other, 'owner');
        $this->consent($other);

        $this->actingAs($outsider)->get(route('tender-reads.view', $read))->assertNotFound();
        $this->actingAs($outsider)->post(route('tender-reads.mode', $read), ['language' => 'en', 'matches' => 'yes'])->assertNotFound();
        $this->actingAs($outsider)->get(route('tender-facts.index', $this->tender))->assertNotFound();
        $this->startRead($outsider, $this->storeDocument($this->tender))->assertNotFound();

        $this->assertNull($read->refresh()->options['language'] ?? null);
    }

    public function test_a_read_of_another_kind_is_not_shown_here(): void
    {
        $read = DocumentRead::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'kind' => 'boq', 'status' => DocumentRead::DRAFT, 'user_id' => $this->owner->id]);

        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertNotFound();
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'en', 'matches' => 'yes'])->assertNotFound();
    }

    public function test_qs_can_watch_a_read_but_cannot_answer_for_it(): void
    {
        $read = $this->preparedRead();

        $this->actingAs($this->qs)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('Text of page 1')
            ->assertDontSee('name="language"', false);
        $this->actingAs($this->qs)->post(route('tender-reads.mode', $read), ['language' => 'en', 'matches' => 'yes'])->assertForbidden();

        // Someone without crm.view does not see the reading screen at all.
        foreach (['engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->organization, $role))->get(route('tender-reads.view', $read))->assertForbidden();
        }
    }

    public function test_only_someone_who_may_start_a_read_can_send_its_pages_price_start_continue_or_cancel_it(): void
    {
        $read = $this->preparedRead();
        $this->answer($read)->assertRedirect();
        $accounts = $this->member($this->organization, 'accounts');

        // qs and accounts hold crm.view and agents.use, not crm.manage: every shared step of a tender read is refused them.
        foreach ([$this->qs, $accounts] as $watcher) {
            $this->actingAs($watcher)->postJson(route('document-reads.quote', $read))->assertForbidden();
            $this->actingAs($watcher)->postJson(route('document-reads.start', $read))->assertForbidden();
            $this->actingAs($watcher)->postJson(route('document-reads.pages.store', $read), ['page_no' => 1])->assertForbidden();
            $this->actingAs($watcher)->getJson(route('document-reads.status', $read))->assertForbidden();
            $this->actingAs($watcher)->postJson(route('document-reads.cancel', $read))->assertForbidden();
        }

        $this->assertNull($read->refresh()->started_at);

        $this->actingAs($this->pm)->postJson(route('document-reads.quote', $read))->assertOk();
        $this->actingAs($this->pm)->postJson(route('document-reads.start', $read))->assertStatus(202);
        $this->assertSame($this->pm->id, (int) $read->refresh()->user_id);

        DocumentRead::withoutGlobalScopes()->whereKey($read->id)->update(['status' => DocumentRead::PAUSED, 'pause_reason' => DocumentRead::PAUSE_CREDIT_CAP]);

        foreach ([$this->qs, $accounts] as $watcher) {
            $this->actingAs($watcher)->postJson(route('document-reads.resume', $read))->assertForbidden();
            $this->actingAs($watcher)->postJson(route('document-reads.cancel', $read))->assertForbidden();
        }

        $this->assertSame(DocumentRead::PAUSED, $read->refresh()->status);
        $this->actingAs($this->owner)->postJson(route('document-reads.cancel', $read))->assertOk();
    }

    public function test_a_paused_read_is_continued_from_the_reading_screen(): void
    {
        $read = $this->preparedRead();
        $this->answer($read);
        $this->startReading($read);
        DocumentRead::withoutGlobalScopes()->whereKey($read->id)->update(['status' => DocumentRead::PAUSED, 'pause_reason' => DocumentRead::PAUSE_CREDIT_CAP]);

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('The reading is paused.');
        $page->assertSee('Continue reading');
        $page->assertSee('window.ReaderCapture.resume('.$read->id, false);
        $page->assertSee('Continue with this limit');

        // Someone who only watches is told why it paused, and is offered nothing to press.
        $this->actingAs($this->qs)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('It reached the credit limit set for this document.')
            ->assertDontSee('Continue reading')
            ->assertDontSee('ReaderCapture.resume', false);

        // What the button calls: the read goes on from where it stopped.
        $this->actingAs($this->owner)->postJson(route('document-reads.resume', $read))->assertStatus(202)->assertJson(['status' => DocumentRead::RUNNING]);
    }

    public function test_a_finished_read_with_pages_that_failed_offers_to_read_them_again(): void
    {
        $read = $this->preparedRead();
        $this->answer($read);
        $this->startReading($read);
        DocumentRead::withoutGlobalScopes()->whereKey($read->id)->update(['status' => DocumentRead::READY, 'pages_failed' => 3]);

        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('Reading finished.')
            ->assertSee('window.ReaderCapture.retry('.$read->id, false)
            ->assertSee('These pages could not be read. Each can be read again.');
        $this->actingAs($this->qs)->get(route('tender-reads.view', $read))->assertOk()
            ->assertDontSee('ReaderCapture.retry', false);
    }

    public function test_a_page_range_on_a_text_read_is_read_from_its_first_page_to_its_last(): void
    {
        $read = $this->preparedRead();
        $rows = fn () => DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('pass', 'text')
            ->orderBy('page_no')->get()->map(fn (DocumentReadPage $row) => [(int) $row->page_no, (int) $row->page_to, $row->status])->all();
        $this->assertSame([[1, 3, 0]], array_map(fn (array $run) => [$run[0], $run[1], 0], $read->options['runs']), 'The notice is one run of its three pages.');

        // A range that starts inside the run: the run is cut at the range, and only pages 2 and 3 are priced and read.
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'en', 'matches' => 'yes', 'page_from' => 2, 'page_to' => 3])->assertRedirect();
        $this->assertSame([[2, 3, 'waiting']], $rows());
        $row = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->sole();
        $this->assertSame(mb_strlen(implode("\n", self::NOTICE[1])) + mb_strlen(implode("\n", self::NOTICE[2])), (int) $row->text_chars);
        $this->assertSame(0, (int) $row->input_tokens, 'The count was for the whole run; the part is estimated.');

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('Pages to read as text: 2');

        // A range that ends inside the run: page 3 is not sent and not charged.
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'en', 'matches' => 'yes', 'page_from' => 1, 'page_to' => 2])->assertRedirect();
        $this->assertSame([[1, 2, 'waiting']], $rows());

        // Back to the start of the range test: what the screen sends to the shared quote and start.
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'en', 'matches' => 'yes', 'page_from' => 2, 'page_to' => 3])->assertRedirect();
        $quote = $this->actingAs($this->owner)->postJson(route('document-reads.quote', $read), ['page_from' => 2, 'page_to' => 3])->assertOk();
        $this->assertSame(1, $quote->json('calls'));
        $this->assertTrue($quote->json('enough'));

        $this->fakeReader([$this->reply([$this->fact('emd_amount', 'Rs. 61,500/-', 2, 'Earnest Money Deposit: Rs. 61,500/- by online payment')])]);
        $this->actingAs($this->owner)->postJson(route('document-reads.start', $read), ['page_from' => 2, 'page_to' => 3, 'skip_pages' => []])->assertStatus(202);
        $this->readAll($read);

        $this->assertCount(1, $this->sent);
        $sent = json_encode($this->sent[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Earnest Money Deposit', $sent);
        $this->assertStringContainsString('Bid validity', $sent);
        $this->assertStringNotContainsString('Office of the Executive Engineer', $sent, 'Page 1 is outside the range.');
        $this->assertSame([2], $this->facts()->pluck('page')->map(fn ($page) => (int) $page)->all());

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('Page 1 was not read, because it was not sent to be read.');

        // Only the last page: the two before it are said to be not read, in a sentence for several pages.
        $last = $this->preparedRead($this->storeDocument($this->tender, null, 'notice-copy.pdf'));
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $last), ['language' => 'en', 'matches' => 'yes', 'page_from' => 3, 'page_to' => 3])->assertRedirect();
        $this->actingAs($this->owner)->postJson(route('document-reads.start', $last), ['page_from' => 3, 'page_to' => 3, 'skip_pages' => []])->assertStatus(202);
        $this->readAll($last);

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('Pages not read: 1–2, because they were not sent to be read.');
    }

    public function test_the_questions_have_no_answer_chosen_in_advance(): void
    {
        $read = $this->preparedRead();

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('Does the text on the left match the page on the right?');
        $page->assertSee('name="language" value="mr"', false);
        $this->assertStringNotContainsString('checked', (string) $page->getContent());

        // Both answers are needed.
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), [])->assertSessionHasErrors([
            'language' => 'Choose the language the document is written in.',
            'matches' => 'Say whether the text on the left matches the page on the right.',
        ]);
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'gu', 'matches' => 'maybe'])->assertSessionHasErrors(['language', 'matches']);
    }

    public function test_answering_no_or_marathi_for_latin_text_sets_every_page_aside_for_pictures(): void
    {
        $read = $this->preparedRead();
        $waiting = fn () => DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('status', 'waiting')->count();

        $this->answer($read, 'en', 'no')->assertRedirect(route('tender-reads.view', $read));
        $this->assertSame([1, 2, 3], TenderReads::picturePages($read->refresh()));
        $this->assertSame(0, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->count(), 'No run is left that could still send the text.');
        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('No page of this document can be read as text.')
            ->assertSee('Pages that can only be read as page pictures: 1, 2, 3');

        // The answers can be changed until the read starts.
        $this->answer($read, 'en', 'yes');
        $this->assertSame(1, $waiting());
        $this->assertSame([], TenderReads::picturePages($read->refresh()));

        // The text is in Latin letters, so it is not the printed Marathi.
        $this->answer($read, 'mr', 'yes');
        $this->assertSame([1, 2, 3], TenderReads::picturePages($read->refresh()));
        $this->assertSame(0, $waiting());

        $this->answer($read, 'en', 'yes');
        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('Pages to read as text: 3');
        $page->assertSee('Start reading');
    }

    public function test_a_read_that_has_started_cannot_be_answered_again(): void
    {
        $read = $this->preparedRead();
        $this->answer($read);
        $this->startReading($read);

        $this->answer($read, 'en', 'no')->assertSessionHasErrors(['tender_read']);
        $this->assertTrue($read->refresh()->options['matches']);
    }

    public function test_a_company_whose_plan_has_no_ai_sees_the_sheet_but_cannot_start_a_read(): void
    {
        $this->organization->update(['plan' => 'contractor']);
        $document = $this->storeDocument($this->tender);

        $this->startRead($this->owner, $document)->assertForbidden();
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertSee('Nothing has been read from this tender yet.');
        $this->actingAs($this->owner)->get(route('crm.tenders.show', $this->tender))->assertOk()->assertDontSee('Read this document');
    }

    public function test_without_consent_or_with_the_reader_not_deployed_nothing_starts(): void
    {
        $document = $this->storeDocument($this->tender);

        $this->consent($this->organization, false);
        $this->startRead($this->owner, $document)->assertStatus(409)->assertJson(['code' => 'consent_required']);

        $this->consent($this->organization);
        config(['modules.tender_reading.read_kinds' => []]);
        $this->startRead($this->owner, $document)->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_one_prepare_at_a_time_and_ten_an_hour(): void
    {
        $first = $this->startRead($this->owner, $this->storeDocument($this->tender))->assertCreated()->json('id');

        $this->startRead($this->owner, $this->storeDocument($this->tender, null, 'second.pdf'))->assertStatus(409)->assertJson(['code' => TenderReads::BUSY]);

        app(TenderTextPreparer::class)->prepare($first);

        foreach (range(2, 10) as $number) {
            // A few minutes apart, so the ten-a-minute throttle of the route is not what stops them.
            $this->travel(2)->minutes();
            $id = $this->startRead($this->owner, $this->storeDocument($this->tender, null, "n{$number}.pdf"))->assertCreated()->json('id');
            app(TenderTextPreparer::class)->prepare($id);
        }

        $this->travel(2)->minutes();
        $this->startRead($this->owner, $this->storeDocument($this->tender, null, 'eleventh.pdf'))->assertStatus(409)->assertJson(['code' => TenderReads::BUSY]);

        $this->travel(61)->minutes();
        $this->startRead($this->owner, $this->storeDocument($this->tender, null, 'later.pdf'))->assertCreated();
    }

    public function test_the_plain_per_person_counter_does_not_stop_a_tender_read(): void
    {
        $limiter = app(RateLimiter::class);

        foreach (range(1, 300) as $ignored) {
            $limiter->hit(sha1((string) $this->owner->id), 60);
        }

        $read = $this->preparedRead();
        $this->answer($read)->assertRedirect(route('tender-reads.view', $read));
    }

    public function test_the_tender_page_offers_a_read_beside_each_pdf_only_when_the_reader_is_on(): void
    {
        $pdf = $this->storeDocument($this->tender);
        $this->storeDocument($this->tender, 'plain text', 'notes.txt');

        $page = $this->actingAs($this->owner)->get(route('crm.tenders.show', $this->tender))->assertOk();
        $page->assertSee('Tender reader');
        $page->assertSee(route('tender-reads.store', ['tender' => $this->tender, 'document' => $pdf]), false);
        $this->assertSame(1, substr_count((string) $page->getContent(), 'Read this document'));

        // Someone who may only look sees no button, and with nothing read there is no card at all.
        $this->actingAs($this->qs)->get(route('crm.tenders.show', $this->tender))->assertOk()->assertDontSee('Read this document')->assertDontSee('Tender reader');

        // With the reader not deployed, the tender page is as it was.
        config(['modules.tender_reading.read_kinds' => []]);
        $this->actingAs($this->owner)->get(route('crm.tenders.show', $this->tender))->assertOk()->assertDontSee('Read this document');
    }
}
