<?php

namespace Tests\Feature\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\PageCall;
use App\Services\DocumentReading\PageOutcome;
use App\Services\DocumentReading\ReadAccess;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadKinds;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\FakeRetention;
use Tests\Feature\DocumentReading\Support\FakeSlipKind;
use Tests\Feature\DocumentReading\Support\FakeTextKind;

/** How readers are registered, where a read keeps its files, and the single call both a read and a dry run go through. */
class ReadKindsAndFilesTest extends PageReadingTestCase
{
    public function test_kinds_are_collected_from_every_module_s_config(): void
    {
        $kinds = app(ReadKinds::class);

        $this->assertSame(['boq', 'tender', 'slip'], array_keys($kinds->all()));
        $this->assertInstanceOf(FakeKind::class, $kinds->get('boq'));
        $this->assertInstanceOf(FakeTextKind::class, $kinds->find('tender'));
        $this->assertNull($kinds->find('bill'));
        $this->assertNull($kinds->find(null));
        $this->assertTrue($kinds->any());

        // Listing the same class in two modules is harmless.
        config(['modules.another_module.read_kinds' => [FakeSlipKind::class]]);
        $this->assertCount(3, app(ReadKinds::class)->all());

        $this->expectException(LogicException::class);
        $kinds->get('bill');
    }

    public function test_with_no_module_deployed_there_is_no_reader(): void
    {
        config(['modules.reader_stand_ins.read_kinds' => []]);

        $this->assertFalse(app(ReadKinds::class)->any());
        $this->assertSame([], app(ReadKinds::class)->all());
    }

    public function test_the_shipped_configuration_registers_no_reader_by_itself(): void
    {
        $shipped = collect(config('modules'))->except('reader_stand_ins')->pluck('read_kinds')->filter();

        // This slice adds the machinery only. A feature module brings its reader with it.
        foreach ($shipped->flatten() as $class) {
            $this->assertTrue(is_subclass_of($class, ReadKind::class), $class);
        }

        $this->assertIsArray(config('modules.document_reading.queues'));
        $this->assertSame(['pages', 'interactive', 'prepare'], array_keys(config('modules.document_reading.queues')));

        foreach (config('modules.document_reading.kinds') as $kind => $figures) {
            $this->assertContains($figures['queue'], ['pages', 'interactive'], $kind);
            $this->assertIsBool($figures['notify'], $kind);
        }
    }

    public function test_a_class_that_is_not_a_reader_two_readers_under_one_name_and_a_pass_without_figures_are_all_refused(): void
    {
        config(['modules.reader_stand_ins.read_kinds' => [FakeRetention::class]]);

        try {
            app(ReadKinds::class)->all();
            $this->fail('A class that is not a reader was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('does not implement ReadKind', $exception->getMessage());
        }

        $twin = new class extends FakeKind {};
        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class, $twin::class]]);

        try {
            app(ReadKinds::class)->all();
            $this->fail('Two readers under one name were accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Two reader kinds', $exception->getMessage());
        }

        $extraPass = new class extends FakeKind
        {
            public function passes(): array
            {
                return ['rows', 'totals'];
            }
        };
        config(['modules.reader_stand_ins.read_kinds' => [$extraPass::class]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no figures for its pass "totals"');
        app(ReadKinds::class)->all();
    }

    public function test_access_needs_every_listed_permission_and_one_of_each_group(): void
    {
        $read = $this->startedRead();
        $kind = new FakeKind;
        $engineer = $this->member($this->organization, 'engineer');
        $viewer = $this->member($this->organization, 'viewer');
        $outsider = $this->member($this->tenant('Narmada Projects'), 'owner');

        FakeKind::$abilities = ['all' => ['boq.view'], 'any' => []];
        $this->assertTrue(ReadAccess::holds($engineer, $read, $kind));
        $this->assertTrue(ReadAccess::holds($viewer, $read, $kind));
        // The owner of another company holds every permission there, and nothing here.
        $this->assertFalse(ReadAccess::holds($outsider, $read, $kind));

        FakeKind::$abilities = ['all' => ['boq.view', 'money.view'], 'any' => []];
        $this->assertFalse(ReadAccess::holds($engineer, $read, $kind));
        $this->assertTrue(ReadAccess::holds($this->owner, $read, $kind));

        FakeKind::$abilities = ['all' => [], 'any' => [['money.view', 'dpr.create'], ['boq.view']]];
        $this->assertTrue(ReadAccess::holds($engineer, $read, $kind));
        $this->assertFalse(ReadAccess::holds($viewer, $read, $kind));
    }

    public function test_a_read_s_files_live_in_its_own_folder_under_its_company(): void
    {
        $read = $this->startedRead();
        $folder = "org-{$this->organization->id}/document-reads/{$read->id}";

        $this->assertSame($folder, ReadFiles::directory($read));
        $this->assertSame("{$folder}/p3-rows-full.jpg", ReadFiles::page($read, 3, 'rows'));
        $this->assertSame("{$folder}/p3-rows-top.jpg", ReadFiles::page($read, 3, 'rows', 'top'));
        $this->assertSame("{$folder}/source.pdf", ReadFiles::source($read));
        $this->assertSame("{$folder}/text.json", ReadFiles::text($read));
        // A pass or part name can never carry a path.
        $this->assertSame("{$folder}/p3-etcpasswd-x.jpg", ReadFiles::page($read, 3, '../../etc/passwd', '..'));

        $this->assertTrue(ReadFiles::owns($read, "{$folder}/p1-rows-full.jpg"));
        $this->assertFalse(ReadFiles::owns($read, "{$folder}/../{$read->id}9/p1-rows-full.jpg"));
        $this->assertFalse(ReadFiles::owns($read, "{$folder}9/p1-rows-full.jpg"));
        $this->assertFalse(ReadFiles::owns($read, 'org-999/document-reads/1/p1-rows-full.jpg'));
        $this->assertFalse(ReadFiles::owns($read, null));
    }

    public function test_extracted_text_is_stored_by_page_and_read_back_for_a_run(): void
    {
        $read = $this->startedRead(overrides: ['kind' => 'tender']);

        ReadFiles::putTexts($read, [1 => 'पृष्ठ एक: बयाना राशि', 2 => 'Page two', 3 => '']);

        $this->assertSame(ReadFiles::text($read), $read->fresh()->text_path);
        $this->assertSame(['pages' => ['1' => 'पृष्ठ एक: बयाना राशि', '2' => 'Page two', '3' => '']], json_decode(Storage::get($read->text_path), true));
        $this->assertSame([2 => 'Page two', 3 => ''], ReadFiles::texts($read, 2, 3));
        $this->assertSame([1 => 'पृष्ठ एक: बयाना राशि'], ReadFiles::texts($read, 1, 1));

        try {
            ReadFiles::texts($read, 3, 4);
            $this->fail('A page with no stored text was read.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The document text has no page 4.', $exception->getMessage());
        }

        // A path outside the read's own folder is never opened.
        $read->forceFill(['text_path' => 'org-1/document-reads/999/text.json'])->save();
        $this->expectException(RuntimeException::class);
        ReadFiles::texts($read, 1, 1);
    }

    public function test_deleting_a_read_s_folder_leaves_its_neighbours(): void
    {
        $read = $this->startedRead();
        $page = $this->storedPage($read, 1);
        $neighbour = $this->storedPage($this->startedRead(), 1);

        $this->assertTrue(ReadFiles::delete($read));
        $this->assertTrue(ReadFiles::delete($read));

        $this->assertFalse(Storage::exists($page->image_path));
        $this->assertTrue(Storage::exists($neighbour->image_path));
    }

    public function test_one_call_judges_an_answer_without_saving_or_charging_anything(): void
    {
        // A page that was never saved, as the platform admin's dry run would use.
        $page = new DocumentReadPage(['page_no' => 1, 'pass' => 'rows', 'part' => 'full', 'model' => 'claude-sonnet-5-5']);
        $page->setRelation('documentRead', new DocumentRead(['kind' => 'boq', 'pages_total' => 1]));
        $this->fakeReader([$this->answer('table', 5000, 800)]);

        $outcome = app(PageCall::class)->run(new FakeKind, $page, $this->jpeg(40, 56), null, ['model' => 'claude-opus-5-5', 'max_tokens' => 3000, 'effort' => 'medium', 'timeout' => 60]);

        $this->assertSame(PageOutcome::READ, $outcome->result);
        $this->assertSame('table', $outcome->output['page_kind']);
        $this->assertSame(['input' => 5000, 'output' => 800, 'cache_read' => 0, 'cache_write' => 0], $outcome->usage);
        $this->assertSame('req_test_1', $outcome->requestId);
        $this->assertTrue($outcome->sent);

        $this->assertSame(['claude-opus-5-5', 3000, 'medium'], [$this->sent[0]['model'], $this->sent[0]['max_tokens'], $this->sent[0]['output_config']['effort']]);
        $this->assertSame(60, $this->sentOptions[0]['timeout']);

        $this->assertSame(0, DocumentReadPage::withoutGlobalScopes()->count());
        $this->assertSame(0, DocumentRead::withoutGlobalScopes()->count());
        $this->assertSame([], $this->usageLines($this->organization));
        $this->assertSame([], FakeKind::$staged);
    }

    public function test_a_call_carries_a_picture_or_text_never_both_and_never_neither(): void
    {
        $page = new DocumentReadPage(['page_no' => 1, 'pass' => 'rows', 'part' => 'full', 'model' => 'claude-sonnet-5-5']);
        $page->setRelation('documentRead', new DocumentRead(['kind' => 'boq', 'pages_total' => 1]));
        $this->fakeReader([$this->answer()]);

        foreach ([[null, null], ['bytes', 'text']] as [$picture, $text]) {
            try {
                app(PageCall::class)->run(new FakeKind, $page, $picture, $text);
                $this->fail('A call with both or neither was made.');
            } catch (LogicException) {
                // Expected.
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_body_a_kind_built_wrongly_never_leaves_the_server(): void
    {
        $careless = new class extends FakeKind
        {
            public function request(DocumentReadPage $page, ?string $imageBase64, ?string $text): array
            {
                return [...parent::request($page, $imageBase64, $text), 'tools' => [['name' => 'save_boq', 'input_schema' => ['type' => 'object']]], 'temperature' => 0];
            }
        };
        config(['modules.reader_stand_ins.read_kinds' => [$careless::class]]);

        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        Http::assertNothingSent();
        $this->assertSame('internal_error', $page->fresh()->fail_reason);
        $this->assertSame([], $this->audits('ai.page_sent'));
    }

    public function test_every_way_a_call_can_fail_has_one_outcome(): void
    {
        $from = fn (string $reason, ?int $retryAfter = null) => PageOutcome::fromFailure(new ModelGatewayException($reason, 'x', 400, 'some_error', null, 'req_9', $retryAfter, 'The provider said this.'), 1500);

        $this->assertSame([PageOutcome::FAILED, 'timeout', true], [$from('timeout')->result, $from('timeout')->reason, $from('timeout')->sent]);
        $this->assertSame([PageOutcome::AGAIN, 'rate_limited', 20], [$from('rate_limited', 20)->result, $from('rate_limited', 20)->reason, $from('rate_limited', 20)->retryAfter]);
        $this->assertSame([PageOutcome::AGAIN, 'unavailable', 1500], [$from('unavailable')->result, $from('unavailable')->reason, $from('unavailable')->latencyMs]);

        foreach (['spend_limit', 'billing', 'rejected_key'] as $reason) {
            $this->assertSame([PageOutcome::BLOCKED, $reason, true, null], [$from($reason)->result, $from($reason)->reason, $from($reason)->sent, $from($reason)->providerMessage]);
        }

        $this->assertSame([PageOutcome::BLOCKED, 'not_connected', false], [$from('not_connected')->result, $from('not_connected')->reason, $from('not_connected')->sent]);

        foreach (['bad_request', 'too_large'] as $reason) {
            $this->assertSame([PageOutcome::FAILED, 'rejected', 'some_error 400', 'The provider said this.', 'req_9'], [$from($reason)->result, $from($reason)->reason, $from($reason)->detail, $from($reason)->providerMessage, $from($reason)->requestId]);
        }

        $this->assertTrue((new PageOutcome(PageOutcome::FAILED, 'cut_off'))->failedByBehaviour());
        $this->assertFalse((new PageOutcome(PageOutcome::FAILED, 'timeout'))->failedByBehaviour());
        $this->assertFalse((new PageOutcome(PageOutcome::READ))->failedByBehaviour());
    }
}
