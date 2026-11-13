<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadQuote;
use App\Support\Dec;
use Illuminate\Support\Facades\Http;

/**
 * Asking the price of a read, starting it, cancelling it and continuing it after a pause, through
 * the shared routes.
 */
class ReadStartTest extends ReadRoutesTestCase
{
    /** The quote the server itself works out for the waiting pages, to compare an answer with. */
    private function quoteOf(DocumentRead $read, ?int $from = null, ?int $to = null): array
    {
        return app(ReadQuote::class)->forRead($read->refresh(), $this->owner, $from, $to);
    }

    public function test_the_price_is_an_estimate_with_a_limit_and_marks_the_read_as_quoted(): void
    {
        $read = $this->draftWithPages(3);

        $response = $this->web($this->owner, 'POST', $read, '/quote')->assertOk();

        $quote = $response->json();
        $this->assertSame(['pages', 'calls', 'estimate', 'worst', 'cap', 'cap_floor', 'company_max', 'rupees', 'minutes', 'free', 'enough', 'pages_that_fit', 'basis'], array_keys($quote));
        $this->assertSame(3, $quote['pages']);
        $this->assertTrue($quote['enough']);
        $this->assertSame(3, $quote['pages_that_fit']);
        $this->assertSame('default', $quote['basis']);
        $this->assertSame('650.0000', $quote['company_max']);
        $this->assertSame('500.0000', $quote['free']);
        $this->assertTrue(Dec::of($quote['cap_floor'])->isLessThanOrEqualTo(Dec::of($quote['cap'])));
        $this->assertTrue(Dec::of($quote['estimate'])->isLessThan(Dec::of($quote['cap'])));

        $read->refresh();
        $this->assertSame(DocumentRead::QUOTED, $read->status);
        $this->assertSame($quote['estimate'], (string) $read->credits_estimate);
        $this->assertNotNull($read->quoted_at);

        // Asking the price holds nothing, sends nothing and queues nothing.
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertSame('500.0000', $this->credits()->free($this->organization));
        $this->assertSame([], $this->queuedPages());
        Http::assertNothingSent();
    }

    public function test_a_price_can_be_asked_for_a_range_of_pages_and_the_balance_is_shown_only_to_those_who_may_see_it(): void
    {
        $read = $this->draftWithPages(3);
        $engineer = $this->member($this->organization, 'engineer');

        $some = $this->api($engineer, 'POST', $read, '/quote', ['page_from' => 2, 'page_to' => 3])->assertOk()->json();

        $this->assertSame(2, $some['pages']);
        // An engineer may start a read but may not open the credits page, so no balance and no rupee figure: credits, and whether it fits.
        $this->assertNull($some['free']);
        $this->assertNull($some['rupees']);
        $this->assertTrue($some['enough']);
        $this->assertSame($this->quoteOf($read, 2, 3)['estimate'], $some['estimate']);

        // A project manager may open it, and sees both.
        $pm = $this->api($this->member($this->organization, 'pm'), 'POST', $read, '/quote', ['page_from' => 2, 'page_to' => 3])->json();
        $this->assertSame($this->quoteOf($read, 2, 3)['rupees'], $pm['rupees']);
        $this->assertNotNull($pm['free']);

        $this->web($this->owner, 'POST', $read, '/quote', ['page_from' => 3, 'page_to' => 2])->assertStatus(422)->assertJsonValidationErrors('page_to');
        $this->web($this->owner, 'POST', $read, '/quote', ['page_from' => 0])->assertStatus(422)->assertJsonValidationErrors('page_from');

        // A read with nothing uploaded has no price, and stays a draft.
        $empty = $this->draft();
        $this->web($this->owner, 'POST', $empty, '/quote')->assertOk()->assertJson(['pages' => 0, 'enough' => false]);
        $this->assertSame(DocumentRead::DRAFT, $empty->refresh()->status);
    }

    public function test_a_price_is_only_asked_before_the_start_or_while_paused(): void
    {
        foreach ([DocumentRead::RUNNING, DocumentRead::READY, DocumentRead::FAILED, DocumentRead::CANCELLED, DocumentRead::CONFIRMED] as $status) {
            $read = $this->draftWithPages(1, ['status' => $status]);
            $this->web($this->owner, 'POST', $read, '/quote')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => $status]);
        }

        // For a paused read it is the price of what is left, and the read stays paused.
        $paused = $this->draftWithPages(2, ['status' => DocumentRead::PAUSED, 'pause_reason' => 'credit_cap', 'credits_cap' => '10', 'credits_used' => '10']);
        $this->web($this->owner, 'POST', $paused, '/quote')->assertOk()->assertJson(['pages' => 2]);
        $this->assertSame(DocumentRead::PAUSED, $paused->refresh()->status);
    }

    public function test_starting_holds_the_limit_and_hands_the_first_page_to_the_queue(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $read = $this->draftWithPages(3, ['consent_version' => null]);
        $quote = $this->quoteOf($read);

        $response = $this->web($engineer, 'POST', $read, '/start')->assertStatus(202);

        $response->assertJson([
            'id' => $read->id, 'kind' => 'boq', 'status' => 'running', 'pause_reason' => null,
            'pages_total' => 3, 'pages_read' => 0, 'pages_failed' => 0, 'pages_left' => 3, 'stored_pages' => [1, 2, 3],
            'credits_used' => '0.0000', 'credits_burned' => '0.0000', 'credits_cap' => $quote['cap'], 'stalled' => false, 'failed_pages' => [],
        ]);

        $read->refresh();
        $this->assertSame(DocumentRead::RUNNING, $read->status);
        $this->assertSame($quote['cap'], (string) $read->credits_cap);
        $this->assertSame($quote['cap'], (string) $read->credits_held);
        $this->assertSame($quote['estimate'], (string) $read->credits_estimate);
        // The read now goes out in the name of the person who started it, under the notice the company agrees to today.
        $this->assertSame($engineer->id, $read->user_id);
        $this->assertSame(AiDocumentConsent::VERSION, $read->consent_version);
        $this->assertNotNull($read->started_at);
        $this->assertNotNull($read->quoted_at);

        // One page at a time: the first is queued, the others wait.
        $this->assertSame([$this->row($read, 1)->id], $this->queuedPages());
        $this->assertSame([DocumentReadPage::QUEUED, DocumentReadPage::WAITING, DocumentReadPage::WAITING], array_map(fn ($page) => $page->status, $this->rows($read)));

        // The held credits are no longer free for anything else.
        $this->assertSame((string) Dec::of('500')->minus(Dec::of($quote['cap']))->toScale(4), $this->credits()->free($this->organization));

        $audit = $this->audits('ai.read_started')[0];
        $this->assertSame($engineer->id, $audit->user_id);
        $this->assertSame(['kind' => 'boq', 'pages' => 3, 'cap' => $quote['cap'], 'consent_version' => AiDocumentConsent::VERSION, 'channel' => 'web'], $audit->new_values);
        // Starting sends nothing by itself: the worker does, page by page.
        Http::assertNothingSent();
    }

    public function test_a_limit_sent_by_the_person_can_only_lower_the_offered_one_and_never_below_the_floor(): void
    {
        $quote = $this->quoteOf($this->draftWithPages(3));
        $between = (string) Dec::of($quote['cap_floor'])->plus(1)->toScale(4);
        $this->assertTrue(Dec::of($between)->isLessThan(Dec::of($quote['cap'])), 'The fixture leaves room between the floor and the offer.');

        foreach (['9999' => $quote['cap'], '0.5' => $quote['cap_floor'], $between => $between] as $asked => $expected) {
            $read = $this->draftWithPages(3);

            $this->api($this->owner, 'POST', $read, '/start', ['cap' => (string) $asked])->assertStatus(202)->assertJson(['credits_cap' => $expected]);
            $this->assertSame($expected, (string) $read->refresh()->credits_held);

            $this->lifecycle()->cancel($read);
        }

        $this->web($this->owner, 'POST', $this->draftWithPages(1), '/start', ['cap' => 'plenty'])->assertStatus(422)->assertJsonValidationErrors('cap');
    }

    public function test_only_the_pages_of_the_range_are_read_and_pages_the_person_left_out_are_set_aside(): void
    {
        $read = $this->draftWithPages(5);

        $this->web($this->owner, 'POST', $read, '/start', ['page_from' => 2, 'page_to' => 5, 'skip_pages' => [4]])
            ->assertStatus(202)->assertJson(['pages_left' => 3, 'left_out_pages' => [['id' => $this->row($read, 1)->id, 'page_no' => 1], ['id' => $this->row($read, 4)->id, 'page_no' => 4]]]);

        $this->assertSame(
            [1 => DocumentReadPage::SKIPPED, 2 => DocumentReadPage::QUEUED, 3 => DocumentReadPage::WAITING, 4 => DocumentReadPage::SKIPPED, 5 => DocumentReadPage::WAITING],
            collect($this->rows($read))->mapWithKeys(fn ($page) => [(int) $page->page_no => $page->status])->all(),
        );
        $this->assertSame(3, $this->audits('ai.read_started')[0]->new_values['pages']);
        $this->assertSame((string) $this->quoteOf($this->draftWithPages(3))['cap'], (string) $read->refresh()->credits_cap);
    }

    public function test_a_read_does_not_start_while_pages_of_the_range_are_missing(): void
    {
        $read = $this->draft(['pages_total' => 6]);

        foreach ([1, 2, 5] as $number) {
            $this->storedPage($read, $number, ['status' => DocumentReadPage::WAITING]);
        }

        $this->web($this->owner, 'POST', $read, '/start')
            ->assertStatus(409)->assertExactJson(['code' => 'pages_missing', 'missing' => [3, 4, 6], 'message' => 'Some pages are not uploaded yet. Upload them, then start again.']);
        $this->api($this->owner, 'POST', $read, '/start', ['page_from' => 1, 'page_to' => 4, 'skip_pages' => [3]])->assertStatus(409)->assertJson(['code' => 'pages_missing', 'missing' => [4]]);

        $read->refresh();
        $this->assertSame(DocumentRead::DRAFT, $read->status);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertSame([], $this->queuedPages());
        $this->assertSame([], $this->audits('ai.read_started'));
        $this->assertSame(3, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('status', DocumentReadPage::WAITING)->count());

        // The range that is complete starts.
        $this->web($this->owner, 'POST', $read, '/start', ['page_from' => 1, 'page_to' => 2])->assertStatus(202);

        // Nothing uploaded at all is the same refusal, with nothing to list when the read was opened without a page count.
        $this->web($this->owner, 'POST', $this->draft(['pages_total' => 0]), '/start')->assertStatus(409)->assertJson(['code' => 'pages_missing', 'missing' => []]);
    }

    public function test_a_read_that_is_more_than_one_document_may_use_is_not_started_as_it_is(): void
    {
        $read = $this->draftWithPages(4);
        $onePage = Dec::of($this->quoteOf($this->draftWithPages(1))['cap_floor']);
        // The owner has lowered what one document may use to a little more than two pages need.
        AiDocumentSettings::for($this->organization)->update(['max_credits_per_read' => (string) $onePage->multipliedBy(2)->plus(1)]);

        $response = $this->web($this->owner, 'POST', $read, '/start')->assertStatus(409)->assertJson(['code' => 'over_limit', 'message' => 'This is more than one document may use. Choose fewer pages.']);
        $fits = $response->json('pages_that_fit');
        $this->assertContains($fits, [1, 2]);
        $this->assertSame(DocumentRead::DRAFT, $read->refresh()->status);

        // The pages that fit can be started as a range.
        $this->web($this->owner, 'POST', $read, '/start', ['page_from' => 1, 'page_to' => $fits])->assertStatus(202);
    }

    public function test_a_read_the_wallet_cannot_cover_answers_402_and_says_how_many_pages_would_fit(): void
    {
        $read = $this->draftWithPages(4);
        $onePage = Dec::of($this->quoteOf($this->draftWithPages(1))['cap_floor']);
        $this->setCredits($this->organization, (string) $onePage->plus('0.5'));

        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(402)->assertExactJson([
            'code' => 'no_credits', 'limit' => 'wallet', 'pages_that_fit' => 1,
            'message' => 'Your company does not have enough AI credits for this. Buy more or choose fewer pages.',
        ]);

        // Someone who is not an owner or admin may hold only part of the wallet, so the rest stays free for other work.
        $this->setCredits($this->organization, (string) $onePage->multipliedBy(6));
        $engineer = $this->member($this->organization, 'engineer');
        $this->api($engineer, 'POST', $read, '/start')->assertStatus(402)->assertJson(['code' => 'no_credits', 'limit' => 'hold_share']);

        $read->refresh();
        $this->assertSame(DocumentRead::DRAFT, $read->status);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertSame([], $this->queuedPages());

        // The owner can.
        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(202);
    }

    public function test_a_daily_budget_or_a_stopped_company_refuses_the_start_with_its_own_code(): void
    {
        $read = $this->draftWithPages(2);
        $settings = AiDocumentSettings::for($this->organization);

        $settings->update(['budgets' => ['user' => ['boq' => '1']]]);
        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(402)->assertJson(['code' => 'daily_budget', 'feature' => 'boq', 'scope' => 'user']);

        $settings->update(['budgets' => ['company' => ['total' => '2']]]);
        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(402)->assertJson(['code' => 'daily_budget', 'feature' => 'total', 'scope' => 'company']);

        $settings->update(['budgets' => null]);
        config(['modules.document_reading.failed_pages_per_day' => 0]);
        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(409)->assertJson(['code' => 'company_limit', 'limit' => 'brake']);

        $read->refresh();
        $this->assertSame(DocumentRead::DRAFT, $read->status);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertSame([], $this->queuedPages());
        $this->assertSame([], $this->audits('ai.read_started'));
    }

    public function test_a_read_starts_once(): void
    {
        $read = $this->draftWithPages(2);

        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(202);
        $held = (string) $read->refresh()->credits_held;

        // A repeat, such as the phone sending again after a lost answer, is told the state it finds.
        $this->api($this->owner, 'POST', $read, '/start')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'running']);

        $this->assertSame($held, (string) $read->refresh()->credits_held);
        $this->assertCount(1, $this->audits('ai.read_started'));
        $this->assertCount(1, $this->queuedPages());

        foreach ([DocumentRead::PAUSED, DocumentRead::READY, DocumentRead::CANCELLED] as $status) {
            $other = $this->draftWithPages(1, ['status' => $status]);
            $this->web($this->owner, 'POST', $other, '/start')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => $status]);
        }
    }

    public function test_a_read_that_is_not_finished_can_be_cancelled_and_gives_its_hold_back(): void
    {
        $read = $this->draftWithPages(3);
        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(202);
        $engineer = $this->member($this->organization, 'engineer');

        $this->web($engineer, 'POST', $read, '/cancel')->assertOk()->assertJson(['status' => 'cancelled', 'can_cancel' => false, 'pages_left' => 0]);

        $read->refresh();
        $this->assertSame(DocumentRead::CANCELLED, $read->status);
        $this->assertSame('0.0000', (string) $read->credits_held);
        $this->assertSame('500.0000', $this->credits()->free($this->organization));
        $this->assertSame([DocumentReadPage::SKIPPED], array_values(array_unique(array_map(fn ($page) => $page->status, $this->rows($read)))));
        $this->assertSame($engineer->id, $this->audits('ai.read_cancelled')[0]->user_id);

        // Asking again changes nothing. The page job that is still in the queue finds its page set aside and sends nothing.
        $this->api($engineer, 'POST', $read, '/cancel')->assertOk()->assertJson(['status' => 'cancelled']);
        $this->assertCount(1, $this->audits('ai.read_cancelled'));
        $this->readPage($this->queuedPages()[0]);
        Http::assertNothingSent();

        // A draft can be cancelled too. A read that finished cannot: discarding what was read is the feature's own step.
        $this->web($this->owner, 'POST', $this->draft(), '/cancel')->assertOk()->assertJson(['status' => 'cancelled']);
        $done = $this->draft(['status' => DocumentRead::READY]);
        $this->web($this->owner, 'POST', $done, '/cancel')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'ready']);
        $this->assertSame(DocumentRead::READY, $done->refresh()->status);
    }

    /** A read that paused after one of its three pages, with this much of its limit used. */
    private function paused(string $reason, string $cap = '40', string $used = '7', array $overrides = []): DocumentRead
    {
        $read = $this->draft(['status' => DocumentRead::PAUSED, 'pause_reason' => $reason, 'credits_cap' => $cap, 'credits_used' => $used, 'started_at' => now()->subMinutes(5), ...$overrides]);
        $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1]);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->storedPage($read, 3, ['status' => DocumentReadPage::WAITING]);

        return $read;
    }

    public function test_a_paused_read_continues_with_what_is_left_of_its_limit(): void
    {
        $read = $this->paused('provider_limit', '40', '7', ['consent_version' => 'ai-docs-v0']);
        $quote = $this->quoteOf($read);
        $this->assertTrue(Dec::of($quote['cap_floor'])->isLessThan(Dec::of('33')), 'What is left covers the remaining pages.');

        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(202)->assertJson(['status' => 'running', 'pause_reason' => null, 'pages_left' => 2, 'credits_cap' => '40.0000']);

        $read->refresh();
        // The hold is what is left of the limit, but no more than the remaining pages can cost.
        $expected = Dec::of('33')->isLessThan(Dec::of($quote['worst'])) ? '33.0000' : $quote['worst'];
        $this->assertSame($expected, (string) $read->credits_held);
        $this->assertSame('40.0000', (string) $read->credits_cap);
        $this->assertSame(0, (int) $read->resumes);
        $this->assertSame(AiDocumentConsent::VERSION, $read->consent_version);
        $this->assertSame([$this->row($read, 2)->id], $this->queuedPages());

        $audit = $this->audits('ai.read_resumed')[0];
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame(['status' => 'paused', 'pause_reason' => 'provider_limit'], $audit->old_values);
        $this->assertSame(['status' => 'running', 'kind' => 'boq', 'pages' => 2, 'held' => $expected, 'cap' => '40.0000', 'consent_version' => AiDocumentConsent::VERSION], $audit->new_values);

        // Only a paused read is continued.
        $this->api($this->owner, 'POST', $read, '/resume')->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'running']);
    }

    public function test_a_read_whose_limit_is_used_up_continues_only_when_a_new_limit_is_named(): void
    {
        $read = $this->paused('credit_cap', '40', '39.5');
        $quote = $this->quoteOf($read);

        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(409)->assertExactJson([
            'code' => 'limit_reached', 'pages' => 2, 'estimate' => $quote['estimate'], 'cap' => $quote['cap'], 'cap_floor' => $quote['cap_floor'], 'cap_left' => '0.5000',
            'message' => 'The credit limit set for this document does not cover this. Set a new limit to go on.',
        ]);
        $this->assertSame(DocumentRead::PAUSED, $read->refresh()->status);
        $this->assertSame([], $this->queuedPages());

        $this->web($this->owner, 'POST', $read, '/resume', ['cap' => $quote['cap']])->assertStatus(202)->assertJson(['status' => 'running']);

        $read->refresh();
        // The new limit is what was used so far plus what was just agreed for the rest.
        $this->assertSame((string) Dec::of('39.5')->plus(Dec::of($quote['cap']))->toScale(4), (string) $read->credits_cap);
        $this->assertSame($quote['cap'], (string) $read->credits_held);
        $this->assertSame($quote['cap'], $read->capLeft());
    }

    public function test_the_most_one_document_may_use_counts_what_the_read_has_used_already(): void
    {
        $read = $this->paused('credit_cap', '40', '39.5');
        $quote = $this->quoteOf($read);

        // The owner has lowered what one document may use to less than this read needs on top of what it used.
        AiDocumentSettings::for($this->organization)->update(['max_credits_per_read' => '45']);
        $this->web($this->owner, 'POST', $read, '/resume', ['cap' => $quote['cap']])
            ->assertStatus(409)->assertExactJson(['code' => 'over_limit', 'pages_that_fit' => 0, 'message' => 'This document has reached the most one document may use.']);
        $this->assertSame(DocumentRead::PAUSED, $read->refresh()->status);

        // With room for the floor but not for the whole offer, the new limit stops at the most a document may use.
        $room = (string) Dec::of($quote['cap_floor'])->plus('1')->toScale(4);
        AiDocumentSettings::for($this->organization)->update(['max_credits_per_read' => (string) Dec::of('39.5')->plus(Dec::of($room))]);
        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(409)->assertJson(['code' => 'limit_reached', 'cap' => $room, 'cap_floor' => $quote['cap_floor']]);
        $this->web($this->owner, 'POST', $read, '/resume', ['cap' => '9999'])->assertStatus(202);

        $read->refresh();
        $this->assertSame($room, (string) $read->credits_held);
        $this->assertSame((string) Dec::of('39.5')->plus(Dec::of($room))->toScale(4), (string) $read->credits_cap);
    }

    public function test_a_read_paused_for_too_many_unusable_pages_is_continued_once(): void
    {
        $read = $this->paused('many_failures');

        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(202);
        $this->assertSame(1, (int) $read->refresh()->resumes);

        $this->lifecycle()->pause($read, DocumentRead::PAUSE_MANY_FAILURES, notify: false);

        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(409)->assertJson(['code' => 'resume_used']);
        $this->assertSame(DocumentRead::PAUSED, $read->refresh()->status);
        $this->assertSame('0.0000', (string) $read->credits_held);

        // Another kind of pause on the same read can still be continued.
        $read->update(['pause_reason' => DocumentRead::PAUSE_INFRA]);
        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(202);
    }

    public function test_someone_else_can_take_over_a_read_whose_starter_is_gone(): void
    {
        $starter = $this->member($this->organization, 'engineer');
        $read = $this->paused('starter_gone', '40', '7', ['user_id' => $starter->id]);
        $starter->update(['is_active' => false]);
        $other = $this->member($this->organization, 'pm');

        $this->web($other, 'POST', $read, '/resume')->assertStatus(202);
        $this->assertSame($other->id, $read->refresh()->user_id);

        // While the starter can still send, the read stays in their name whoever continues it.
        $kept = $this->paused('provider_limit', '40', '7', ['user_id' => $other->id]);
        $this->web($this->owner, 'POST', $kept, '/resume')->assertStatus(202);
        $this->assertSame($other->id, $kept->refresh()->user_id);
    }

    public function test_a_paused_read_with_nothing_left_to_send_is_finished_instead(): void
    {
        $read = $this->draft(['status' => DocumentRead::PAUSED, 'pause_reason' => 'credit_cap', 'credits_cap' => '10', 'credits_used' => '10']);
        $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1]);

        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(202)->assertJson(['status' => 'ready', 'pages_left' => 0]);
        $this->assertSame('0.0000', (string) $read->refresh()->credits_held);
        $this->assertSame([], $this->queuedPages());
    }

    public function test_the_wallet_still_decides_when_a_read_is_continued(): void
    {
        $read = $this->paused('wallet');
        $this->setCredits($this->organization, '0.5');

        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(402)->assertJson(['code' => 'no_credits', 'limit' => 'wallet']);

        $this->assertSame(DocumentRead::PAUSED, $read->refresh()->status);
        $this->assertSame('wallet', $read->pause_reason);
    }
}
