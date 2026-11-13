<?php

namespace Tests\Feature\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadQuote;
use App\Support\Dec;
use Illuminate\Support\Facades\Http;

/**
 * Having one page read again, and having a cut-off page read as two halves, from the first request
 * to the page coming back from the reader.
 */
class ReadRetryTest extends ReadRoutesTestCase
{
    /** A read that finished: page 1 was read, and page 2 ended the way the test says. */
    private function finished(array $second = [], array $overrides = []): DocumentRead
    {
        $read = $this->startedRead(null, '0', [
            'status' => DocumentRead::READY, 'project_id' => $this->project->id, 'pages_total' => 2,
            'credits_cap' => '40', 'credits_used' => '7', 'credits_burned' => '6', 'pages_read' => 1, 'pages_failed' => 1, 'finished_at' => now(),
            ...$overrides,
        ]);

        $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1, 'finished_at' => now()]);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'invalid_output', 'error_detail' => 'rows', 'attempts' => 1, 'behaviour_failures' => 1, 'finished_at' => now(), ...$second]);

        return $read;
    }

    private function quoteFor(DocumentRead $read, DocumentReadPage ...$pages): array
    {
        return app(ReadQuote::class)->forPages($read->refresh(), $pages, $this->owner);
    }

    public function test_a_failed_page_is_read_again_out_of_what_is_left_of_the_limit(): void
    {
        $read = $this->finished();
        $page = $this->row($read, 2);
        $quote = $this->quoteFor($read, $page);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")
            ->assertStatus(202)->assertJson(['status' => 'running', 'pages_read' => 1, 'pages_failed' => 0, 'pages_left' => 1, 'failed_pages' => [], 'credits_cap' => '40.0000']);

        $page->refresh();
        $this->assertSame(DocumentReadPage::QUEUED, $page->status);
        $this->assertNull($page->fail_reason);
        $this->assertNull($page->error_detail);
        $this->assertSame('claude-sonnet-5-5', $page->model);
        $this->assertSame([$page->id], $this->queuedPages());

        $read->refresh();
        // 27 credits of the limit were left; the hold is no more than this one page and its second pass can cost.
        $this->assertSame($quote['worst'], (string) $read->credits_held);
        $this->assertTrue(Dec::of($read->credits_held)->isLessThanOrEqualTo(Dec::of('27')));
        $this->assertSame('40.0000', (string) $read->credits_cap);

        $audit = $this->audits('ai.page_retry')[0];
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame(['status' => 'failed', 'fail_reason' => 'invalid_output'], $audit->old_values);
        $this->assertSame(['read_id' => $read->id, 'kind' => 'boq', 'page_no' => 2, 'pass' => 'rows', 'part' => 'full', 'model' => 'claude-sonnet-5-5', 'stronger' => false, 'attempts_so_far' => 1], $audit->new_values);

        // The page goes to the reader, comes back read, is charged, and the read is ready again with both pages read.
        $this->fakeReader([$this->answer('other')]);
        $this->readPage($page);

        $read->refresh();
        $this->assertSame(DocumentRead::READY, $read->status);
        $this->assertSame([2, 0], [(int) $read->pages_read, (int) $read->pages_failed]);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertTrue(Dec::of($read->credits_used)->isGreaterThan(Dec::of('7')));
        $this->assertSame(2, (int) $page->refresh()->attempts);
        $this->assertLedgerMatchesWallet($this->organization);
    }

    public function test_a_repeated_request_finds_the_page_already_on_its_way(): void
    {
        $read = $this->finished();
        $page = $this->row($read, 2);

        $this->api($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(202);
        $this->api($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'running', 'page_status' => 'queued']);

        $this->assertCount(1, $this->audits('ai.page_retry'));
        $this->assertCount(1, $this->queuedPages());
    }

    public function test_a_cut_off_page_and_a_page_with_too_many_unusable_answers_are_not_sent_again(): void
    {
        $cutOff = $this->finished(['fail_reason' => 'cut_off']);
        $this->web($this->owner, 'POST', $cutOff, '/pages/'.$this->row($cutOff, 2)->id.'/retry')
            ->assertStatus(409)->assertExactJson(['code' => 'cut_off', 'message' => 'The answer for this page was too long to fit, so reading it again the same way would not help.']);

        // Two more tries are offered after the first unusable answer. After the third, none.
        $twice = $this->finished(['behaviour_failures' => 2]);
        $this->web($this->owner, 'POST', $twice, '/pages/'.$this->row($twice, 2)->id.'/retry')->assertStatus(202);

        $spent = $this->finished(['behaviour_failures' => 3, 'fail_reason' => 'declined']);
        $this->web($this->owner, 'POST', $spent, '/pages/'.$this->row($spent, 2)->id.'/retry')
            ->assertStatus(409)->assertExactJson(['code' => 'retries_used', 'message' => 'This page has been read again as often as is offered. Type this page by hand.']);

        // A page lost to a timeout or an outage is nobody's fault and can be sent as often as needed.
        $unlucky = $this->finished(['fail_reason' => 'timeout', 'behaviour_failures' => 0, 'attempts' => 9]);
        $this->web($this->owner, 'POST', $unlucky, '/pages/'.$this->row($unlucky, 2)->id.'/retry')->assertStatus(202);

        foreach ([$cutOff, $spent] as $read) {
            $this->assertSame(DocumentReadPage::FAILED, $this->row($read, 2)->status);
            $this->assertSame(DocumentRead::READY, $read->refresh()->status);
            $this->assertSame('0.0000', (string) $read->credits_held);
        }
    }

    public function test_the_stronger_model_is_used_only_where_it_is_offered(): void
    {
        // A generous limit: the stronger model costs several times the usual one.
        $read = $this->finished([], ['credits_cap' => '150']);
        $page = $this->row($read, 2);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry", ['stronger' => true])->assertStatus(202);
        $this->assertSame('claude-opus-5-5', $page->refresh()->model);
        $this->assertTrue($this->audits('ai.page_retry')[0]->new_values['stronger']);

        // It is what the reader is then asked with.
        $this->fakeReader([$this->answer('other')]);
        $this->readPage($page);
        $this->assertSame('claude-opus-5-5', $this->sent[0]['model']);

        // A page that was read can be read once more with it, when a person wants that. A later plain try goes back to the usual model.
        $this->web($this->owner, 'POST', $read, '/pages/'.$this->row($read, 1)->id.'/retry', ['stronger' => true])->assertStatus(202);
        $this->assertSame('claude-opus-5-5', $this->row($read, 1)->model);
        $this->lifecycle()->cancel($read->refresh());

        $plain = $this->finished();
        $this->row($plain, 2)->update(['model' => 'claude-opus-5-5']);
        $this->web($this->owner, 'POST', $plain, '/pages/'.$this->row($plain, 2)->id.'/retry')->assertStatus(202);
        $this->assertSame('claude-sonnet-5-5', $this->row($plain, 2)->model);

        // Not for a timeout, where a stronger model would not have helped.
        $timeout = $this->finished(['fail_reason' => 'timeout', 'behaviour_failures' => 0]);
        $this->web($this->owner, 'POST', $timeout, '/pages/'.$this->row($timeout, 2)->id.'/retry', ['stronger' => true])
            ->assertStatus(409)->assertExactJson(['code' => 'not_offered', 'message' => 'The stronger model is not offered for this page.']);
        $this->assertSame(DocumentReadPage::FAILED, $this->row($timeout, 2)->status);

        // Not for a pass that has none.
        $slip = $this->finished([], ['kind' => 'slip']);
        $this->row($slip, 2)->update(['pass' => 'recheck']);
        $this->web($this->owner, 'POST', $slip, '/pages/'.$this->row($slip, 2, 'recheck')->id.'/retry', ['stronger' => true])->assertStatus(409)->assertJson(['code' => 'not_offered']);
    }

    public function test_a_company_on_a_trial_is_never_given_the_stronger_model(): void
    {
        $this->organization->update(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(10)]);
        $read = $this->finished();
        $page = $this->row($read, 2);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry", ['stronger' => true])->assertStatus(409)->assertJson(['code' => 'not_offered']);
        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(202);
        $this->assertSame('claude-sonnet-5-5', $page->refresh()->model);
    }

    public function test_a_page_of_a_running_read_is_read_again_out_of_the_hold_the_read_already_has(): void
    {
        $read = $this->finished([], ['status' => DocumentRead::RUNNING, 'credits_held' => '20', 'last_dispatched_at' => now()]);
        $this->storedPage($read, 3, ['status' => DocumentReadPage::RUNNING, 'attempts' => 1, 'claimed_at' => now()]);
        $page = $this->row($read, 2);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(202)->assertJson(['status' => 'running', 'pages_failed' => 0]);

        $this->assertSame('20.0000', (string) $read->refresh()->credits_held);
        // One page at a time: it waits until the page that is with the reader comes back.
        $this->assertSame(DocumentReadPage::WAITING, $page->refresh()->status);
        $this->assertSame([], $this->queuedPages());
        $this->assertSame([], $this->audits('ai.read_hold'));
    }

    public function test_the_brake_on_unusable_answers_also_stops_a_page_being_sent_again(): void
    {
        config(['modules.document_reading.failed_pages_per_day' => 1]);

        // The failed page itself is today's one unusable answer.
        foreach ([$this->finished(), $this->finished([], ['status' => DocumentRead::RUNNING, 'credits_held' => '20', 'last_dispatched_at' => now()])] as $read) {
            $page = $this->row($read, 2);

            $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(409)->assertJson(['code' => 'company_limit', 'limit' => 'brake']);
            $this->assertSame(DocumentReadPage::FAILED, $page->refresh()->status);
            $this->assertSame('invalid_output', $page->fail_reason);
        }

        $this->assertSame([], $this->queuedPages());
        $this->assertSame([], $this->audits('ai.page_retry'));
    }

    public function test_a_page_is_read_again_only_while_its_read_can_still_send(): void
    {
        foreach ([DocumentRead::PAUSED, DocumentRead::CANCELLED, DocumentRead::CONFIRMED, DocumentRead::EXPIRED, DocumentRead::DRAFT] as $status) {
            $read = $this->finished([], ['status' => $status]);
            $this->web($this->owner, 'POST', $read, '/pages/'.$this->row($read, 2)->id.'/retry')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => $status]);
        }

        // The page pictures were deleted after the keeping period.
        $purged = $this->finished([], ['files_purged_at' => now()]);
        $this->web($this->owner, 'POST', $purged, '/pages/'.$this->row($purged, 2)->id.'/retry')->assertStatus(409)->assertJson(['code' => 'wrong_state']);

        $this->assertSame([], $this->queuedPages());
    }

    public function test_when_the_limit_no_longer_covers_a_page_a_new_limit_must_be_named(): void
    {
        $read = $this->finished([], ['credits_cap' => '13.5']);
        $page = $this->row($read, 2);
        $quote = $this->quoteFor($read, $page);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(409)->assertJson([
            'code' => 'limit_reached', 'pages' => 1, 'cap' => $quote['cap'], 'cap_floor' => $quote['cap_floor'], 'cap_left' => '0.5000',
        ]);
        $this->assertSame(DocumentReadPage::FAILED, $page->refresh()->status);
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry", ['cap' => $quote['cap']])->assertStatus(202);

        $read->refresh();
        $this->assertSame((string) Dec::of('13')->plus(Dec::of($quote['cap']))->toScale(4), (string) $read->credits_cap);
        $this->assertSame($quote['cap'], (string) $read->credits_held);
    }

    public function test_the_wallet_decides_whether_a_page_can_be_read_again(): void
    {
        $read = $this->finished();
        $page = $this->row($read, 2);
        $this->setCredits($this->organization, '0.2');

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(402)->assertJson(['code' => 'no_credits', 'limit' => 'wallet', 'pages_that_fit' => 0]);

        $this->assertSame(DocumentReadPage::FAILED, $page->refresh()->status);
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);
    }

    public function test_a_page_that_was_left_out_at_the_start_can_be_read_later(): void
    {
        $read = $this->draftWithPages(3);
        $this->web($this->owner, 'POST', $read, '/start', ['page_from' => 1, 'page_to' => 2])->assertStatus(202);
        $leftOut = $this->row($read, 3);
        $this->assertSame(DocumentReadPage::SKIPPED, $leftOut->status);

        $this->web($this->owner, 'POST', $read, "/pages/{$leftOut->id}/retry")->assertStatus(202)->assertJson(['pages_left' => 3, 'left_out_pages' => []]);
        $this->assertSame(DocumentReadPage::WAITING, $leftOut->refresh()->status);
    }

    /** A finished read whose second page was cut off, with or without its halves uploaded. */
    private function cutOff(array $halves = ['top', 'bottom']): DocumentRead
    {
        $read = $this->finished(['fail_reason' => 'cut_off']);

        foreach ($halves as $part) {
            $this->upload($read, 2, $part, $this->picture(640, 620))->assertStatus(201);
        }

        return $read;
    }

    public function test_a_cut_off_page_is_read_as_its_two_halves(): void
    {
        $read = $this->cutOff();
        $whole = $this->row($read, 2);
        $top = $this->row($read, 2, 'rows', 'top');
        $bottom = $this->row($read, 2, 'rows', 'bottom');
        $quote = $this->quoteFor($read, $top, $bottom);

        $this->web($this->owner, 'POST', $read, "/pages/{$whole->id}/split")->assertStatus(202)->assertJson(['status' => 'running', 'pages_read' => 1, 'pages_failed' => 0, 'pages_left' => 1, 'failed_pages' => []]);

        // The whole page's failed try is set aside; its halves take its place, the top one first.
        $this->assertSame(DocumentReadPage::SUPERSEDED, $whole->refresh()->status);
        $this->assertSame([DocumentReadPage::QUEUED, DocumentReadPage::WAITING], [$top->refresh()->status, $bottom->refresh()->status]);
        $this->assertSame([$top->id], $this->queuedPages());
        $this->assertSame($quote['worst'], (string) $read->refresh()->credits_held);
        $this->assertSame(['read_id' => $read->id, 'kind' => 'boq', 'page_no' => 2, 'pass' => 'rows', 'status' => 'superseded'], $this->audits('ai.page_split')[0]->new_values);

        // Each half is sent with its own picture and told which half it is. Then the page counts as read.
        $this->fakeReader([$this->answer('other')]);
        $this->readPage($top);
        $this->readPage($bottom->refresh());

        $this->assertStringContainsString('top half of page 2', json_encode($this->sent[0]['messages']));
        $this->assertStringContainsString('bottom half of page 2', json_encode($this->sent[1]['messages']));
        $this->assertSame($this->base64Of($bottom), $this->sent[1]['messages'][0]['content'][0]['source']['data']);

        $read->refresh();
        $this->assertSame(DocumentRead::READY, $read->status);
        $this->assertSame([2, 0], [(int) $read->pages_read, (int) $read->pages_failed]);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertLedgerMatchesWallet($this->organization);

        // Asking twice finds the page already replaced by its halves.
        $this->api($this->owner, 'POST', $read, "/pages/{$whole->id}/split")->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'ready', 'page_status' => 'superseded']);
    }

    public function test_both_halves_must_be_there_before_either_is_sent(): void
    {
        $read = $this->cutOff(['top']);
        $whole = $this->row($read, 2);

        $this->web($this->owner, 'POST', $read, "/pages/{$whole->id}/split")
            ->assertStatus(409)->assertExactJson(['code' => 'pages_missing', 'missing' => ['bottom'], 'message' => 'Both halves of the page must be uploaded before they can be read.']);

        $none = $this->cutOff([]);
        $this->web($this->owner, 'POST', $none, '/pages/'.$this->row($none, 2)->id.'/split')->assertStatus(409)->assertJson(['code' => 'pages_missing', 'missing' => ['top', 'bottom']]);

        $this->assertSame(DocumentReadPage::FAILED, $whole->refresh()->status);
        $this->assertSame(DocumentReadPage::SKIPPED, $this->row($read, 2, 'rows', 'top')->status);
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);
        $this->assertSame([], $this->queuedPages());
        Http::assertNothingSent();
    }

    public function test_only_a_cut_off_page_of_a_kind_that_offers_halves_is_split(): void
    {
        // A page that failed for another reason, a page that was read, and a half itself.
        $read = $this->finished();
        $this->web($this->owner, 'POST', $read, '/pages/'.$this->row($read, 2)->id.'/split')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'page_status' => 'failed']);
        $this->web($this->owner, 'POST', $read, '/pages/'.$this->row($read, 1)->id.'/split')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'page_status' => 'read']);

        $cutOff = $this->cutOff();
        $this->web($this->owner, 'POST', $cutOff, '/pages/'.$this->row($cutOff, 2, 'rows', 'top')->id.'/split')->assertStatus(409)->assertJson(['code' => 'wrong_state']);
        // A half that is waiting for its other half is not "read again" on its own either.
        $this->web($this->owner, 'POST', $cutOff, '/pages/'.$this->row($cutOff, 2, 'rows', 'top')->id.'/retry')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'page_status' => 'skipped']);

        Support\FakeKind::$cutOff = 'fail';
        $this->web($this->owner, 'POST', $cutOff, '/pages/'.$this->row($cutOff, 2)->id.'/split')->assertStatus(409)->assertJson(['code' => 'wrong_state']);

        $this->assertSame([], $this->queuedPages());
    }

    public function test_a_read_that_is_read_again_goes_out_under_the_notice_agreed_to_today(): void
    {
        $read = $this->finished([], ['consent_version' => 'ai-docs-v0']);
        $page = $this->row($read, 2);

        $this->web($this->owner, 'POST', $read, "/pages/{$page->id}/retry")->assertStatus(202);
        $this->assertSame(AiDocumentConsent::VERSION, $read->refresh()->consent_version);

        // So the page is sent, instead of pausing the read as one agreed to under an older notice.
        $this->fakeReader([$this->answer('other')]);
        $this->readPage($page);
        $this->assertCount(1, $this->sent);
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);
    }
}
