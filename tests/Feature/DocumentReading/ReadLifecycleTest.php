<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\ReadDocumentPage;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\ReadLifecycle;
use App\Services\DocumentReading\ReadQueues;
use App\Services\DocumentReading\ReadWords;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DocumentReading\Support\FakeKind;

/** Sending the next page, finishing, pausing, cancelling and closing a read, and who is told. */
class ReadLifecycleTest extends PageReadingTestCase
{
    private function page(DocumentRead $read, int $number, string $status = DocumentReadPage::WAITING, array $overrides = []): DocumentReadPage
    {
        return $this->makePage($read, $number, ['status' => $status, ...$overrides]);
    }

    public function test_pages_go_out_one_at_a_time_in_reading_order(): void
    {
        $read = $this->startedRead();
        // Made out of order on purpose: page 2 first, a second pass before its first, a bottom half before its top.
        $second = $this->page($read, 2);
        $firstAmounts = $this->page($read, 1, overrides: ['pass' => 'amounts']);
        $firstBottom = $this->page($read, 1, overrides: ['part' => 'bottom']);
        $firstTop = $this->page($read, 1, overrides: ['part' => 'top']);

        $this->assertSame(ReadLifecycle::DISPATCHED, $this->lifecycle()->advance($read));
        $this->assertSame([$firstTop->id], $this->queuedPages());
        $this->assertSame(DocumentReadPage::QUEUED, $firstTop->fresh()->status);
        $this->assertNotNull($read->fresh()->last_dispatched_at);

        // While one is out, nothing more is sent, however often it is asked.
        $this->assertSame(ReadLifecycle::IN_FLIGHT, $this->lifecycle()->advance($read->id));
        $this->assertCount(1, $this->queuedPages());

        $order = [$firstTop->id];

        foreach ([$firstTop, $firstBottom, $firstAmounts] as $done) {
            $done->forceFill(['status' => DocumentReadPage::READ])->save();
            $this->lifecycle()->advance($read);
            $order = $this->queuedPages();
        }

        $this->assertSame([$firstTop->id, $firstBottom->id, $firstAmounts->id, $second->id], $order);
    }

    public function test_more_pages_go_out_together_only_when_the_setting_says_so(): void
    {
        config(['modules.document_reading.parallel_pages' => 3]);
        $read = $this->startedRead();
        $pages = array_map(fn (int $number) => $this->page($read, $number), [1, 2, 3, 4]);
        $this->page($read, 5, DocumentReadPage::RUNNING);

        $this->lifecycle()->advance($read);

        // One was already with the reader, so two more fit.
        $this->assertSame([$pages[0]->id, $pages[1]->id], $this->queuedPages());
    }

    public function test_page_jobs_go_to_the_queue_of_their_kind_and_only_a_usable_name_replaces_it(): void
    {
        $queues = fn () => app(ReadQueues::class);

        // Out of the box every lane is the queue the worker already reads.
        $this->assertNull($queues()->name('pages'));
        $this->assertNull($queues()->name('interactive'));
        $this->assertNull($queues()->name('prepare'));
        $this->assertNull($queues()->forKind('boq'));

        $read = $this->startedRead();
        $this->page($read, 1);
        $this->lifecycle()->advance($read);
        Queue::assertPushed(ReadDocumentPage::class, fn (ReadDocumentPage $job) => $job->queue === null && $job->afterCommit === true);

        app(PlatformSettings::class)->set(['ai_queue_pages' => 'ai-pages', 'ai_queue_interactive' => 'ai-interactive']);

        $this->assertSame('ai-pages', $queues()->forKind('boq'));
        $this->assertSame('ai-pages', $queues()->forKind('tender'));
        $this->assertSame('ai-interactive', $queues()->forKind('slip'));
        $this->assertSame('ai-interactive', $queues()->forKind('bill'));
        $this->assertSame('ai-interactive', $queues()->name(ReadQueues::INTERACTIVE));
        $this->assertNull($queues()->name(ReadQueues::PREPARE));
        $this->assertSame('ai-pages', $queues()->name('no such lane'));

        $slip = $this->startedRead(overrides: ['kind' => 'slip']);
        $slipPage = $this->page($slip, 1, overrides: ['pass' => 'main', 'model' => 'claude-haiku-5-5']);
        $boq = $this->startedRead();
        $boqPage = $this->page($boq, 1);
        $this->lifecycle()->advance($slip);
        $this->lifecycle()->advance($boq);

        Queue::assertPushed(ReadDocumentPage::class, fn (ReadDocumentPage $job) => $job->pageId === $slipPage->id && $job->queue === 'ai-interactive');
        Queue::assertPushed(ReadDocumentPage::class, fn (ReadDocumentPage $job) => $job->pageId === $boqPage->id && $job->queue === 'ai-pages');

        // The config can name a queue too, and a name that is not a queue name is ignored.
        app(PlatformSettings::class)->set(['ai_queue_pages' => 'not a queue; DROP', 'ai_queue_interactive' => null]);
        config(['modules.document_reading.queues.prepare' => 'ai-prepare']);

        $this->assertNull($queues()->name('pages'));
        $this->assertNull($queues()->name('interactive'));
        $this->assertSame('ai-prepare', $queues()->name('prepare'));
    }

    public function test_nothing_is_sent_for_a_read_that_is_not_running(): void
    {
        foreach ([DocumentRead::DRAFT, DocumentRead::QUOTED, DocumentRead::PAUSED, DocumentRead::READY, DocumentRead::CANCELLED] as $status) {
            $read = $this->startedRead(overrides: ['status' => $status, 'credits_held' => '0']);
            $this->page($read, 1);

            $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance($read), $status);
            $this->assertSame($status, $read->fresh()->status);
        }

        $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance(987654));
        $this->assertSame([], $this->queuedPages());
    }

    public function test_a_read_with_nothing_left_ends_as_ready_when_any_page_was_read(): void
    {
        $read = $this->startedRead(held: '80', overrides: ['pages_total' => 4, 'credits_used' => '20', 'credits_burned' => '6.5']);
        $this->page($read, 1, DocumentReadPage::READ);
        $this->page($read, 2, DocumentReadPage::FAILED, ['fail_reason' => 'cut_off']);
        $this->page($read, 3, DocumentReadPage::READ);
        $this->page($read, 3, DocumentReadPage::FAILED, ['pass' => 'amounts', 'fail_reason' => 'timeout']);
        $this->page($read, 4, DocumentReadPage::SKIPPED);

        $this->assertSame(ReadLifecycle::FINISHED, $this->lifecycle()->advance($read));

        $read->refresh();
        $this->assertSame([DocumentRead::READY, '0.0000', 1, 2], [$read->status, $read->credits_held, $read->pages_read, $read->pages_failed]);
        $this->assertNotNull($read->finished_at);
        $this->assertSame([$read->id], FakeKind::$finished);
        $this->assertSame('500.0000', $this->credits()->free($this->organization));

        $this->assertSame(
            ['status' => 'ready', 'pages_read' => 1, 'pages_failed' => 2, 'credits_used' => '20.0000', 'credits_burned' => '6.5000'],
            $this->audits('ai.read_finished')[0]->new_values,
        );

        $notice = json_decode(DB::table('notifications')->where('notifiable_id', $this->owner->id)->value('data'), true);
        $this->assertSame('The reader has finished: scanned BOQ pages', $notice['title']);
        $this->assertSame('1 of 4 pages were read and 2 could not be read. Check every value against the page before you save anything.', $notice['body']);

        // Asking again changes nothing and tells nobody twice.
        $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance($read));
        $this->assertCount(1, $this->noticesOf($this->owner));
    }

    public function test_a_paused_read_with_nothing_left_is_finished_too_and_one_with_pages_left_stays_paused(): void
    {
        $done = $this->startedRead(overrides: ['status' => DocumentRead::PAUSED, 'pause_reason' => 'credit_cap', 'credits_held' => '0']);
        $this->page($done, 1, DocumentReadPage::READ);

        $this->assertSame(ReadLifecycle::FINISHED, $this->lifecycle()->advance($done));
        $this->assertSame([DocumentRead::READY, null], [$done->fresh()->status, $done->fresh()->pause_reason]);

        $waiting = $this->startedRead(overrides: ['status' => DocumentRead::PAUSED, 'pause_reason' => 'wallet', 'credits_held' => '0']);
        $this->page($waiting, 1, DocumentReadPage::READ);
        $queued = $this->page($waiting, 2, DocumentReadPage::QUEUED);

        $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance($waiting));
        $this->assertSame(DocumentRead::PAUSED, $waiting->fresh()->status);
        // A page that was queued when a charge paused the read goes back to waiting.
        $this->assertSame(DocumentReadPage::WAITING, $queued->fresh()->status);
    }

    public function test_a_finishing_step_that_fails_is_tried_again_and_after_five_times_the_read_ends_as_failed(): void
    {
        $read = $this->startedRead();
        $this->page($read, 1, DocumentReadPage::READ);
        FakeKind::$failuresToFinish = 2;

        // Nothing of a failed try is kept: the read still runs and still holds its credits.
        $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance($read));
        $this->assertSame([DocumentRead::RUNNING, '100.0000'], [$read->fresh()->status, $read->fresh()->credits_held]);
        $this->assertSame([], $this->audits('ai.read_finished'));

        $this->lifecycle()->advance($read);
        $this->assertSame(ReadLifecycle::FINISHED, $this->lifecycle()->advance($read));
        $this->assertSame(DocumentRead::READY, $read->fresh()->status);

        $stuck = $this->startedRead();
        $this->page($stuck, 1, DocumentReadPage::READ);
        FakeKind::$failuresToFinish = 50;

        foreach (range(1, 4) as $try) {
            $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance($stuck));
        }

        $this->assertSame(ReadLifecycle::FINISHED, $this->lifecycle()->advance($stuck));

        $stuck->refresh();
        $this->assertSame([DocumentRead::FAILED, ReadWords::FINISH_FAILED, '0.0000'], [$stuck->status, $stuck->error, $stuck->credits_held]);
        $this->assertSame('Something went wrong while finishing this document.', ReadWords::error($stuck->error));
    }

    public function test_a_pause_gives_back_the_hold_takes_queued_pages_back_and_tells_the_starter(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $read = $this->startedRead($engineer, '60');
        $queued = $this->page($read, 1, DocumentReadPage::QUEUED, ['not_before' => now()->addMinute()]);
        $running = $this->page($read, 2, DocumentReadPage::RUNNING);

        $this->assertTrue($this->lifecycle()->pause($read, DocumentRead::PAUSE_INFRA));

        // The caller's own copy shows it as well.
        foreach ([$read, $read->fresh()] as $copy) {
            $this->assertSame([DocumentRead::PAUSED, DocumentRead::PAUSE_INFRA, '0.0000'], [$copy->status, $copy->pause_reason, $copy->credits_held]);
        }

        $this->assertSame([DocumentReadPage::WAITING, null], [$queued->fresh()->status, $queued->fresh()->not_before]);
        $this->assertSame(DocumentReadPage::RUNNING, $running->fresh()->status);
        $this->assertSame('500.0000', $this->credits()->free($this->organization));

        $notice = json_decode(DB::table('notifications')->where('notifiable_id', $engineer->id)->value('data'), true);
        $this->assertSame('The reader has paused: scanned BOQ pages', $notice['title']);
        $this->assertSame('The AI service cannot be reached right now. Nothing more is sent until someone continues it. A paused document is cancelled after 24 hours.', $notice['body']);
        $this->assertSame([], $this->noticesOf($this->owner));

        // A second pause is refused and tells nobody.
        $this->assertFalse($this->lifecycle()->pause($read, DocumentRead::PAUSE_MANY_FAILURES));
        $this->assertSame(DocumentRead::PAUSE_INFRA, $read->fresh()->pause_reason);
        $this->assertCount(1, $this->noticesOf($engineer));
        $this->assertCount(1, $this->audits('ai.read_paused'));
    }

    public function test_each_pause_reason_has_its_own_words_and_none_names_the_provider_s_trouble(): void
    {
        $reasons = [
            DocumentRead::PAUSE_CREDIT_CAP, DocumentRead::PAUSE_WALLET, DocumentRead::PAUSE_STARTER_GONE, DocumentRead::PAUSE_CONSENT_OFF, DocumentRead::PAUSE_PLAN,
            DocumentRead::PAUSE_MANY_FAILURES, DocumentRead::PAUSE_PROVIDER_LIMIT, DocumentRead::PAUSE_COMPANY_LIMIT, DocumentRead::PAUSE_PLATFORM_CEILING,
        ];

        $words = array_map(fn (string $reason) => ReadWords::pause($reason), $reasons);
        $this->assertCount(count($reasons), array_unique($words));

        // What is wrong on the platform's side is the platform's business: a company reads one plain sentence for all of it.
        $generic = ReadWords::pause(DocumentRead::PAUSE_INFRA);

        foreach ([DocumentRead::PAUSE_NOT_CONNECTED, DocumentRead::PAUSE_NOT_RELEASED, 'spend_limit', 'billing', 'rejected_key', 'something new', null] as $reason) {
            $this->assertSame($generic, ReadWords::pause($reason));
        }

        foreach ([...$words, $generic] as $sentence) {
            $this->assertDoesNotMatchRegularExpression('/key|billing|spending|Anthropic/i', $sentence);
        }

        $this->assertSame('AI सेवा व्यस्त है। कुछ मिनट बाद जारी रखें।', ReadWords::pause(DocumentRead::PAUSE_PROVIDER_LIMIT, 'hi'));
        $this->assertSame('AI सेवा व्यस्त आहे. काही मिनिटांनी पुढे सुरू करा.', ReadWords::pause(DocumentRead::PAUSE_PROVIDER_LIMIT, 'mr'));
        $this->assertSame('This file is too large to read as text. Read it as page pictures.', ReadWords::error(ReadWords::TOO_LARGE_FOR_TEXT));
        $this->assertSame('', ReadWords::error(null));
    }

    public function test_a_read_that_is_watched_on_its_own_form_sends_no_notice(): void
    {
        $slip = $this->startedRead(overrides: ['kind' => 'slip', 'pages_total' => 1]);
        $this->page($slip, 1, DocumentReadPage::READ, ['pass' => 'main']);

        $this->assertSame(ReadLifecycle::FINISHED, $this->lifecycle()->advance($slip));
        $this->assertSame(DocumentRead::READY, $slip->fresh()->status);

        $other = $this->startedRead(overrides: ['kind' => 'slip']);
        $this->lifecycle()->pause($other, DocumentRead::PAUSE_WALLET);

        $this->assertSame([], $this->noticesOf($this->owner));
    }

    public function test_cancelling_skips_what_was_not_sent_and_closes_the_read_for_good(): void
    {
        $read = $this->startedRead(held: '70');
        $read->forceFill(['credits_used' => '12'])->save();
        $done = $this->page($read, 1, DocumentReadPage::READ);
        $waiting = $this->page($read, 2);
        $queued = $this->page($read, 3, DocumentReadPage::QUEUED);
        $running = $this->page($read, 4, DocumentReadPage::RUNNING);

        $this->assertTrue($this->lifecycle()->cancel($read, $this->owner));

        $this->assertSame([DocumentRead::CANCELLED, '0.0000', '12.0000'], [$read->status, $read->fresh()->credits_held, $read->fresh()->credits_used]);
        $this->assertNotNull($read->fresh()->finished_at);
        $this->assertSame(
            [DocumentReadPage::READ, DocumentReadPage::SKIPPED, DocumentReadPage::SKIPPED, DocumentReadPage::RUNNING],
            [$done->fresh()->status, $waiting->fresh()->status, $queued->fresh()->status, $running->fresh()->status],
        );

        $audit = $this->audits('ai.read_cancelled')[0];
        $this->assertSame([$this->owner->id, $this->organization->id, ['status' => 'cancelled']], [$audit->user_id, $audit->organization_id, $audit->new_values]);

        // Closed is closed: it cannot be cancelled twice, paused, or given another page.
        $this->assertFalse($this->lifecycle()->cancel($read));
        $this->assertFalse($this->lifecycle()->pause($read, DocumentRead::PAUSE_INFRA));
        $this->assertSame(ReadLifecycle::IDLE, $this->lifecycle()->advance($read));
        $this->assertSame([], $this->queuedPages());
    }

    public function test_only_an_unfinished_read_can_be_cancelled_and_only_a_ready_one_expires(): void
    {
        foreach ([DocumentRead::DRAFT, DocumentRead::PREPARING, DocumentRead::PREPARED, DocumentRead::QUOTED, DocumentRead::PAUSED] as $status) {
            $read = $this->makeRead($this->organization, $this->owner, ['status' => $status]);
            $this->assertTrue($this->lifecycle()->cancel($read, null, ReadWords::NOT_STARTED), $status);
            $this->assertSame([DocumentRead::CANCELLED, ReadWords::NOT_STARTED], [$read->fresh()->status, $read->fresh()->error]);
        }

        foreach ([DocumentRead::READY, DocumentRead::FAILED, DocumentRead::CONFIRMED, DocumentRead::DISCARDED, DocumentRead::EXPIRED] as $status) {
            $read = $this->makeRead($this->organization, $this->owner, ['status' => $status]);
            $this->assertFalse($this->lifecycle()->cancel($read), $status);
            $this->assertSame($status === DocumentRead::READY, $this->lifecycle()->expire($read), $status);
        }

        $preparing = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::PREPARING]);
        $this->assertTrue($this->lifecycle()->failPreparing($preparing, ReadWords::TOO_LARGE_FOR_TEXT));
        $this->assertSame([DocumentRead::FAILED, ReadWords::TOO_LARGE_FOR_TEXT], [$preparing->fresh()->status, $preparing->fresh()->error]);
        $this->assertFalse($this->lifecycle()->failPreparing($this->makeRead($this->organization, $this->owner), ReadWords::TOO_LARGE_FOR_TEXT));
        $this->assertCount(1, $this->audits('ai.read_expired'));
        $this->assertCount(1, $this->audits('ai.read_failed'));
    }

    public function test_pages_are_counted_by_page_not_by_call(): void
    {
        $read = $this->startedRead(overrides: ['kind' => 'tender']);

        // Page 1: both passes read. Page 2: first pass read, second still waiting. Page 3: one half failed.
        $this->page($read, 1, DocumentReadPage::READ);
        $this->page($read, 1, DocumentReadPage::READ, ['pass' => 'amounts']);
        $this->page($read, 2, DocumentReadPage::READ);
        $this->page($read, 2, DocumentReadPage::WAITING, ['pass' => 'amounts']);
        $this->page($read, 3, DocumentReadPage::SUPERSEDED);
        $this->page($read, 3, DocumentReadPage::READ, ['part' => 'top']);
        $this->page($read, 3, DocumentReadPage::FAILED, ['part' => 'bottom', 'fail_reason' => 'cut_off']);
        // Page 4: replaced by its two halves, both read. Page 5 was skipped.
        $this->page($read, 4, DocumentReadPage::SUPERSEDED);
        $this->page($read, 4, DocumentReadPage::READ, ['part' => 'top']);
        $this->page($read, 4, DocumentReadPage::READ, ['part' => 'bottom']);
        $this->page($read, 5, DocumentReadPage::SKIPPED);
        // A run of eight text pages that was read, and a run of three that failed.
        $this->page($read, 12, DocumentReadPage::READ, ['page_to' => 19, 'pass' => 'text']);
        $this->page($read, 20, DocumentReadPage::FAILED, ['page_to' => 22, 'pass' => 'text', 'fail_reason' => 'timeout']);

        $this->assertSame(['read' => 10, 'failed' => 4], $this->lifecycle()->count($read));

        // Another company's rows are never counted, whatever read id they carry.
        $other = $this->tenant('Narmada Projects');
        DB::table('document_read_pages')->where('document_read_id', $read->id)->where('page_no', 12)->update(['organization_id' => $other->id]);
        $this->assertSame(['read' => 2, 'failed' => 4], $this->lifecycle()->count($read));
    }
}
