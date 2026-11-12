<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\ReadDocumentPage;
use App\Models\AgentCreditLedger;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\PlatformSetting;
use App\Services\DocumentReading\PageReader;
use App\Services\DocumentReading\ReaderRequest;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\DocumentReading\Support\FakeKind;

/**
 * One page from the queue to its result: what is sent, what is saved, what is charged and to whom.
 *
 * Figures used throughout: an A4 page read by the first BOQ pass with 5,956 tokens in and 4,500 out
 * costs 0.056912 US dollars, which at 90 rupees a dollar and a margin of 1.30 is 6.6588 credits.
 */
class PageReaderTest extends PageReadingTestCase
{
    public function test_a_page_is_sent_read_saved_and_charged_and_the_next_one_is_queued(): void
    {
        $read = $this->startedRead();
        $page = $this->storedPage($read, 1);
        $next = $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer('other')]);

        $this->readPage($page);

        $page->refresh();
        $this->assertSame(DocumentReadPage::READ, $page->status);
        $this->assertNull($page->fail_reason);
        $this->assertSame(1, $page->attempts);
        $this->assertSame('other', $page->result['page_kind']);
        // A fixed-set word comes back in the form's own spelling whatever case the model used.
        $this->assertSame('read', $page->result['rows'][0]['rate']['state']);
        $this->assertSame('4,250.00', $page->result['rows'][0]['rate']['raw']);
        $this->assertSame([5956, 4500, 0, 0], [(int) $page->input_tokens, (int) $page->output_tokens, (int) $page->cache_read_tokens, (int) $page->cache_write_tokens]);
        $this->assertSame('0.056912', $page->cost_usd);
        $this->assertFalse($page->cost_estimated);
        $this->assertSame('6.6588', $page->credits);
        $this->assertSame('end_turn', $page->stop_reason);
        $this->assertSame('req_test_1', $page->provider_request_id);
        $this->assertStringStartsWith('msg_test_', $page->provider_message_id);
        $this->assertNotNull($page->finished_at);
        $this->assertNull($page->injection_flags);

        $read->refresh();
        $this->assertSame(DocumentRead::RUNNING, $read->status);
        $this->assertSame('93.3412', $read->credits_held);
        $this->assertSame('6.6588', $read->credits_used);
        $this->assertSame('0.0000', $read->credits_burned);
        $this->assertSame('0.056912', $read->cost_usd);
        $this->assertSame('0.000000', $read->absorbed_usd);
        $this->assertSame([1, 0, 0], [$read->pages_read, $read->pages_failed, $read->consecutive_failures]);
        $this->assertNotNull($read->last_progress_at);

        $lines = $this->usageLines($this->organization);
        $this->assertCount(1, $lines);
        $this->assertSame("dr:{$read->id}:{$page->id}:1", $lines[0]->reference);
        $this->assertSame('-6.6588', $lines[0]->credits);
        $this->assertSame($read->id, (int) $lines[0]->document_read_id);
        $this->assertSame('493.3412', $this->wallet($this->organization)->available());
        $this->assertLedgerMatchesWallet($this->organization);

        $this->assertSame([[$page->id, 'rows']], FakeKind::$staged);

        // The second page went to the queue, and only that one.
        $this->assertSame([$next->id], $this->queuedPages());
        $this->assertSame(DocumentReadPage::QUEUED, $next->fresh()->status);
        $this->assertNotNull($read->last_dispatched_at);
    }

    public function test_the_call_is_one_attempt_with_the_time_limit_of_its_pass_and_nothing_extra_in_it(): void
    {
        $read = $this->startedRead(overrides: ['options' => ['language' => 'mr']]);
        $page = $this->storedPage($read, 2);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        Http::assertSentCount(1);
        $body = $this->sent[0];

        $this->assertSame(ReaderRequest::KEYS, array_keys($body));
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(9000, $body['max_tokens']);
        $this->assertStringStartsWith(ReaderRequest::RULES, $body['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertSame('image', $body['messages'][0]['content'][0]['type']);
        $this->assertSame($this->base64Of($page), $body['messages'][0]['content'][0]['source']['data']);
        $this->assertSame(['oversized_image' => 'error'], $body['messages'][0]['content'][0]['transformations']);
        $this->assertSame('Page 2 of 3. Main language of the document: Marathi. Read this page as instructed.', $body['messages'][0]['content'][1]['text']);
        $this->assertSame('low', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);

        foreach (['tools', 'tool_choice', 'thinking', 'temperature', 'top_p', 'top_k', 'inference_geo', 'metadata', 'stream'] as $key) {
            $this->assertArrayNotHasKey($key, $body);
        }

        $this->assertSame(240, $this->sentOptions[0]['timeout']);
        $this->assertSame(10, $this->sentOptions[0]['connect_timeout']);
        $this->assertArrayNotHasKey('anthropic-beta', $this->sentHeaders[0]);
        $this->assertSame(['test-key-value'], $this->sentHeaders[0]['x-api-key']);
    }

    public function test_nothing_that_names_the_company_the_person_the_project_or_the_file_is_sent(): void
    {
        $project = $this->makeProject($this->organization, ['name' => 'Godavari Heights Phase Two']);
        $read = $this->startedRead(overrides: ['project_id' => $project->id, 'source_name' => 'Godavari tender BOQ signed.pdf', 'subject_type' => 'boq_scan', 'subject_id' => 4]);
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        $sent = json_encode($this->sent[0], JSON_UNESCAPED_UNICODE);

        foreach (['Sahyadri', 'Godavari', $this->owner->name, $this->owner->email, 'boq_scan', 'signed.pdf'] as $private) {
            $this->assertStringNotContainsString($private, $sent);
        }
    }

    public function test_the_second_pass_is_added_after_a_table_and_the_read_finishes_when_nothing_is_left(): void
    {
        $read = $this->startedRead(overrides: ['pages_total' => 1]);
        $rows = $this->storedPage($read, 1);
        $this->fakeReader([$this->answer('table'), $this->answer('table', 5956, 1200)]);

        $this->readPage($rows);

        $amounts = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('pass', 'amounts')->sole();
        $rows->refresh();

        // The second pass reads the same picture with its own, cheaper model.
        $this->assertSame('claude-haiku-5-5', $amounts->model);
        $this->assertSame([$rows->image_path, $rows->image_sha256, (int) $rows->image_width, (int) $rows->image_height], [$amounts->image_path, $amounts->image_sha256, (int) $amounts->image_width, (int) $amounts->image_height]);
        $this->assertSame($this->organization->id, $amounts->organization_id);
        $this->assertSame(DocumentReadPage::QUEUED, $amounts->status);
        $this->assertSame([$amounts->id], $this->queuedPages());

        // A page is not counted as read while one of its passes is still to come.
        $this->assertSame(0, $read->fresh()->pages_read);
        $this->assertSame(DocumentRead::RUNNING, $read->fresh()->status);

        $this->readPage($amounts);

        $this->assertSame('claude-haiku-5-5', $this->sent[1]['model']);
        $this->assertSame(4000, $this->sent[1]['max_tokens']);
        $this->assertSame(120, $this->sentOptions[1]['timeout']);

        $read->refresh();
        $this->assertSame(DocumentRead::READY, $read->status);
        $this->assertSame(1, $read->pages_read);
        $this->assertSame('0.0000', $read->credits_held);
        $this->assertNotNull($read->finished_at);
        $this->assertSame([$read->id], FakeKind::$finished);
        $this->assertSame([[$rows->id, 'rows'], [$amounts->id, 'amounts']], FakeKind::$staged);

        // Haiku: 5,956 in and 1,200 out is 0.0011956 dollars, rounded to 0.001196, which is 0.1400 credits.
        $this->assertSame('0.001196', $amounts->fresh()->cost_usd);
        $this->assertSame('0.1400', $amounts->fresh()->credits);
        $this->assertSame('6.7988', $read->credits_used);
        $this->assertSame('493.2012', $this->wallet($this->organization)->available());
        // Nothing is on hold any more: all of the wallet is free again.
        $this->assertSame('493.2012', $this->credits()->free($this->organization));
        $this->assertLedgerMatchesWallet($this->organization);

        $finished = $this->audits('ai.read_finished');
        $this->assertCount(1, $finished);
        $this->assertSame(['status' => 'ready', 'pages_read' => 1, 'pages_failed' => 0, 'credits_used' => '6.7988', 'credits_burned' => '0.0000'], $finished[0]->new_values);
        $this->assertSame($this->organization->id, $finished[0]->organization_id);

        $this->assertSame(['The reader has finished: scanned BOQ pages'], $this->noticesOf($this->owner));
    }

    public function test_a_page_the_reader_reports_as_blank_is_saved_but_not_charged(): void
    {
        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer('blank', 5956, 40)]);

        $this->readPage($page);

        // 5,956 in and 40 out is 0.012312 dollars, 1.4406 credits: nobody pays it, and it comes off the read's limit.
        $page->refresh();
        $this->assertSame(DocumentReadPage::READ, $page->status);
        $this->assertSame('0.0000', $page->credits);
        $this->assertSame('0.012312', $page->cost_usd);

        $read->refresh();
        $this->assertSame('0.0000', $read->credits_used);
        $this->assertSame('1.4406', $read->credits_burned);
        $this->assertSame('98.5594', $read->credits_held);
        $this->assertSame('0.012312', $read->absorbed_usd);
        $this->assertSame([], $this->usageLines($this->organization));
        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame([[$page->id, 'rows']], FakeKind::$staged);
        $this->assertSame(0, (int) $page->behaviour_failures);
    }

    public function test_a_job_delivered_twice_sends_and_charges_once(): void
    {
        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);
        $this->readPage($page);
        (new ReadDocumentPage($page->id))->handle();

        Http::assertSentCount(1);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame(1, $page->fresh()->attempts);
        $this->assertSame('6.6588', $read->fresh()->credits_used);
        $this->assertCount(1, $this->audits('ai.page_sent'));
    }

    public function test_a_worker_whose_claim_was_taken_over_writes_nothing_and_charges_nothing(): void
    {
        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $other = (string) Str::uuid();

        // While this worker waits for the answer, the page is given up as lost and claimed by another worker.
        $this->fakeReader([function () use ($page, $other) {
            DocumentReadPage::withoutGlobalScopes()->whereKey($page->id)->update(['attempt_uuid' => $other, 'attempts' => 2]);

            return $this->answer();
        }]);

        $this->readPage($page);

        $page->refresh();
        $this->assertSame(DocumentReadPage::RUNNING, $page->status);
        $this->assertSame($other, $page->attempt_uuid);
        $this->assertNull($page->result);
        $this->assertSame('0.000000', $page->cost_usd);
        $this->assertSame([], $this->usageLines($this->organization));
        $this->assertSame('100.0000', $read->fresh()->credits_held);
        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame([], FakeKind::$staged);
        $this->assertSame([], $this->audits('ai.page_sent'));
        $this->assertSame([], $this->queuedPages());
    }

    public function test_a_charge_never_takes_more_than_the_read_holds_and_the_read_then_pauses(): void
    {
        $read = $this->startedRead(held: '5');
        $page = $this->storedPage($read, 1);
        $next = $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        $read->refresh();
        $this->assertSame(DocumentRead::PAUSED, $read->status);
        $this->assertSame(DocumentRead::PAUSE_CREDIT_CAP, $read->pause_reason);
        $this->assertSame('5.0000', $read->credits_used);
        $this->assertSame('1.6588', $read->credits_burned);
        $this->assertSame('0.0000', $read->credits_held);
        $this->assertSame('5.0000', $page->fresh()->credits);
        $this->assertSame('495.0000', $this->wallet($this->organization)->available());
        $this->assertLedgerMatchesWallet($this->organization);

        // Nothing more goes out, and the starter is told once.
        $this->assertSame([], $this->queuedPages());
        $this->assertSame(DocumentReadPage::WAITING, $next->fresh()->status);
        $this->assertSame(['The reader has paused: scanned BOQ pages'], $this->noticesOf($this->owner));
    }

    public function test_a_read_whose_last_page_uses_up_its_hold_is_finished_not_left_paused(): void
    {
        $read = $this->startedRead(held: '6.6588', overrides: ['pages_total' => 1]);
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        $read->refresh();
        $this->assertSame(DocumentRead::READY, $read->status);
        $this->assertNull($read->pause_reason);
        $this->assertSame('6.6588', $read->credits_used);
        $this->assertSame(['The reader has finished: scanned BOQ pages'], $this->noticesOf($this->owner));
    }

    public function test_the_wallet_can_never_go_below_zero(): void
    {
        $this->setCredits($this->organization, '2.5000');
        $read = $this->startedRead(held: '2.5');
        $page = $this->storedPage($read);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        $this->assertSame('0.0000', $this->wallet($this->organization)->available());
        $this->assertSame('2.5000', $page->fresh()->credits);
        $this->assertSame('-2.5000', $this->usageLines($this->organization)[0]->credits);
        $this->assertSame('0.0000', $this->usageLines($this->organization)[0]->balance_after);

        $read->refresh();
        $this->assertSame(DocumentRead::PAUSED, $read->status);
        $this->assertSame('4.1588', $read->credits_burned);
        // The part of the page's cost that nobody could be charged for: 0.056912 x 4.1588 / 6.6588.
        $this->assertSame('0.035545', $read->absorbed_usd);
    }

    public function test_a_page_tried_twice_keeps_the_cost_of_both_tries_and_is_charged_for_the_one_that_was_read(): void
    {
        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer('unusable', 5956, 300), $this->answer('other')]);

        $this->readPage($page);

        // 5,956 in and 300 out: 0.014912 dollars, 1.7448 credits, not charged.
        $page->refresh();
        $this->assertSame(DocumentReadPage::FAILED, $page->status);
        $this->assertSame('invalid_output', $page->fail_reason);
        $this->assertSame('0.014912', $page->cost_usd);
        $this->assertSame('1.7448', $read->fresh()->credits_burned);

        // Someone asks for the page again.
        $page->forceFill(['status' => DocumentReadPage::QUEUED])->save();
        $this->readPage($page);

        $page->refresh();
        $this->assertSame(DocumentReadPage::READ, $page->status);
        $this->assertNull($page->fail_reason);
        $this->assertSame(2, $page->attempts);
        $this->assertSame('0.071824', $page->cost_usd);
        $this->assertSame('6.6588', $page->credits);
        $this->assertSame(1, (int) $page->behaviour_failures);

        $read->refresh();
        $this->assertSame('6.6588', $read->credits_used);
        $this->assertSame('1.7448', $read->credits_burned);
        $this->assertSame('0.071824', $read->cost_usd);
        $this->assertSame('0.014912', $read->absorbed_usd);
        $this->assertSame('91.5964', $read->credits_held);
        $this->assertSame("dr:{$read->id}:{$page->id}:2", $this->usageLines($this->organization)[0]->reference);
        $this->assertSame([1, 0, 0], [$read->pages_read, $read->pages_failed, $read->consecutive_failures]);
    }

    public function test_one_company_s_page_never_touches_another_company(): void
    {
        $other = $this->tenant('Narmada Projects');
        $otherOwner = $this->member($other, 'owner');
        $this->consent($other);
        $theirs = $this->startedRead($otherOwner);
        $theirPage = $this->storedPage($theirs, 1, ['status' => DocumentReadPage::WAITING]);

        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        $this->assertSame('500.0000', $this->wallet($other)->available());
        $this->assertSame([], $this->usageLines($other));
        $this->assertSame('100.0000', $theirs->fresh()->credits_held);
        $this->assertSame(DocumentReadPage::WAITING, $theirPage->fresh()->status);
        $this->assertSame([], $this->queuedPages());

        foreach ($this->audits('ai.page_sent') as $audit) {
            $this->assertSame($this->organization->id, $audit->organization_id);
        }

        $this->assertSame(0, AgentCreditLedger::withoutGlobalScopes()->where('organization_id', $other->id)->where('type', 'usage')->count());
    }

    public function test_a_row_that_points_at_another_company_s_file_is_never_sent(): void
    {
        $other = $this->tenant('Narmada Projects');
        $otherOwner = $this->member($other, 'owner');
        $theirPage = $this->storedPage($this->startedRead($otherOwner), 1);

        $read = $this->startedRead();
        $page = $this->storedPage($read, 1, ['image_path' => $theirPage->image_path, 'image_sha256' => $theirPage->image_sha256]);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        Http::assertNothingSent();
        $page->refresh();
        $this->assertSame(DocumentReadPage::FAILED, $page->status);
        $this->assertSame('internal_error', $page->fail_reason);
        $this->assertSame('0.000000', $page->cost_usd);
        $this->assertSame([], $this->audits('ai.page_sent'));
        $this->assertSame([], $this->usageLines($this->organization));
    }

    public function test_a_picture_that_changed_since_upload_or_is_gone_is_not_sent(): void
    {
        $read = $this->startedRead();
        $changed = $this->storedPage($read, 1);
        $gone = $this->storedPage($read, 2);
        Storage::put($changed->image_path, $this->jpeg(40, 56).'tampered');
        Storage::delete($gone->image_path);
        $this->fakeReader([$this->answer()]);

        $this->readPage($changed);
        $this->readPage($gone);

        Http::assertNothingSent();
        $this->assertSame(['internal_error', 'internal_error'], [$changed->fresh()->fail_reason, $gone->fresh()->fail_reason]);
        $this->assertSame(['0.0000', '0.0000'], [$read->fresh()->credits_used, $read->fresh()->credits_burned]);
        $this->assertSame([], $this->audits('ai.page_sent'));
    }

    public function test_the_audit_line_says_what_was_sent_and_never_what_it_said(): void
    {
        $read = $this->startedRead(overrides: ['source_name' => 'Krishna Associates estimate.pdf']);
        $page = $this->storedPage($read, 3, ['part' => 'top']);
        $this->fakeReader([$this->answer()]);

        $this->readPage($page);

        $audit = $this->audits('ai.page_sent')[0];
        $this->assertSame($this->organization->id, $audit->organization_id);
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame($page->id, (int) $audit->auditable_id);

        $this->assertSame([
            'read_id' => $read->id, 'kind' => 'boq', 'page_no' => 3, 'page_to' => null, 'pass' => 'rows', 'part' => 'top', 'attempt' => 1,
            'model' => 'claude-sonnet-5-5', 'bytes' => (int) $page->image_bytes, 'sha256' => $page->image_sha256, 'width' => 1622, 'height' => 2296,
            'outcome' => 'read', 'reason' => null, 'provider_request_id' => 'req_test_1',
        ], $audit->new_values);

        $stored = json_encode([$audit->new_values, $audit->old_values]);
        $this->assertStringNotContainsString('4,250.00', $stored);
        $this->assertStringNotContainsString('Krishna', $stored);
    }

    public function test_the_picture_itself_is_kept_out_of_every_table(): void
    {
        // A real queue table this time, so the job's payload can be looked at.
        $this->useTheQueueTable();

        $read = $this->startedRead();
        $page = $this->storedPage($read, 1);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer()]);
        $picture = $this->base64Of($page);

        $this->readPage($page);

        $this->assertGreaterThan(0, DB::table('jobs')->count());

        foreach (['jobs', 'failed_jobs', 'document_read_pages', 'document_reads', 'audit_logs', 'credit_ledger', 'notifications'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString($picture, json_encode($row), $table);
                $this->assertStringNotContainsString(substr($picture, 0, 200), stripslashes(json_encode($row)), $table);
            }
        }

        $payload = DB::table('jobs')->where('payload', 'like', '%ReadDocumentPage%')->value('payload');
        $this->assertLessThan(2000, strlen((string) $payload));
    }

    public function test_the_job_has_room_for_the_longest_call_and_reads_settings_afresh(): void
    {
        $job = new ReadDocumentPage(7);

        $this->assertSame(3, $job->tries);
        $this->assertSame(270, $job->timeout);

        $longest = collect(config('modules.document_reading.kinds'))->flatMap(fn ($kind) => array_column($kind['passes'], 'timeout'))->max();
        $this->assertLessThanOrEqual($job->timeout - 20, $longest + 10);

        // A key removed after the worker started is seen by the next job.
        $read = $this->startedRead();
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);
        $settings = app(PlatformSettings::class);
        PlatformSetting::whereKey('anthropic_api_key')->delete();
        Cache::forget('platform_settings.v1');

        $this->assertSame($settings, app(PlatformSettings::class));
        (new ReadDocumentPage($page->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(DocumentRead::PAUSE_NOT_CONNECTED, $read->fresh()->pause_reason);

        // Its failed() hook leaves the page alone: only tending decides what a lost page becomes.
        $other = $this->storedPage($this->startedRead(), 1, ['status' => DocumentReadPage::RUNNING]);
        $job = new ReadDocumentPage($other->id);
        $job->failed(new \RuntimeException('The worker was stopped.'));
        $job->failed(null);
        $this->assertSame(DocumentReadPage::RUNNING, $other->fresh()->status);
    }

    public function test_a_rejection_note_is_only_for_the_platform_admin(): void
    {
        $this->assertNull(PageReader::rejectionNote(123));
    }
}
