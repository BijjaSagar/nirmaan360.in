<?php

namespace Tests\Feature\TenderReading;

use App\Jobs\TenderReading\PrepareTenderText;
use App\Models\CrmTenderDocument;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadTending;
use App\Services\TenderReading\TenderPdfText;
use App\Services\TenderReading\TenderReads;
use App\Services\TenderReading\TenderTextPreparer;
use App\Services\TenderReading\TenderTextRefused;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** Taking the text out of a tender PDF, before anything is sent to be read. */
class PrepareTenderTextTest extends TenderReadingTestCase
{
    public function test_a_typed_pdf_is_prepared_into_runs_of_text_pages_and_nothing_is_read_yet(): void
    {
        $this->fakeReader([$this->reply([])], tokens: 2100);
        $document = $this->storeDocument($this->tender);

        $response = $this->startRead($this->owner, $document)->assertCreated();
        $this->assertSame('draft', $response->json('status'));

        $read = DocumentRead::withoutGlobalScopes()->findOrFail($response->json('id'));
        Queue::assertPushed(PrepareTenderText::class, fn (PrepareTenderText $job) => $job->readId === $read->id);
        $this->assertNull($read->project_id, 'A tender read belongs to the company.');
        $this->assertSame(['tender_id' => $this->tender->id, 'document_id' => $document->id], $read->options);

        app(TenderTextPreparer::class)->prepare($read->id);
        $read->refresh();

        $this->assertSame(DocumentRead::PREPARED, $read->status);
        $this->assertSame(3, (int) $read->pages_total);
        $this->assertSame([], $read->options['page_flags']);
        $this->assertSame([1, 2, 3], $read->options['samples']);

        $texts = ReadFiles::texts($read, 1, 3);
        $this->assertStringContainsString('Notice Inviting Tender No. SWD/77/2026-27', $texts[1]);
        $this->assertSame(self::NOTICE[1], explode("\n", $texts[2]), 'One extracted line per printed line, top to bottom.');

        // One run of three pages, waiting, with its tokens counted for the quote. Nothing was read.
        $rows = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame([1, 3, 'text', 'waiting', 2100], [(int) $rows[0]->page_no, (int) $rows[0]->page_to, $rows[0]->pass, $rows[0]->status, (int) $rows[0]->input_tokens]);
        $this->assertSame(1, $this->counted);
        $this->assertSame([], $this->sent);
    }

    public function test_runs_hold_at_most_eight_pages_or_the_character_limit(): void
    {
        config(['modules.tender_reading.run_chars' => 300]);
        $pages = array_fill(0, 11, ['Clause text that fills the page with an ordinary sentence of the notice', 'and another sentence that goes on about the conditions of the contract']);
        $read = $this->preparedRead($this->storeDocument($this->tender, $this->typedPdf($pages)));

        $runs = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->orderBy('page_no')->get()
            ->map(fn ($row) => [(int) $row->page_no, (int) $row->page_to])->all();

        // About 140 characters a page: two pages fit in 300.
        $this->assertSame([[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [11, 11]], $runs);

        config(['modules.tender_reading.run_chars' => 24000]);
        $read = $this->preparedRead($this->storeDocument($this->tender, $this->typedPdf($pages), 'second.pdf'));
        $runs = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->orderBy('page_no')->get()
            ->map(fn ($row) => [(int) $row->page_no, (int) $row->page_to])->all();
        $this->assertSame([[1, 8], [9, 11]], $runs);
    }

    public function test_a_scanners_text_layer_an_old_font_and_an_empty_page_are_set_aside_for_page_pictures(): void
    {
        $scan = $this->pagePicture().$this->textLines(['Earnest Money Deposit Rs. 1,00,000/-', 'Last date 01-01-2027'], 3);
        $oldFont = $this->textLines(['oaxkfud dke ;kstuk vkSj foLrkj', 'lQkbZ dk;Z gsrq fufonk'], 0, 800, 'F2');
        $empty = $this->pagePicture();
        $typed = $this->textLines(self::NOTICE[1]);

        $pdf = $this->handPdf([$typed, $scan, $oldFont, $empty, $typed], ['fonts' => ['F2' => 'Kruti-Dev-010']]);
        $read = $this->preparedRead($this->storeDocument($this->tender, $pdf));

        $this->assertSame([2 => [TenderPdfText::MACHINE_TEXT], 3 => [TenderPdfText::OLD_FONT], 4 => [TenderPdfText::NO_TEXT]], $read->options['page_flags']);

        // Pages 1 and 5 are each a run of their own: a flagged page is never inside a run.
        $runs = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->orderBy('page_no')->get()
            ->map(fn ($row) => [(int) $row->page_no, (int) $row->page_to])->all();
        $this->assertSame([[1, 1], [5, 5]], $runs);
        $this->assertSame([2, 3, 4], TenderReads::picturePages($read));
    }

    public function test_a_file_named_pdf_that_is_not_one_is_refused_before_anything_is_queued(): void
    {
        $fake = $this->storeDocument($this->tender, "GIF89a\x01\x00\x01\x00not a pdf at all", 'notice.pdf');

        $this->startRead($this->owner, $fake)->assertStatus(422)->assertJson(['code' => TenderReads::NOT_READABLE, 'message' => 'Only PDF and image files can be read.']);

        $picture = $this->storeDocument($this->tender, $this->jpegBytes(), 'site.jpg');
        $this->startRead($this->owner, $picture)->assertStatus(422)->assertJson(['message' => 'This file is a picture. Only PDF files are read here.']);

        config(['modules.tender_reading.max_bytes' => 200]);
        $this->startRead($this->owner, $this->storeDocument($this->tender))->assertStatus(422)->assertJson(['message' => 'Only a PDF of up to 0 MB can be read as text.']);

        Queue::assertNothingPushed();
        $this->assertSame(0, DocumentRead::withoutGlobalScopes()->count());
    }

    public function test_a_second_delivery_of_the_prepare_job_does_nothing(): void
    {
        $this->fakeReader([$this->reply([])]);
        $read = $this->preparedRead();
        $rows = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->pluck('id')->all();
        $counted = $this->counted;

        (new PrepareTenderText($read->id))->handle(app(TenderTextPreparer::class));

        $this->assertSame(DocumentRead::PREPARED, $read->refresh()->status);
        $this->assertSame($rows, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->pluck('id')->all());
        $this->assertSame($counted, $this->counted);
    }

    public function test_without_a_key_the_runs_are_estimated_instead_of_counted(): void
    {
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        Http::fake();

        // Starting needs the service connected; the prepare itself does not.
        $document = $this->storeDocument($this->tender);
        $read = $this->openDraft($document);
        app(TenderTextPreparer::class)->prepare($read->id);

        $this->assertSame(DocumentRead::PREPARED, $read->refresh()->status);
        $this->assertSame(0, (int) DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->value('input_tokens'));
        Http::assertNothingSent();
    }

    public function test_a_pdf_that_cannot_be_read_as_text_fails_with_a_plain_reason(): void
    {
        config(['modules.tender_reading.max_pages' => 2]);
        $read = $this->openDraft($this->storeDocument($this->tender));

        app(TenderTextPreparer::class)->prepare($read->id);
        $read->refresh();

        $this->assertSame(DocumentRead::FAILED, $read->status);
        $this->assertSame(TenderTextRefused::TOO_MANY_PAGES, $read->error);
        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('This PDF has 3 pages. Up to 2 pages can be read as text.');

        // A damaged file.
        $broken = $this->openDraft($this->storeDocument($this->tender, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n", 'broken.pdf'));
        app(TenderTextPreparer::class)->prepare($broken->id);
        $this->assertSame([DocumentRead::FAILED, TenderTextRefused::UNREADABLE], [$broken->refresh()->status, $broken->error]);

        // A document removed from the tender after the read was asked for.
        $gone = $this->openDraft($document = $this->storeDocument($this->tender, null, 'gone.pdf'));
        $document->delete();
        app(TenderTextPreparer::class)->prepare($gone->id);
        $this->assertSame([DocumentRead::FAILED, TenderTextRefused::DOCUMENT_GONE], [$gone->refresh()->status, $gone->error]);

        $this->assertSame(0, DocumentReadPage::withoutGlobalScopes()->count());
    }

    public function test_tending_gives_up_on_a_prepare_that_never_finished(): void
    {
        $read = $this->openDraft($this->storeDocument($this->tender));
        DocumentRead::withoutGlobalScopes()->whereKey($read->id)->update(['status' => DocumentRead::PREPARING, 'prepare_started_at' => now()->subMinutes(5)]);

        app(ReadTending::class)->one($read);

        $this->assertSame(DocumentRead::FAILED, $read->refresh()->status);
        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()->assertSee('This file is too large to read as text. Read it as page pictures.');
    }

    public function test_the_migration_rolls_back_and_forward(): void
    {
        $this->assertTrue(Schema::hasTable('crm_tender_facts'));

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2027_01_13_100001_create_tender_facts_table.php'])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('crm_tender_facts'));

        $this->artisan('migrate', ['--path' => 'database/migrations/2027_01_13_100001_create_tender_facts_table.php'])->assertSuccessful();
        $this->assertTrue(Schema::hasColumns('crm_tender_facts', ['organization_id', 'tender_id', 'document_read_id', 'field', 'value_raw', 'value_norm', 'quote_line', 'quote_found', 'conflict_group', 'page_viewed', 'applied_to']));
    }

    /** A draft read opened as the create route opens it, without going through the route's limits. */
    private function openDraft(CrmTenderDocument $document): DocumentRead
    {
        return DocumentRead::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'kind' => 'tender',
            'status' => DocumentRead::DRAFT,
            'user_id' => $this->owner->id,
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'options' => ['tender_id' => $this->tender->id, 'document_id' => $document->id],
        ]);
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
