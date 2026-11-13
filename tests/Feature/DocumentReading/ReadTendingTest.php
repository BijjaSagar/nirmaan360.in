<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\ReadDocumentPage;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadQuote;
use App\Services\DocumentReading\ReadTending;
use App\Services\DocumentReading\ReadWords;
use App\Support\PlatformSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\FakeRetention;

/** What happens to reads and pages when nobody is looking: lost workers, forgotten reads, old files. */
class ReadTendingTest extends PageReadingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2027-01-20 11:00:00');
        FakeRetention::$runs = 0;
    }

    private function lostPage(DocumentRead $read, int $number = 1, array $overrides = []): DocumentReadPage
    {
        return $this->makePage($read, $number, [
            'status' => DocumentReadPage::RUNNING, 'attempts' => 1, 'attempt_uuid' => (string) Str::uuid(),
            'claimed_at' => now()->subSeconds(331), ...$overrides,
        ]);
    }

    public function test_a_page_whose_worker_died_is_tried_once_more_and_then_fails_uncharged(): void
    {
        $read = $this->startedRead(overrides: ['last_dispatched_at' => now()->subSeconds(331), 'last_progress_at' => now()->subSeconds(400)]);
        $page = $this->lostPage($read);
        $this->makePage($read, 2);

        $this->tending()->all();

        $page->refresh();
        $this->assertSame([DocumentReadPage::QUEUED, 1, null, null], [$page->status, (int) $page->auto_requeued, $page->attempt_uuid, $page->fail_reason]);
        $this->assertSame([$page->id], $this->queuedPages());
        $this->assertSame(DocumentRead::RUNNING, $read->fresh()->status);

        // The second worker is lost as well.
        $page->forceFill(['status' => DocumentReadPage::RUNNING, 'attempts' => 2, 'attempt_uuid' => (string) Str::uuid(), 'claimed_at' => now()->subSeconds(331)])->save();

        $this->tending()->all();

        $page->refresh();
        $this->assertSame([DocumentReadPage::FAILED, 'worker_lost'], [$page->status, $page->fail_reason]);
        $this->assertNotNull($page->finished_at);
        $this->assertSame('0.000000', $page->cost_usd);

        $read->refresh();
        $this->assertSame([DocumentRead::RUNNING, 1, 1], [$read->status, $read->pages_failed, $read->consecutive_failures]);
        $this->assertSame(['100.0000', '0.0000', '0.0000'], [$read->credits_held, $read->credits_burned, $read->credits_used]);
        $this->assertSame([], $this->usageLines($this->organization));

        // The read goes on with its next page.
        $this->assertCount(2, $this->queuedPages());
        $this->assertSame(DocumentReadPage::QUEUED, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('page_no', 2)->sole()->status);
    }

    public function test_a_recovered_page_that_then_gets_an_answer_has_its_second_chance_back(): void
    {
        $read = $this->startedRead(overrides: ['last_dispatched_at' => now()->subSeconds(331)]);
        $path = ReadFiles::page($read, 1, 'rows');
        Storage::put($path, 'stored picture');
        $page = $this->lostPage($read, 1, ['image_path' => $path, 'image_sha256' => hash('sha256', 'stored picture')]);
        $this->makePage($read, 2);
        $this->fakeReader([$this->answer()]);

        $this->tending()->all();
        $this->assertSame([DocumentReadPage::QUEUED, 1], [$page->fresh()->status, (int) $page->fresh()->auto_requeued]);

        $this->readPage($page);

        $page->refresh();
        $this->assertSame([DocumentReadPage::READ, 0, 2], [$page->status, (int) $page->auto_requeued, $page->attempts]);
        // Only the try that was answered is charged.
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame("dr:{$read->id}:{$page->id}:2", $this->usageLines($this->organization)[0]->reference);
    }

    public function test_a_page_that_is_only_slow_is_left_alone_and_a_late_worker_cannot_write_after_it_was_given_up(): void
    {
        $read = $this->startedRead(overrides: ['last_dispatched_at' => now()->subSeconds(300)]);
        $slow = $this->lostPage($read, 1, ['claimed_at' => now()->subSeconds(329)]);

        $this->tending()->all();
        $this->assertSame(DocumentReadPage::RUNNING, $slow->fresh()->status);
        $this->assertSame([], $this->queuedPages());

        // Given up a moment later. The first worker's answer then arrives, and finds its claim gone.
        Carbon::setTestNow(now()->addSeconds(2));
        $this->tending()->one($read);
        $this->assertSame(DocumentReadPage::QUEUED, $slow->fresh()->status);

        $changed = DocumentReadPage::withoutGlobalScopes()->whereKey($slow->id)->where('attempt_uuid', $slow->attempt_uuid)->where('status', DocumentReadPage::RUNNING)->update(['status' => DocumentReadPage::READ]);
        $this->assertSame(0, $changed);
    }

    public function test_two_pages_lost_in_a_row_pause_the_read(): void
    {
        $read = $this->startedRead(overrides: ['last_dispatched_at' => now()->subSeconds(400)]);
        $this->makePage($read, 1, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'timeout', 'finished_at' => now()->subMinutes(7), 'attempts' => 1]);
        $lost = $this->lostPage($read, 2, ['auto_requeued' => 1]);
        $this->makePage($read, 3);

        $this->tending()->all();

        $this->assertSame('worker_lost', $lost->fresh()->fail_reason);
        $this->assertSame([DocumentRead::PAUSED, DocumentRead::PAUSE_INFRA, '0.0000'], [$read->fresh()->status, $read->fresh()->pause_reason, $read->fresh()->credits_held]);
        $this->assertSame([], $this->queuedPages());
        $this->assertSame(['The reader has paused: scanned BOQ pages'], $this->noticesOf($this->owner));
    }

    public function test_a_lost_page_of_a_read_that_is_no_longer_running_is_failed_straight_away(): void
    {
        $read = $this->startedRead(overrides: ['status' => DocumentRead::CANCELLED, 'credits_held' => '0']);
        $lost = $this->lostPage($read);

        $this->tending()->all();

        $this->assertSame([DocumentReadPage::FAILED, 'worker_lost'], [$lost->fresh()->status, $lost->fresh()->fail_reason]);
        $this->assertSame(DocumentRead::CANCELLED, $read->fresh()->status);
        $this->assertSame([], $this->queuedPages());
    }

    public function test_a_running_read_with_nothing_out_gets_its_next_page_once_half_a_minute_has_passed(): void
    {
        $recent = $this->startedRead(overrides: ['last_dispatched_at' => now()->subSeconds(20), 'last_progress_at' => now()->subSeconds(20)]);
        $recentPage = $this->makePage($recent, 1);
        $idle = $this->startedRead(overrides: ['last_dispatched_at' => now()->subSeconds(31), 'last_progress_at' => now()->subSeconds(31)]);
        $idlePage = $this->makePage($idle, 1);
        $never = $this->startedRead(overrides: ['last_dispatched_at' => null]);
        $neverPage = $this->makePage($never, 1);

        $this->tending()->all();

        $this->assertEqualsCanonicalizing([$idlePage->id, $neverPage->id], $this->queuedPages());
        $this->assertSame(DocumentReadPage::WAITING, $recentPage->fresh()->status);
    }

    public function test_a_read_with_nothing_left_is_finished_whether_it_runs_or_is_paused(): void
    {
        $running = $this->startedRead(overrides: ['last_dispatched_at' => now()->subMinutes(2), 'last_progress_at' => now()->subMinutes(2)]);
        $this->makePage($running, 1, ['status' => DocumentReadPage::READ]);
        $paused = $this->startedRead(overrides: ['status' => DocumentRead::PAUSED, 'pause_reason' => 'credit_cap', 'credits_held' => '0']);
        $this->makePage($paused, 1, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off']);
        $stillPaused = $this->startedRead(overrides: ['status' => DocumentRead::PAUSED, 'pause_reason' => 'wallet', 'credits_held' => '0']);
        $this->makePage($stillPaused, 1);

        $this->tending()->all();

        $this->assertSame(DocumentRead::READY, $running->fresh()->status);
        $this->assertSame(DocumentRead::FAILED, $paused->fresh()->status);
        $this->assertSame(DocumentRead::PAUSED, $stillPaused->fresh()->status);
    }

    public function test_a_page_that_sits_in_the_queue_for_five_minutes_is_handed_over_once_more(): void
    {
        $read = $this->startedRead(overrides: ['last_dispatched_at' => now(), 'last_progress_at' => now()]);
        $page = $this->makePage($read, 1, ['status' => DocumentReadPage::QUEUED]);
        $waitingOut = $this->startedRead(overrides: ['last_dispatched_at' => now(), 'last_progress_at' => now()]);
        $backingOff = $this->makePage($waitingOut, 1, ['status' => DocumentReadPage::QUEUED, 'not_before' => now()->addMinutes(20)]);

        Carbon::setTestNow(now()->addSeconds(299));
        $this->tending()->all();
        $this->assertSame([], $this->queuedPages());

        Carbon::setTestNow(now()->addSeconds(2));
        $this->tending()->all();
        $this->tending()->all();

        // Once, not once per round, and never for a page that is waiting out a back-off.
        $this->assertSame([$page->id], $this->queuedPages());
        $this->assertSame(DocumentReadPage::QUEUED, $backingOff->fresh()->status);

        Carbon::setTestNow(now()->addSeconds(301));
        $this->tending()->all();
        $this->assertSame([$page->id, $page->id], $this->queuedPages());
    }

    public function test_a_running_read_on_which_nothing_moves_for_half_an_hour_is_paused(): void
    {
        $stuck = $this->startedRead(overrides: ['started_at' => now()->subHours(2), 'last_dispatched_at' => now()->subMinutes(31), 'last_progress_at' => now()->subMinutes(45)]);
        $stuckPage = $this->makePage($stuck, 1, ['status' => DocumentReadPage::QUEUED]);
        $moving = $this->startedRead(overrides: ['started_at' => now()->subHours(2), 'last_dispatched_at' => now()->subMinutes(45), 'last_progress_at' => now()->subMinutes(29)]);
        $this->makePage($moving, 1, ['status' => DocumentReadPage::QUEUED, 'not_before' => now()->addMinute()]);

        $this->tending()->all();

        $this->assertSame([DocumentRead::PAUSED, DocumentRead::PAUSE_INFRA, '0.0000'], [$stuck->fresh()->status, $stuck->fresh()->pause_reason, $stuck->fresh()->credits_held]);
        $this->assertSame(DocumentReadPage::WAITING, $stuckPage->fresh()->status);
        $this->assertSame(DocumentRead::RUNNING, $moving->fresh()->status);
    }

    public function test_a_read_left_preparing_fails_with_a_plain_reason(): void
    {
        $slow = $this->makeRead($this->organization, $this->owner, ['kind' => 'tender', 'status' => DocumentRead::PREPARING, 'prepare_started_at' => now()->subSeconds(200)]);
        $stuck = $this->makeRead($this->organization, $this->owner, ['kind' => 'tender', 'status' => DocumentRead::PREPARING, 'prepare_started_at' => now()->subSeconds(241)]);
        DocumentRead::withoutGlobalScopes()->whereKey($stuck->id)->update(['updated_at' => now()->subSeconds(241)]);

        $this->tending()->all();

        $this->assertSame(DocumentRead::PREPARING, $slow->fresh()->status);
        $this->assertSame([DocumentRead::FAILED, ReadWords::TOO_LARGE_FOR_TEXT], [$stuck->fresh()->status, $stuck->fresh()->error]);
        $this->assertNotNull($stuck->fresh()->finished_at);
    }

    public function test_reads_nobody_came_back_to_are_closed(): void
    {
        $age = fn (DocumentRead $read, Carbon $at) => tap($read, fn () => DocumentRead::withoutGlobalScopes()->whereKey($read->id)->update(['updated_at' => $at]));
        $make = fn (string $status, Carbon $at, array $more = []) => $age($this->makeRead($this->organization, $this->owner, ['status' => $status, ...$more]), $at);

        $draftOld = $make(DocumentRead::DRAFT, now()->subMinutes(121));
        $draftNew = $make(DocumentRead::DRAFT, now()->subMinutes(119));
        // An old draft whose pages are still being uploaded is not abandoned.
        $draftBusy = $make(DocumentRead::DRAFT, now()->subMinutes(180));
        $this->makePage($draftBusy, 1);
        $quotedOld = $make(DocumentRead::QUOTED, now()->subHours(25));
        $quotedNew = $make(DocumentRead::QUOTED, now()->subHours(23));
        $preparedOld = $make(DocumentRead::PREPARED, now()->subHours(25));
        $pausedOld = $make(DocumentRead::PAUSED, now()->subHours(25), ['pause_reason' => 'wallet']);
        $pausedPage = $this->makePage($pausedOld, 1);
        $pausedNew = $make(DocumentRead::PAUSED, now()->subHours(23), ['pause_reason' => 'wallet']);
        $this->makePage($pausedNew, 1);
        $readyOld = $make(DocumentRead::READY, now()->subDays(15), ['finished_at' => now()->subDays(15)]);
        $readyNew = $make(DocumentRead::READY, now()->subDays(13), ['finished_at' => now()->subDays(20)]);
        $failedOld = $make(DocumentRead::FAILED, now()->subDays(15));

        $this->tending()->all();

        $status = fn (DocumentRead $read) => [$read->fresh()->status, $read->fresh()->error];

        $this->assertSame([DocumentRead::CANCELLED, ReadWords::NOT_STARTED], $status($draftOld));
        $this->assertSame([DocumentRead::DRAFT, null], $status($draftNew));
        $this->assertSame([DocumentRead::DRAFT, null], $status($draftBusy));
        $this->assertSame([DocumentRead::CANCELLED, ReadWords::NOT_STARTED], $status($quotedOld));
        $this->assertSame([DocumentRead::QUOTED, null], $status($quotedNew));
        $this->assertSame([DocumentRead::CANCELLED, ReadWords::NOT_STARTED], $status($preparedOld));
        $this->assertSame([DocumentRead::CANCELLED, ReadWords::PAUSED_TOO_LONG], $status($pausedOld));
        $this->assertSame(DocumentReadPage::SKIPPED, $pausedPage->fresh()->status);
        $this->assertSame([DocumentRead::PAUSED, null], $status($pausedNew));
        $this->assertSame([DocumentRead::EXPIRED, null], $status($readyOld));
        // Someone was still working on this one two weeks after it finished: it stays open.
        $this->assertSame([DocumentRead::READY, null], $status($readyNew));
        $this->assertSame([DocumentRead::FAILED, null], $status($failedOld));

        $this->assertSame(['It was not started in time, so it was cancelled.', 'It stayed paused for too long, so it was cancelled.'], [ReadWords::error(ReadWords::NOT_STARTED), ReadWords::error(ReadWords::PAUSED_TOO_LONG)]);
    }

    public function test_asking_about_one_read_tends_that_read_and_no_other(): void
    {
        $watched = $this->startedRead(overrides: ['last_dispatched_at' => now()->subMinutes(2), 'last_progress_at' => now()->subMinutes(2)]);
        $watchedPage = $this->makePage($watched, 1);
        $other = $this->startedRead(overrides: ['last_dispatched_at' => now()->subMinutes(2), 'last_progress_at' => now()->subMinutes(2)]);
        $otherPage = $this->makePage($other, 1);

        $this->tending()->one($watched);

        $this->assertSame([$watchedPage->id], $this->queuedPages());
        $this->assertSame(DocumentReadPage::WAITING, $otherPage->fresh()->status);
        $this->assertNull(ReadTending::lastRun());
    }

    public function test_old_files_and_answers_are_deleted_and_the_figures_stay(): void
    {
        $old = $this->startedRead(overrides: ['status' => DocumentRead::CONFIRMED, 'credits_held' => '0', 'credits_used' => '13.3176', 'cost_usd' => '0.113824', 'source_name' => 'estimate.pdf']);
        $first = $this->storedPage($old, 1, ['status' => DocumentReadPage::READ, 'result' => ['page_kind' => 'other', 'rows' => []], 'credits' => '6.6588', 'cost_usd' => '0.056912', 'input_tokens' => 5956]);
        $second = $this->storedPage($old, 2, ['status' => DocumentReadPage::READ, 'result' => ['page_kind' => 'other', 'rows' => []]]);
        Storage::put(ReadFiles::source($old), '%PDF-1.7 invented');
        Storage::put(ReadFiles::directory($old).'/left-behind.tmp', 'x');
        ReadFiles::putTexts($old, [1 => 'page one']);
        $old->forceFill(['source_path' => ReadFiles::source($old)])->save();
        DocumentRead::withoutGlobalScopes()->whereKey($old->id)->update(['updated_at' => now()->subDays(31)]);

        $recent = $this->startedRead(overrides: ['status' => DocumentRead::DISCARDED, 'credits_held' => '0']);
        $recentPage = $this->storedPage($recent, 1, ['status' => DocumentReadPage::READ, 'result' => ['page_kind' => 'other', 'rows' => []]]);
        DocumentRead::withoutGlobalScopes()->whereKey($recent->id)->update(['updated_at' => now()->subDays(29)]);

        // Old, but not ended: a ready read is closed first and deleted a month after that.
        $open = $this->startedRead(overrides: ['status' => DocumentRead::READY, 'credits_held' => '0']);
        $openPage = $this->storedPage($open, 1, ['status' => DocumentReadPage::READ]);
        DocumentRead::withoutGlobalScopes()->whereKey($open->id)->update(['updated_at' => now()->subDays(31)]);

        $done = $this->tending()->all();

        $this->assertSame(1, $done['purged']);
        $this->assertSame([], Storage::allFiles(ReadFiles::directory($old)));

        $old->refresh();
        $this->assertSame([null, null, DocumentRead::CONFIRMED], [$old->source_path, $old->text_path, $old->status]);
        $this->assertNotNull($old->files_purged_at);
        $this->assertSame(['13.3176', '0.113824', 'estimate.pdf'], [$old->credits_used, $old->cost_usd, $old->source_name]);

        $first->refresh();
        $this->assertSame([null, null], [$first->image_path, $first->result]);
        $this->assertSame(['6.6588', '0.056912', 5956, DocumentReadPage::READ], [$first->credits, $first->cost_usd, (int) $first->input_tokens, $first->status]);
        $this->assertNotNull($first->image_sha256);
        $this->assertNull($second->fresh()->result);
        $this->assertSame([$old->id], FakeKind::$purged);
        $this->assertCount(1, $this->audits('ai.read_purged'));

        // The other company files and the newer reads are untouched.
        $this->assertTrue(Storage::exists($recentPage->image_path));
        $this->assertNotNull($recentPage->fresh()->result);
        $this->assertNull($recent->fresh()->files_purged_at);
        $this->assertSame(DocumentRead::EXPIRED, $open->fresh()->status);
        $this->assertTrue(Storage::exists($openPage->image_path));

        // A second round finds nothing more to do for the same read.
        $this->assertSame(0, $this->tending()->purgeOld());
    }

    public function test_one_company_s_folder_is_all_that_a_purge_deletes(): void
    {
        $mine = $this->startedRead(overrides: ['status' => DocumentRead::CANCELLED, 'credits_held' => '0']);
        $minePage = $this->storedPage($mine, 1);
        DocumentRead::withoutGlobalScopes()->whereKey($mine->id)->update(['updated_at' => now()->subDays(40)]);

        $other = $this->tenant('Narmada Projects');
        $theirs = $this->startedRead($this->member($other, 'owner'), overrides: ['status' => DocumentRead::READY, 'credits_held' => '0']);
        $theirPage = $this->storedPage($theirs, 1, ['status' => DocumentReadPage::READ]);

        $this->assertNotSame(dirname($minePage->image_path), dirname($theirPage->image_path));
        $this->assertStringStartsWith("org-{$other->id}/document-reads/{$theirs->id}/", $theirPage->image_path);

        $this->tending()->purgeOld();

        $this->assertFalse(Storage::exists($minePage->image_path));
        $this->assertTrue(Storage::exists($theirPage->image_path));
        $this->assertNotNull($theirPage->fresh()->image_path);
    }

    public function test_every_module_s_own_clean_up_runs_each_round(): void
    {
        config(['modules.reader_stand_ins.retention' => [FakeRetention::class]]);

        $done = $this->tending()->all();
        $this->tending()->all();

        $this->assertSame(2, FakeRetention::$runs);
        $this->assertSame(3, $done['retained']);
    }

    public function test_the_fixed_part_of_each_prompt_is_counted_once_and_then_used_for_quotes(): void
    {
        $this->fakeReader([$this->answer()], promptTokens: 1437);
        $quote = fn () => app(ReadQuote::class);

        $this->assertSame(1200, $quote()->promptTokens('boq', 'rows'));

        // Two counts a round, so a slow provider can never hold a round up for long.
        $this->tending()->all();
        $this->assertSame(2, $this->counted);
        $this->tending()->all();
        $this->tending()->all();

        // Two passes for each of the three stand-in kinds, counted once each.
        $this->assertSame(6, $this->counted);
        $this->assertSame(1437, $quote()->promptTokens('boq', 'rows'));
        $this->assertSame(1437, $quote()->promptTokens('tender', 'text'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages/count_tokens')
            && ! isset($request->data()['max_tokens'])
            && ! str_contains(json_encode($request->data()), '"image"')
            && str_starts_with($request->data()['system'][0]['text'], ReaderRequest::RULES));

        // Not again within the hour, and after that only a look in the cache while the wording is the same.
        $this->tending()->all();
        Carbon::setTestNow(now()->addMinutes(61));
        $this->tending()->all();
        $this->assertSame(6, $this->counted);
        $this->assertSame([], $this->sent);
    }

    public function test_no_count_is_asked_for_without_a_saved_key(): void
    {
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        $this->fakeReader([$this->answer()]);

        $this->tending()->all();

        Http::assertNothingSent();
    }

    public function test_one_read_that_cannot_be_tended_does_not_stop_the_others(): void
    {
        $broken = $this->startedRead(overrides: ['last_dispatched_at' => now()->subMinutes(2), 'last_progress_at' => now()->subMinutes(2)]);
        $this->makePage($broken, 1, ['status' => DocumentReadPage::READ]);
        FakeKind::$failuresToFinish = 1;
        $fine = $this->startedRead(overrides: ['last_dispatched_at' => now()->subMinutes(2), 'last_progress_at' => now()->subMinutes(2)]);
        $finePage = $this->makePage($fine, 1);

        $this->tending()->all();

        $this->assertSame(DocumentRead::RUNNING, $broken->fresh()->status);
        $this->assertSame([$finePage->id], $this->queuedPages());

        // The next round finishes the first one.
        $this->tending()->all();
        $this->assertSame(DocumentRead::READY, $broken->fresh()->status);
    }

    public function test_the_command_runs_a_round_and_records_when(): void
    {
        $read = $this->startedRead(overrides: ['last_dispatched_at' => null]);
        $page = $this->makePage($read, 1);

        $this->assertNull(ReadTending::lastRun());
        $this->assertNull(ReadTending::oldestQueuedSince());

        $this->artisan('document-reads:tend')
            ->expectsOutputToContain('Tended 1 reads')
            ->assertSuccessful();

        $this->assertSame([$page->id], $this->queuedPages());
        $this->assertSame('2027-01-20 11:00:00', ReadTending::lastRun()->toDateTimeString());
        $this->assertSame('2027-01-20 11:00:00', ReadTending::oldestQueuedSince()->toDateTimeString());
    }

    public function test_the_command_is_scheduled_every_minute_on_one_server_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'document-reads:tend'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    public function test_tending_never_calls_the_reader_itself(): void
    {
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        $read = $this->startedRead(overrides: ['last_dispatched_at' => null]);
        $this->storedPage($read, 1, ['status' => DocumentReadPage::WAITING]);
        $this->lostPage($this->startedRead(), 1);
        Http::fake();

        $this->tending()->all();

        Http::assertNothingSent();
        Queue::assertPushed(ReadDocumentPage::class, 2);
    }
}
