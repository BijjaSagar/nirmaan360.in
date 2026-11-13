<?php

namespace Tests\Feature\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadTending;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The status body the screens and the phone watch a read with, the tending that asking for it
 * does, and the stored page picture.
 */
class ReadStatusTest extends ReadRoutesTestCase
{
    /** A running read of five pages in every state a page can be in. */
    private function mixed(): DocumentRead
    {
        $read = $this->startedRead(null, '60', [
            'project_id' => $this->project->id, 'pages_total' => 6, 'credits_cap' => '96', 'credits_used' => '13.4', 'credits_burned' => '6.6',
            'pages_read' => 1, 'pages_failed' => 3, 'last_dispatched_at' => now(), 'last_progress_at' => now(),
        ]);

        $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1]);
        $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1, 'pass' => 'amounts', 'model' => 'claude-haiku-5-5']);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off', 'attempts' => 1, 'behaviour_failures' => 1]);
        $this->storedPage($read, 3, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'invalid_output', 'attempts' => 1, 'behaviour_failures' => 1]);
        $this->storedPage($read, 4, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'timeout', 'attempts' => 3]);
        $this->storedPage($read, 5, ['status' => DocumentReadPage::RUNNING, 'attempts' => 1, 'claimed_at' => now()]);
        $this->storedPage($read, 6, ['status' => DocumentReadPage::WAITING]);

        return $read;
    }

    public function test_the_status_says_where_a_read_stands_and_what_can_be_done_about_each_failed_page(): void
    {
        $read = $this->mixed();

        $body = $this->web($this->owner, 'GET', $read, '/status')->assertOk()->json();

        $this->assertSame([
            'id', 'kind', 'status', 'pause_reason', 'error', 'message', 'pages_total', 'pages_read', 'pages_failed', 'pages_left', 'stored_pages',
            'credits_used', 'credits_burned', 'credits_cap', 'eta_minutes', 'stalled', 'can_cancel', 'can_resume', 'failed_pages', 'left_out_pages',
        ], array_keys($body));

        $this->assertSame([
            'id' => $read->id, 'kind' => 'boq', 'status' => 'running', 'pause_reason' => null, 'error' => null, 'message' => null,
            'pages_total' => 6, 'pages_read' => 1, 'pages_failed' => 3, 'pages_left' => 2, 'stored_pages' => [1, 2, 3, 4, 5, 6],
            'credits_used' => '13.4000', 'credits_burned' => '6.6000', 'credits_cap' => '96.0000',
        ], array_slice($body, 0, 14));

        // Two first passes are left, each usually followed by a second: 75, 20, 75 and 20 seconds, one call at a time.
        $this->assertSame(4, $body['eta_minutes']);
        $this->assertFalse($body['stalled']);
        $this->assertTrue($body['can_cancel']);
        $this->assertFalse($body['can_resume']);
        $this->assertSame([], $body['left_out_pages']);

        $this->assertSame([
            // Cut off: reading it again the same way would be cut off again, so it is offered as two halves instead.
            ['id' => $this->row($read, 2)->id, 'page_no' => 2, 'page_to' => null, 'pass' => 'rows', 'part' => 'full', 'reason' => 'cut_off', 'can_retry' => false, 'can_split' => true, 'can_stronger' => false, 'needs_picture' => false],
            // An unusable answer: again, or again with the stronger model.
            ['id' => $this->row($read, 3)->id, 'page_no' => 3, 'page_to' => null, 'pass' => 'rows', 'part' => 'full', 'reason' => 'invalid_output', 'can_retry' => true, 'can_split' => false, 'can_stronger' => true, 'needs_picture' => false],
            // Lost to a timeout: again, but a stronger model would not have helped.
            ['id' => $this->row($read, 4)->id, 'page_no' => 4, 'page_to' => null, 'pass' => 'rows', 'part' => 'full', 'reason' => 'timeout', 'can_retry' => true, 'can_split' => false, 'can_stronger' => false, 'needs_picture' => false],
        ], $body['failed_pages']);

        // The phone gets the same body.
        $this->assertSame($body, $this->api($this->owner, 'GET', $read)->assertOk()->json());
    }

    public function test_what_is_offered_for_a_failed_page_follows_the_rules(): void
    {
        $read = $this->mixed();
        $offers = fn () => collect($this->web($this->owner, 'GET', $read, '/status')->json('failed_pages'))->keyBy('page_no');

        // An unusable answer is tried again twice at most.
        $this->row($read, 3)->update(['behaviour_failures' => 3]);
        $this->assertFalse($offers()[3]['can_retry']);
        $this->assertFalse($offers()[3]['can_stronger']);
        $this->row($read, 3)->update(['behaviour_failures' => 2]);
        $this->assertTrue($offers()[3]['can_retry']);

        // A company on a trial is never offered the stronger model.
        $this->organization->update(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(10)]);
        $this->assertTrue($offers()[3]['can_retry']);
        $this->assertFalse($offers()[3]['can_stronger']);
        $this->organization->update(['plan_status' => 'active']);

        // A kind that does not offer halves offers nothing for a cut-off page.
        Support\FakeKind::$cutOff = 'fail';
        $this->assertFalse($offers()[2]['can_split']);
        Support\FakeKind::$cutOff = 'offer_halves';

        // Once the read is closed, or its files are gone, nothing more can be sent.
        $read->update(['files_purged_at' => now()]);
        $this->assertSame([false, false, false], [$offers()[2]['can_split'], $offers()[3]['can_retry'], $offers()[4]['can_retry']]);
    }

    public function test_a_paused_or_ended_read_carries_the_sentence_that_explains_it(): void
    {
        $paused = $this->draftWithPages(2, ['status' => DocumentRead::PAUSED, 'pause_reason' => 'wallet', 'credits_cap' => '30']);

        $this->web($this->owner, 'GET', $paused, '/status')->assertOk()->assertJson([
            'status' => 'paused', 'pause_reason' => 'wallet', 'message' => 'Your company has no AI credits left. Buy credits, then continue.',
            'eta_minutes' => null, 'can_resume' => true, 'can_cancel' => true, 'pages_left' => 2,
        ]);

        $used = $this->draftWithPages(1, ['status' => DocumentRead::PAUSED, 'pause_reason' => 'many_failures', 'resumes' => 1]);
        $this->web($this->owner, 'GET', $used, '/status')->assertJson(['can_resume' => false]);

        $cancelled = $this->draft(['status' => DocumentRead::CANCELLED, 'error' => 'consent_withdrawn']);
        $this->web($this->owner, 'GET', $cancelled, '/status')->assertOk()->assertJson([
            'status' => 'cancelled', 'error' => 'consent_withdrawn', 'message' => 'Your company switched off the AI readers, so it was cancelled.', 'can_cancel' => false, 'can_resume' => false,
        ]);

        // In the person's own language.
        $this->owner->update(['locale' => 'hi']);
        $words = $this->web($this->owner->refresh(), 'GET', $paused, '/status')->json('message');
        $this->assertSame(__('Your company has no AI credits left. Buy credits, then continue.', [], 'hi'), $words);
        $this->assertNotSame('Your company has no AI credits left. Buy credits, then continue.', $words);
    }

    public function test_a_read_the_server_has_not_picked_up_is_reported_as_stalled(): void
    {
        $read = $this->startedRead(null, '60', ['project_id' => $this->project->id, 'started_at' => now()->subMinutes(4), 'last_dispatched_at' => now()]);
        $page = $this->storedPage($read, 1);

        $this->web($this->owner, 'GET', $read, '/status')->assertJson(['stalled' => true, 'status' => 'running']);

        // Once a page has gone to the reader the server has started, however long the page takes.
        $page->update(['status' => DocumentReadPage::RUNNING, 'attempts' => 1, 'claimed_at' => now()]);
        $this->web($this->owner, 'GET', $read, '/status')->assertJson(['stalled' => false]);

        $fresh = $this->startedRead(null, '60', ['project_id' => $this->project->id, 'started_at' => now()->subMinute(), 'last_dispatched_at' => now()]);
        $this->storedPage($fresh, 1);
        $this->web($this->owner, 'GET', $fresh, '/status')->assertJson(['stalled' => false]);
    }

    public function test_asking_for_the_status_keeps_a_watched_read_moving(): void
    {
        // Running, with a page waiting, and nothing was ever handed to the queue: the scheduler is not running.
        $read = $this->startedRead(null, '60', ['project_id' => $this->project->id]);
        $waiting = $this->storedPage($read, 1, ['status' => DocumentReadPage::WAITING]);
        $this->assertSame([], $this->queuedPages());

        $this->web($this->owner, 'GET', $read, '/status')->assertOk()->assertJson(['status' => 'running', 'pages_left' => 1]);
        $this->assertSame([$waiting->id], $this->queuedPages());

        // A page whose worker died long ago is given its second try.
        $lost = $this->startedRead(null, '60', ['project_id' => $this->project->id, 'last_dispatched_at' => now()]);
        $page = $this->storedPage($lost, 1, ['status' => DocumentReadPage::RUNNING, 'attempts' => 1, 'attempt_uuid' => fake()->uuid(), 'claimed_at' => now()->subSeconds(ReadTending::LOST_PAGE_SECONDS + 5)]);

        $this->api($this->owner, 'GET', $lost)->assertOk();
        $this->assertSame(DocumentReadPage::QUEUED, $page->refresh()->status);
        $this->assertSame(1, (int) $page->auto_requeued);

        // A read with nothing left is finished by the asking.
        $done = $this->startedRead(null, '60', ['project_id' => $this->project->id, 'last_dispatched_at' => now()->subMinutes(2)]);
        $this->storedPage($done, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1]);
        $this->web($this->owner, 'GET', $done, '/status')->assertJson(['status' => 'ready', 'pages_left' => 0, 'eta_minutes' => null]);

        // None of this sends a page: only the worker does.
        Http::assertNothingSent();
    }

    public function test_a_run_of_text_pages_counts_for_every_page_in_it(): void
    {
        $read = $this->startedRead(null, '60', ['kind' => 'tender', 'project_id' => null, 'pages_total' => 16, 'last_dispatched_at' => now()]);
        ReadFiles::putTexts($read, array_fill(1, 16, 'Tender notice text.'));
        $this->makePage($read, 1, ['pass' => 'text', 'page_to' => 8, 'text_chars' => 800, 'image_width' => null, 'image_height' => null, 'status' => DocumentReadPage::QUEUED]);
        $this->makePage($read, 9, ['pass' => 'text', 'page_to' => 16, 'text_chars' => 800, 'image_width' => null, 'image_height' => null, 'status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off', 'needs_picture' => true, 'attempts' => 1]);

        $body = $this->web($this->owner, 'GET', $read, '/status')->assertOk()->json();

        $this->assertSame(8, $body['pages_left']);
        $this->assertSame([], $body['stored_pages']);
        $this->assertSame(1, $body['eta_minutes']);
        $this->assertSame(['page_no' => 9, 'page_to' => 16, 'pass' => 'text', 'part' => 'full', 'reason' => 'cut_off', 'can_retry' => false, 'can_split' => false, 'can_stronger' => false, 'needs_picture' => true], array_slice($body['failed_pages'][0], 1));
    }

    public function test_the_stored_page_picture_is_served_as_it_goes_to_the_reader(): void
    {
        $read = $this->draft();
        $this->upload($read, 1)->assertStatus(201);
        $page = $this->row($read, 1);
        $bytes = Storage::get($page->image_path);

        $response = $this->actingAs($this->owner)->get("/document-reads/{$read->id}/pages/{$page->id}/image")->assertOk();

        $this->assertSame($bytes, $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('ETag', '"'.$page->image_sha256.'"');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        // The browser asks again each time, because a page can be replaced before the start, and is told when nothing changed.
        $this->actingAs($this->owner)->get("/document-reads/{$read->id}/pages/{$page->id}/image", ['If-None-Match' => '"'.$page->image_sha256.'"'])->assertStatus(304);

        // The phone fetches it under /api.
        $this->assertSame($bytes, $this->api($this->owner, 'GET', $read, "/pages/{$page->id}/image")->assertOk()->streamedContent());
    }

    public function test_a_picture_that_is_gone_is_a_404(): void
    {
        $read = $this->draft();
        $this->upload($read, 1)->assertStatus(201);
        $page = $this->row($read, 1);
        $address = "/document-reads/{$read->id}/pages/{$page->id}/image";

        // A row whose path points outside the read's own folder is never served.
        $other = $this->draft();
        $this->upload($other, 1)->assertStatus(201);
        $page->update(['image_path' => $this->row($other, 1)->image_path, 'image_sha256' => null]);
        $this->actingAs($this->owner)->get($address)->assertNotFound();
        $page->update(['image_path' => ReadFiles::page($read, 1, 'rows', 'full')]);

        // The file was deleted from the disk.
        Storage::delete($page->image_path);
        $this->actingAs($this->owner)->get($address)->assertNotFound();

        // The read's files were deleted after the keeping period: the row no longer names a file.
        $read->update(['files_purged_at' => now()]);
        $this->actingAs($this->owner)->get($address)->assertNotFound();

        // A text page has no picture.
        $text = $this->makePage($read, 2, ['pass' => 'amounts', 'image_path' => null]);
        $read->update(['files_purged_at' => null]);
        $this->actingAs($this->owner)->get("/document-reads/{$read->id}/pages/{$text->id}/image")->assertNotFound();
    }

    public function test_a_page_is_only_reached_through_its_own_read(): void
    {
        $read = $this->draft();
        $other = $this->draft();
        $this->upload($read, 1)->assertStatus(201);
        $this->upload($other, 1)->assertStatus(201);
        $foreign = $this->row($other, 1);

        $this->actingAs($this->owner)->get("/document-reads/{$read->id}/pages/{$foreign->id}/image")->assertNotFound();
        $this->web($this->owner, 'POST', $read, "/pages/{$foreign->id}/retry")->assertNotFound();
        $this->web($this->owner, 'POST', $read, "/pages/{$foreign->id}/split")->assertNotFound();
        $this->api($this->owner, 'GET', $read, "/pages/{$foreign->id}/image")->assertNotFound();
        $this->api($this->owner, 'POST', $read, "/pages/{$foreign->id}/retry")->assertNotFound();
        $this->api($this->owner, 'POST', $read, "/pages/{$foreign->id}/split")->assertNotFound();

        $this->assertSame(DocumentReadPage::WAITING, $foreign->refresh()->status);
    }
}
