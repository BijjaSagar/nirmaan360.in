<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\ReadQuote;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

/**
 * The estimate, the limit offered and the lowest limit allowed, worked out from the price table.
 *
 * One A4 page of a BOQ is 4,756 picture tokens and an assumed 1,200 of instructions. Its first pass
 * on Sonnet typically writes 4,500 tokens (6.6588 credits) and its second on Haiku 1,200 (0.1400),
 * so a page is estimated at 6.7988 credits and can cost 12.2276 at the very most.
 */
class ReadQuoteTest extends ReadingTestCase
{
    private Organization $organization;

    private User $owner;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->release();
        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
        $this->engineer = $this->member($this->organization, 'engineer');
    }

    private function quote(): ReadQuote
    {
        return app(ReadQuote::class);
    }

    private function boq(int $pages, ?Organization $organization = null): DocumentRead
    {
        $read = $this->makeRead($organization ?? $this->organization, $this->owner);

        foreach (range(1, $pages) as $number) {
            $this->makePage($read, $number);
        }

        return $read;
    }

    public function test_one_page_of_a_boq_counts_both_of_its_passes(): void
    {
        $this->assertSame([
            'pages' => 1,
            'calls' => 2,
            'estimate' => '6.7988',
            'worst' => '12.2276',
            'cap' => '11.0000',
            'cap_floor' => '9.0000',
            'company_max' => '650.0000',
            'rupees' => '6.80',
            'minutes' => 2,
            'free' => '500.0000',
            'enough' => true,
            'pages_that_fit' => 1,
            'basis' => 'default',
        ], $this->quote()->forRead($this->boq(1), $this->owner));
    }

    public function test_twelve_pages_give_the_limit_and_the_floor_of_the_worked_example(): void
    {
        $quote = $this->quote()->forRead($this->boq(12), $this->owner);

        $this->assertSame(12, $quote['pages']);
        $this->assertSame(24, $quote['calls']);
        $this->assertSame('81.5856', $quote['estimate']);
        $this->assertSame('146.7312', $quote['worst']);
        // 81.5856 x 1.6 = 130.54 and x 1.2 = 97.90, each rounded up to a whole credit.
        $this->assertSame('131.0000', $quote['cap']);
        $this->assertSame('98.0000', $quote['cap_floor']);
        $this->assertSame('81.59', $quote['rupees']);
        // Twelve pages at 75 and 20 seconds each, one at a time.
        $this->assertSame(19, $quote['minutes']);
        $this->assertTrue($quote['enough']);
    }

    public function test_the_limit_never_passes_the_companys_maximum_for_one_read(): void
    {
        $this->setCredits($this->organization, '500', '1000');
        $read = $this->boq(60);

        $quote = $this->quote()->forRead($read, $this->owner);

        $this->assertSame('407.9280', $quote['estimate']);
        $this->assertSame('733.6560', $quote['worst']);
        $this->assertSame('650.0000', $quote['cap']);
        $this->assertSame('490.0000', $quote['cap_floor']);
        $this->assertSame('650.0000', $quote['company_max']);
        $this->assertTrue($quote['enough']);
        $this->assertSame(60, $quote['pages_that_fit']);

        // The owner lowers the maximum: now only as many pages fit as their floor allows.
        AiDocumentSettings::for($this->organization)->update(['max_credits_per_read' => '100']);

        $quote = $this->quote()->forRead($read, $this->owner);

        $this->assertSame('100.0000', $quote['company_max']);
        $this->assertFalse($quote['enough']);
        // Twelve pages need a floor of 98, thirteen would need 107.
        $this->assertSame(12, $quote['pages_that_fit']);
        // A limit below the floor is never offered, even though the read cannot start as it is.
        $this->assertSame('490.0000', $quote['cap']);

        $inRange = $this->quote()->forRead($read, $this->owner, 1, 12);
        $this->assertTrue($inRange['enough']);
        $this->assertSame('100.0000', $inRange['cap']);
        $this->assertSame('98.0000', $inRange['cap_floor']);
    }

    public function test_a_read_larger_than_the_free_balance_says_how_many_pages_fit(): void
    {
        $read = $this->boq(60);
        $this->setCredits($this->organization, '100');

        $quote = $this->quote()->forRead($read, $this->owner);

        $this->assertFalse($quote['enough']);
        $this->assertSame(12, $quote['pages_that_fit']);
        $this->assertSame('100.0000', $quote['free']);
        $this->assertSame('650.0000', $quote['cap']);

        // The offered limit is brought down to what can be held when the floor fits.
        $twelve = $this->quote()->forRead($read, $this->owner, 1, 12);
        $this->assertTrue($twelve['enough']);
        $this->assertSame('100.0000', $twelve['cap']);
        $this->assertSame('98.0000', $twelve['cap_floor']);

        // Credits another read holds are not free for this one.
        $this->runningRead($this->organization, $this->owner, '30');
        $this->assertFalse($this->quote()->forRead($read, $this->owner, 1, 12)['enough']);
        $this->assertSame(8, $this->quote()->forRead($read, $this->owner, 1, 12)['pages_that_fit']);
    }

    public function test_someone_who_is_not_an_owner_or_admin_is_quoted_within_the_hold_share_and_sees_no_balance(): void
    {
        $read = $this->boq(60);

        $quote = $this->quote()->forRead($read, $this->engineer);

        // Half of 500 is within reach: thirty pages need a floor of 245, thirty-one would need 253.
        $this->assertFalse($quote['enough']);
        $this->assertSame(30, $quote['pages_that_fit']);
        $this->assertNull($quote['free']);

        $thirty = $this->quote()->forRead($read, $this->engineer, 1, 30);
        $this->assertTrue($thirty['enough']);
        $this->assertSame('250.0000', $thirty['cap']);
        $this->assertSame('245.0000', $thirty['cap_floor']);
        $this->assertNull($thirty['free']);

        // The quote and the hold agree: what is offered can be held, a credit more cannot.
        $this->credits()->hold($read, $thirty['cap'], $this->engineer);
        $this->assertSame('250.0000', $read->fresh()->credits_held);

        // A project manager may see the credits page, so the balance is shown.
        $this->assertSame('250.0000', $this->quote()->forRead($this->boq(1), $this->member($this->organization, 'pm'))['free']);
    }

    public function test_a_trial_company_is_quoted_within_its_lower_maximum_and_its_page_allowance(): void
    {
        $trial = $this->trialTenant();
        $starter = $this->member($trial, 'owner');

        $five = $this->quote()->forRead($this->boq(5, $trial), $starter);

        $this->assertSame('60.0000', $five['company_max']);
        $this->assertSame('33.9940', $five['estimate']);
        $this->assertSame('55.0000', $five['cap']);
        $this->assertSame('41.0000', $five['cap_floor']);
        $this->assertTrue($five['enough']);

        // Seven pages need a floor of 58, eight would need 66.
        $ten = $this->quote()->forRead($this->boq(10, $trial), $starter);
        $this->assertFalse($ten['enough']);
        $this->assertSame(7, $ten['pages_that_fit']);

        // With eighteen of its twenty pages already sent, only two more fit, whatever they cost.
        $sent = $this->makeRead($trial, $starter, ['status' => DocumentRead::READY]);

        foreach (range(1, 18) as $number) {
            $this->makePage($sent, $number, ['status' => 'read', 'attempts' => 1]);
        }

        $again = $this->quote()->forRead($this->boq(5, $trial), $starter);
        $this->assertFalse($again['enough']);
        $this->assertSame(2, $again['pages_that_fit']);

        // Second passes of pages that were already sent take nothing more from the allowance.
        $this->makePage($sent, 19, ['status' => 'read', 'attempts' => 1]);
        $this->makePage($sent, 20, ['status' => 'read', 'attempts' => 1]);
        $this->makePage($sent, 19, ['pass' => 'amounts', 'model' => 'claude-haiku-5-5']);
        $this->makePage($sent, 20, ['pass' => 'amounts', 'model' => 'claude-haiku-5-5']);

        $rest = $this->quote()->forRead($sent, $starter);
        $this->assertSame(2, $rest['pages']);
        $this->assertSame('0.2800', $rest['estimate']);
        $this->assertTrue($rest['enough']);
        $this->assertSame(0, $this->quote()->forRead($this->boq(1, $trial), $starter)['pages_that_fit']);
    }

    public function test_only_pages_still_waiting_in_the_asked_range_are_quoted(): void
    {
        $read = $this->boq(6);
        $read->pages()->where('page_no', 2)->update(['status' => 'read']);
        $read->pages()->where('page_no', 5)->update(['status' => 'skipped']);
        $this->boq(4);

        $this->assertSame(4, $this->quote()->forRead($read, $this->owner)['pages']);

        $quote = $this->quote()->forRead($read, $this->owner, 2, 5);
        $this->assertSame(2, $quote['pages']);
        $this->assertSame('13.5976', $quote['estimate']);

        $none = $this->quote()->forRead($read, $this->owner, 40, 50);
        $this->assertSame(0, $none['pages']);
        $this->assertSame('0.0000', $none['estimate']);
        $this->assertSame('0.0000', $none['cap']);
        $this->assertSame(0, $none['minutes']);
        $this->assertFalse($none['enough']);
    }

    public function test_a_second_pass_that_has_its_own_row_is_counted_once_and_halves_are_one_page(): void
    {
        // Page 1 has been read; its amounts pass is waiting with a row of its own.
        $read = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::PAUSED]);
        $this->makePage($read, 1, ['status' => 'read']);
        $this->makePage($read, 1, ['pass' => 'amounts', 'model' => 'claude-haiku-5-5']);

        $quote = $this->quote()->forRead($read, $this->owner);
        $this->assertSame(['pages' => 1, 'calls' => 1, 'estimate' => '0.1400', 'worst' => '0.3038'], array_intersect_key($quote, array_flip(['pages', 'calls', 'estimate', 'worst'])));
        // For a small sum the worst case is below a whole credit, so it is both the limit and the floor.
        $this->assertSame('0.3038', $quote['cap']);
        $this->assertSame('0.3038', $quote['cap_floor']);
        $this->assertSame(1, $quote['minutes']);

        // Page 3 was cut off and is read again as a top and a bottom half, each a smaller picture.
        $halves = $this->makeRead($this->organization, $this->owner);
        $this->makePage($halves, 3, ['part' => 'top', 'image_height' => 1263]);
        $this->makePage($halves, 3, ['part' => 'bottom', 'image_height' => 1263]);

        $quote = $this->quote()->forRead($halves, $this->owner);
        $this->assertSame(1, $quote['pages']);
        $this->assertSame(4, $quote['calls']);
        $this->assertSame('12.5714', $quote['estimate']);
        $this->assertSame(1, $quote['pages_that_fit']);
    }

    public function test_a_run_of_text_pages_uses_its_counted_tokens_or_its_length(): void
    {
        $read = $this->makeRead($this->organization, $this->owner, ['kind' => 'tender']);
        $run = $this->makePage($read, 12, ['page_to' => 19, 'pass' => 'text', 'image_width' => null, 'image_height' => null, 'text_chars' => 20000, 'input_tokens' => 9000]);

        $quote = $this->quote()->forRead($read, $this->owner);

        $this->assertSame(8, $quote['pages']);
        $this->assertSame(1, $quote['calls']);
        $this->assertSame('5.0310', $quote['estimate']);
        $this->assertSame('11.4660', $quote['worst']);
        $this->assertSame('9.0000', $quote['cap']);
        $this->assertSame('7.0000', $quote['cap_floor']);
        $this->assertSame(8, $quote['pages_that_fit']);

        // Not counted beforehand: one token a character, plus the instructions.
        $run->update(['input_tokens' => 0]);
        $this->assertSame('7.8858', $this->quote()->forRead($read, $this->owner)['estimate']);

        // A page that must be read as a picture and has none stored yet is priced as the largest picture.
        $this->makePage($read, 20, ['pass' => 'image', 'image_width' => null, 'image_height' => null, 'needs_picture' => true]);
        $this->assertSame('3.1553', $this->quote()->forPages($read, [$read->pages()->where('page_no', 20)->sole()], $this->owner)['estimate']);
    }

    public function test_a_row_set_to_the_stronger_model_is_priced_at_that_model(): void
    {
        $read = $this->makeRead($this->organization, $this->owner);
        $page = $this->makePage($read, 1, ['model' => 'claude-opus-5-5']);

        // The first pass on Opus, the pass that follows on its usual model.
        $this->assertSame('13.4575', $this->quote()->forPages($read, [$page], $this->owner)['estimate']);
    }

    public function test_the_typical_figures_become_the_median_once_enough_pages_have_been_read(): void
    {
        $other = $this->tenant('Konkan Infra');
        $done = $this->makeRead($other, null, ['status' => DocumentRead::READY]);
        $read = fn (int $number, array $values = []) => $this->makePage($done, $number, ['status' => 'read', 'attempts' => 1, 'output_tokens' => 2000 + $number * 10, 'latency_ms' => 30000 + $number * 100, ...$values]);

        foreach (range(1, 49) as $number) {
            $read($number);
        }

        // Pages of another kind, another pass, or that failed, tell nothing about this one.
        $slips = $this->makeRead($other, null, ['kind' => 'slip', 'status' => DocumentRead::READY]);

        foreach (range(1, 60) as $number) {
            $this->makePage($slips, $number, ['pass' => 'main', 'status' => 'read', 'output_tokens' => 99]);
        }

        $read(900, ['status' => 'failed', 'output_tokens' => 9000]);
        $read(901, ['pass' => 'amounts', 'output_tokens' => 50]);

        $this->assertSame(['output' => 4500, 'seconds' => 75, 'measured' => false], $this->quote()->typical('boq', 'rows'));

        $read(50);

        // Fifty values from 2,010 to 2,500 and from 30.1 to 35 seconds.
        $this->assertSame(['output' => 2255, 'seconds' => 33, 'measured' => true], $this->quote()->typical('boq', 'rows'));
        $this->assertSame(['output' => 1200, 'seconds' => 20, 'measured' => false], $this->quote()->typical('boq', 'amounts'));
        $this->assertSame(['output' => 99, 'seconds' => 1, 'measured' => true], $this->quote()->typical('slip', 'main'));

        $quote = $this->quote()->forRead($this->boq(1), $this->owner);
        $this->assertSame('4.1721', $quote['estimate']);
        $this->assertSame('12.2276', $quote['worst']);
        $this->assertSame(1, $quote['minutes']);
        $this->assertSame('mixed', $quote['basis']);

        // Only the newest pages inside the window are looked at, and never more than a call may write.
        config(['modules.document_reading.typical.window' => 3, 'modules.document_reading.typical.minimum' => 3]);
        $read(60, ['output_tokens' => 50000]);
        $read(61, ['output_tokens' => 60000]);

        $this->assertSame(9000, $this->quote()->typical('boq', 'rows')['output']);
        $this->assertTrue($this->quote()->typical('boq', 'amounts')['measured'] === false);
    }

    public function test_the_median_is_looked_up_once_in_a_while_not_for_every_quote(): void
    {
        config(['modules.document_reading.typical.cache_seconds' => 600, 'modules.document_reading.typical.minimum' => 2]);
        $done = $this->makeRead($this->organization, null, ['status' => DocumentRead::READY]);
        $this->makePage($done, 1, ['status' => 'read', 'output_tokens' => 3000, 'latency_ms' => 10000]);
        $this->makePage($done, 2, ['status' => 'read', 'output_tokens' => 3000, 'latency_ms' => 10000]);

        $this->assertSame(3000, $this->quote()->typical('boq', 'rows')['output']);

        $this->makePage($done, 3, ['status' => 'read', 'output_tokens' => 8000, 'latency_ms' => 10000]);
        $this->makePage($done, 4, ['status' => 'read', 'output_tokens' => 8000, 'latency_ms' => 10000]);
        $this->assertSame(3000, $this->quote()->typical('boq', 'rows')['output']);

        $this->travel(601)->seconds();
        $this->assertSame(5500, $this->quote()->typical('boq', 'rows')['output']);
    }

    public function test_the_limit_a_read_starts_with_can_only_be_lowered_and_never_below_the_floor(): void
    {
        $quote = $this->quote()->forRead($this->boq(12), $this->owner);

        $this->assertSame('131.0000', $this->quote()->capFor($quote, null));
        $this->assertSame('131.0000', $this->quote()->capFor($quote, 'as much as it takes'));
        $this->assertSame('120.5000', $this->quote()->capFor($quote, '120.5'));
        $this->assertSame('131.0000', $this->quote()->capFor($quote, '5000'));
        $this->assertSame('98.0000', $this->quote()->capFor($quote, '10'));
        $this->assertSame('98.0000', $this->quote()->capFor($quote, '-3'));
    }

    public function test_a_one_shot_action_states_the_most_it_can_use_in_whole_credits(): void
    {
        // The largest accepted picture, the assumed instructions, and the most the call may write.
        $this->assertSame('1.0000', $this->quote()->upTo('slip', 'main'));
        $this->assertSame('4.0000', $this->quote()->upTo('slip', 'main', 'claude-sonnet-5-5'));
        $this->assertSame('12.0000', $this->quote()->upTo('boq', 'rows'));
        $this->assertSame('24.0000', $this->quote()->upTo('boq', 'rows', config('modules.document_reading.kinds.boq.passes.rows.stronger')));

        // A text call prices its own request.
        $this->assertSame('0.2106', $this->quote()->worst('claude-haiku-5-5', 3000, 3000));
    }

    public function test_the_instructions_are_counted_once_for_each_wording_when_a_key_is_saved(): void
    {
        $body = [
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 9000,
            'system' => [['type' => 'text', 'text' => 'Copy what is printed.']],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Page 1 of 1.']]]],
            'output_config' => ['effort' => 'low'],
        ];
        $counts = [1480, 1510];
        $sent = [];

        Http::fake(['api.anthropic.com/v1/messages/count_tokens' => function (Request $request) use (&$counts, &$sent) {
            $sent[] = $request->data();

            return Http::response(['input_tokens' => array_shift($counts)]);
        }]);

        // No key saved: nothing is asked, and the assumption stands.
        $this->assertSame(1200, $this->quote()->measurePrompt('boq', 'rows', $body));
        $this->assertSame([], $sent);

        $this->connect();

        $this->assertSame(1480, $this->quote()->measurePrompt('boq', 'rows', $body));
        $this->assertSame(1480, $this->quote()->measurePrompt('boq', 'rows', $body));
        $this->assertCount(1, $sent);
        $this->assertArrayNotHasKey('max_tokens', $sent[0]);
        $this->assertSame('claude-sonnet-5-5', $sent[0]['model']);

        $this->assertSame(1480, $this->quote()->promptTokens('boq', 'rows'));
        $this->assertSame(1200, $this->quote()->promptTokens('boq', 'amounts'));

        // 4,756 + 1,480 tokens in on the first pass; the second pass is still at the assumed figure.
        $this->assertSame('6.8643', $this->quote()->forRead($this->boq(1), $this->owner)['estimate']);

        // New wording, new count.
        $body['system'][0]['text'] = 'Copy what is printed, exactly.';
        $this->assertSame(1510, $this->quote()->measurePrompt('boq', 'rows', $body));
        $this->assertCount(2, $sent);
        $this->assertSame(1510, $this->quote()->promptTokens('boq', 'rows'));
    }

    public function test_a_count_that_fails_leaves_the_assumption_in_place(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/v1/messages/count_tokens' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);

        $this->assertSame(1200, $this->quote()->measurePrompt('slip', 'main', ['model' => 'claude-haiku-5-5', 'messages' => []]));
        $this->assertSame(1200, $this->quote()->promptTokens('slip', 'main'));
        $this->assertNull(Cache::get('ai.prompt_tokens:slip:main'));
    }

    public function test_a_kind_or_pass_without_figures_is_an_error_not_a_guess(): void
    {
        $read = $this->makeRead($this->organization, $this->owner, ['kind' => 'muster']);
        $this->makePage($read, 1, ['pass' => 'main']);

        $this->expectException(RuntimeException::class);
        $this->quote()->forRead($read, $this->owner);
    }

    public function test_a_quote_reads_only_its_own_companys_pages_and_balance(): void
    {
        $other = $this->tenant('Konkan Infra');
        $theirs = $this->boq(3, $other);
        $this->setCredits($other, '40');
        $mine = $this->boq(2);

        // The same page numbers exist in both reads. Each quote counts its own.
        $this->assertSame(2, $this->quote()->forRead($mine, $this->owner)['pages']);
        $this->assertSame('500.0000', $this->quote()->forRead($mine, $this->owner)['free']);

        $quote = $this->quote()->forRead($theirs, $this->member($other, 'owner'));
        $this->assertSame(3, $quote['pages']);
        $this->assertSame('40.0000', $quote['free']);
        $this->assertSame('33.0000', $quote['cap']);
        $this->assertTrue($quote['enough']);

        // Nobody is quoted for another company's read, and a page is never priced into another read.
        foreach ([fn () => $this->quote()->forRead($theirs, $this->owner), fn () => $this->quote()->forPages($mine, $theirs->pages, $this->owner)] as $mixed) {
            try {
                $mixed();
                $this->fail('A quote crossed companies.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }
}
