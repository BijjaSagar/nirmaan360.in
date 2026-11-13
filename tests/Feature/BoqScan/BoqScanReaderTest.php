<?php

namespace Tests\Feature\BoqScan;

use App\Jobs\DocumentReading\ReadDocumentPage;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\BoqScan\BoqReadKind;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ShapeCheck;
use Illuminate\Support\Facades\Http;

/**
 * The BOQ reader on the page reader, with the provider faked: what each reading asks for, which
 * pages get a second reading, and what is charged. No request leaves the test.
 */
class BoqScanReaderTest extends BoqScanTestCase
{
    /** A provider answer holding this form. */
    private function reply(array $form, int $output = 3000, string $stop = 'end_turn'): array
    {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($form)]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => self::A4_INPUT, 'output_tokens' => $output],
        ];
    }

    private function rowsForm(string $pageKind = 'boq_table'): array
    {
        $cell = fn (string $raw) => ['raw' => $raw, 'state' => $raw === '' ? 'absent' : 'read'];

        return [
            'page_kind' => $pageKind,
            'column_order' => $pageKind === 'boq_table' ? ['item_no', 'description', 'unit', 'qty', 'rate', 'amount'] : [],
            'rows' => $pageKind === 'boq_table' ? [
                ['row_no' => 1, 'kind' => 'item', 'y_top' => 40, 'y_bottom' => 52, 'item_no' => $cell('1'), 'description' => $cell('Excavation in ordinary soil'), 'unit' => $cell('cum'), 'qty' => $cell('120.500'), 'rate' => $cell('450.00')],
            ] : [],
        ];
    }

    private function amountsForm(): array
    {
        return ['rows' => [['row_no' => 1, 'y_top' => 40, 'y_bottom' => 52, 'item_no' => ['raw' => '1', 'state' => 'read'], 'amount' => ['raw' => '54,225.00', 'state' => 'read']]]];
    }

    private function readAt(string $held = '100'): DocumentRead
    {
        return $this->startedRead(held: $held, overrides: ['project_id' => $this->project->id, 'options' => ['language' => 'mr'], 'pages_total' => 2]);
    }

    public function test_the_kind_is_the_registered_boq_reader_and_its_forms_follow_the_rules(): void
    {
        $this->assertInstanceOf(BoqReadKind::class, app(ReadKinds::class)->get('boq'));
        $this->assertSame([], ShapeCheck::schemaProblems(BoqReadKind::rowsSchema()));
        $this->assertSame([], ShapeCheck::schemaProblems(BoqReadKind::amountsSchema()));

        // The first reading has no field for an amount at all; the second has nothing but the item number and the amount.
        $this->assertSame(['row_no', 'kind', 'y_top', 'y_bottom', 'item_no', 'description', 'unit', 'qty', 'rate'], array_keys(BoqReadKind::rowsSchema()['properties']['rows']['items']['properties']));
        $this->assertSame(['row_no', 'y_top', 'y_bottom', 'item_no', 'amount'], array_keys(BoqReadKind::amountsSchema()['properties']['rows']['items']['properties']));
    }

    public function test_the_first_reading_asks_for_no_amount_and_a_table_gets_a_second_reading_of_its_amounts(): void
    {
        $read = $this->readAt();
        $page = $this->storedPage($read, 1);
        $this->fakeReader([$this->reply($this->rowsForm()), $this->reply($this->amountsForm(), 600)]);

        $this->readPage($page);

        $body = $this->sent[0];
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(9000, $body['max_tokens']);
        $this->assertSame(['model', 'max_tokens', 'system', 'messages', 'output_config'], array_keys($body));
        $this->assertStringContainsString('Do not read the amount column. Do not use it to fill or check quantity or rate.', $body['system'][0]['text']);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
        $this->assertSame(['oversized_image' => 'error'], $body['messages'][0]['content'][0]['transformations']);
        $this->assertSame($this->base64Of($page), $body['messages'][0]['content'][0]['source']['data']);
        $this->assertStringContainsString('Main language of the document: Marathi.', $body['messages'][0]['content'][1]['text']);
        $this->assertArrayNotHasKey('amount', $body['output_config']['format']['schema']['properties']['rows']['items']['properties']);

        foreach (['tools', 'tool_choice', 'thinking', 'temperature', 'top_p', 'top_k'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }

        $amounts = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('pass', 'amounts')->sole();
        // It goes to the queue next, as the read's next page.
        $this->assertSame(DocumentReadPage::QUEUED, $amounts->status);
        $this->assertContains($amounts->id, $this->queuedPages());
        $this->assertSame('claude-haiku-5-5', $amounts->model);
        $this->assertSame($page->image_path, $amounts->image_path);

        $row = BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->sole();
        $this->assertSame([$this->project->id, '120.500', '450.00', null], [(int) $row->project_id, $row->qty, $row->rate, $row->amount]);
        // Pixels 40 to 52 of a stored picture 2,296 pixels high, in parts per thousand, rounded outwards.
        $this->assertSame([17, 23], [(int) $row->y_top, (int) $row->y_bottom]);

        $amounts->forceFill(['status' => DocumentReadPage::QUEUED])->save();
        $this->readPage($amounts);

        $second = $this->sent[1];
        $this->assertSame('claude-haiku-5-5', $second['model']);
        $this->assertStringContainsString('Read only the item number column and the amount column', $second['system'][0]['text']);
        $this->assertStringNotContainsString('Excavation', json_encode($second));
        $this->assertSame('54225.00', $row->fresh()->amount);
    }

    public function test_a_page_without_a_table_gets_no_second_reading_and_a_blank_page_is_not_charged(): void
    {
        $read = $this->readAt();
        $other = $this->storedPage($read, 1);
        $blank = $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->reply($this->rowsForm('other')), $this->reply($this->rowsForm('blank'))]);

        $this->readPage($other);
        $blank->forceFill(['status' => DocumentReadPage::QUEUED])->save();
        $this->readPage($blank);

        $this->assertSame(0, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('pass', 'amounts')->count());
        $this->assertSame('0.0000', $blank->fresh()->credits);
        $this->assertGreaterThan(0, (float) $other->fresh()->credits);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame(DocumentReadPage::READ, $blank->fresh()->status);
    }

    public function test_an_unusable_answer_is_not_charged_and_its_cost_comes_off_the_hold(): void
    {
        $read = $this->readAt();
        $page = $this->storedPage($read, 1);
        $this->fakeReader([$this->reply(['page_kind' => 'boq_table', 'rows' => 'not a list'])]);

        $this->readPage($page);

        $this->assertSame(DocumentReadPage::FAILED, $page->fresh()->status);
        $this->assertSame('invalid_output', $page->fresh()->fail_reason);
        $this->assertSame([], $this->usageLines($this->organization));
        $this->assertGreaterThan(0, (float) $read->fresh()->credits_burned);
        $this->assertLessThan(100, (float) $read->fresh()->credits_held);
        $this->assertSame(0, BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->count());
    }

    public function test_a_page_delivered_twice_is_sent_and_charged_once(): void
    {
        $read = $this->readAt();
        $page = $this->storedPage($read, 1);
        $this->fakeReader([$this->reply($this->rowsForm())]);

        $this->readPage($page);
        $this->readPage($page);
        (new ReadDocumentPage($page->id))->handle();

        Http::assertSentCount(1);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame(1, BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->count());
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_a_page_read_again_replaces_its_rows_and_keeps_rows_added_by_hand(): void
    {
        $read = $this->readAt();
        $page = $this->storedPage($read, 1);
        $this->fakeReader([$this->reply($this->rowsForm())]);
        $this->readPage($page);

        BoqScanRow::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'document_read_id' => $read->id,
            'page_no' => 1, 'sort' => 100150, 'kind' => 'item', 'description' => 'Typed from the page', 'typed' => ['description'],
        ]);

        $page->forceFill(['status' => DocumentReadPage::QUEUED])->save();
        $this->readPage($page);

        $this->assertSame(2, BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->count());
        $this->assertSame(1, BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->whereNull('kind_read')->count());
    }

    public function test_a_cut_off_table_is_offered_as_halves_and_a_cut_off_amount_column_just_fails(): void
    {
        $kind = app(ReadKinds::class)->get('boq');
        $read = $this->readAt();

        $this->assertSame('offer_halves', $kind->onCutOff($this->makePage($read, 1)));
        $this->assertSame('fail', $kind->onCutOff($this->makePage($read, 1, ['pass' => 'amounts'])));
        $this->assertSame(['all' => ['boq.manage', 'money.view'], 'any' => []], $kind->abilities($read));
        $this->assertSame(['max_pages' => 60, 'min_short_side' => 600], $kind->limits());
    }

    public function test_the_dry_run_page_shows_quantity_times_rate_against_the_second_amount(): void
    {
        $kind = app(BoqReadKind::class);
        $checks = $kind->pageChecks(['rows' => $this->rowsForm(), 'amounts' => ['rows' => [['row_no' => 1, 'y_top' => 40, 'y_bottom' => 52, 'item_no' => ['raw' => '1', 'state' => 'read'], 'amount' => ['raw' => '54,000.00', 'state' => 'read']]]]]);

        $this->assertSame([true, false, true], array_column($checks, 'ok'));
        $this->assertSame(['qty' => 'rows.qty', 'rate' => 'rows.rate', 'unit' => 'rows.unit'], array_flip($kind->cellKinds('rows')));
        $this->assertSame(['rows.amount' => 'amount'], $kind->cellKinds('amounts'));
    }

    public function test_when_files_are_purged_the_staged_rows_go_with_them(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);

        app(ReadKinds::class)->get('boq')->purge($read);

        $this->assertSame(0, BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->count());
    }
}
