<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\SpendRefused;
use Illuminate\Support\Carbon;
use Tests\Feature\DocumentReading\Support\BringsTextCalls;

/** Text calls under the same limits as page reads: budgets, the trial allowance, the brakes and the platform's totals. */
class AiSpendTextCallsTest extends ReadingTestCase
{
    use BringsTextCalls;

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

    private function spend(): AiSpend
    {
        return app(AiSpend::class);
    }

    private function refusal(callable $work): SpendRefused
    {
        try {
            $work();
        } catch (SpendRefused $refused) {
            return $refused;
        }

        $this->fail('Nothing was refused.');
    }

    public function test_a_persons_voice_budget_stops_the_twenty_first_credit(): void
    {
        // 17.5 charged today on finished calls, and one call still running that may cost up to 2.
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'voice', 'status' => 'done', 'credits' => '10.25', 'max_credits' => '12']);
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'voice', 'status' => 'done', 'credits' => '7.25', 'max_credits' => '12']);
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'voice', 'status' => 'running', 'max_credits' => '2']);

        // Not this person's, not this purpose, not today, not this company.
        $this->textCall($this->organization, $this->owner, ['purpose' => 'voice', 'status' => 'done', 'credits' => '9']);
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'translate', 'status' => 'done', 'credits' => '9']);
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'voice', 'status' => 'done', 'credits' => '9', 'created_at' => now()->subDays(2)]);
        $this->textCall($this->tenant('Konkan Infra'), null, ['purpose' => 'voice', 'status' => 'done', 'credits' => '9']);

        $this->assertSame('19.5000', (string) $this->spend()->spentToday($this->organization, 'voice', $this->engineer));
        $this->assertSame('28.5000', (string) $this->spend()->spentToday($this->organization, 'voice'));

        $this->spend()->assertRoom($this->organization, $this->engineer, 'voice', '0.5');

        $refused = $this->refusal(fn () => $this->spend()->assertRoom($this->organization, $this->engineer, 'voice', '0.5001'));

        $this->assertSame(402, $refused->status);
        $this->assertSame(['code' => 'daily_budget', 'feature' => 'voice', 'scope' => 'user'], $refused->toArray());
    }

    public function test_the_text_day_starts_at_midnight_in_india(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 00:30:00', 'Asia/Kolkata'));

        $this->textCall($this->organization, $this->engineer, ['status' => 'done', 'credits' => '6', 'created_at' => Carbon::parse('2026-11-12 23:55:00', 'Asia/Kolkata')]);
        $this->textCall($this->organization, $this->engineer, ['status' => 'done', 'credits' => '4', 'created_at' => Carbon::parse('2026-11-13 00:05:00', 'Asia/Kolkata')]);

        $this->assertSame('4.0000', (string) $this->spend()->spentToday($this->organization, 'wording', $this->engineer));

        Carbon::setTestNow();
    }

    public function test_reads_and_text_calls_share_the_company_total(): void
    {
        AiDocumentSettings::for($this->organization)->update(['budgets' => ['company' => ['total' => '40']]]);

        $this->runningRead($this->organization, $this->owner, '25');
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'rate_match', 'status' => 'queued', 'max_credits' => '3']);
        $this->textCall($this->organization, $this->engineer, ['purpose' => 'wording', 'status' => 'done', 'credits' => '1.5']);

        $this->assertSame('29.5000', (string) $this->spend()->spentToday($this->organization, 'total'));
        $this->spend()->assertRoom($this->organization, $this->engineer, 'translate', '10.5');

        $refused = $this->refusal(fn () => $this->spend()->assertRoom($this->organization, $this->engineer, 'translate', '10.5001'));
        $this->assertSame(['code' => 'daily_budget', 'feature' => 'total', 'scope' => 'company'], $refused->toArray());
    }

    public function test_a_trial_company_has_a_fixed_number_of_text_calls(): void
    {
        $trial = $this->trialTenant();
        $member = $this->member($trial, 'owner');

        foreach (range(1, 29) as $ignored) {
            $this->textCall($trial, $member, ['status' => 'done', 'credits' => '0.01', 'max_credits' => '1']);
        }

        $this->assertSame(1, $this->spend()->trialTextCallsLeft($trial));
        $this->assertNull($this->spend()->trialTextCallsLeft($this->organization));
        $this->assertNull($this->spend()->companyStop($trial, textCall: true));
        $this->spend()->assertRoom($trial, $member, 'wording', '1');

        // A call counts however it ended.
        $this->textCall($trial, $member, ['status' => 'rejected', 'max_credits' => '1']);

        $this->assertSame(0, $this->spend()->trialTextCallsLeft($trial));
        $this->assertSame('trial_text_calls', $this->spend()->companyStop($trial, textCall: true));

        $refused = $this->refusal(fn () => $this->spend()->assertRoom($trial, $member, 'wording', '1'));
        $this->assertSame(['code' => 'company_limit', 'limit' => 'trial_text_calls'], $refused->toArray());

        // Its page allowance is a separate count and is untouched.
        $this->assertNull($this->spend()->companyStop($trial));
        $this->assertSame(20, $this->spend()->trialPagesLeft($trial));
    }

    public function test_text_the_guards_refused_counts_toward_the_brake_and_its_cost_is_capped(): void
    {
        $read = $this->makeRead($this->organization, $this->owner);
        $this->makePage($read, 1, ['status' => 'failed', 'fail_reason' => 'declined', 'attempts' => 2, 'behaviour_failures' => 38, 'finished_at' => now()]);

        $this->textCall($this->organization, $this->engineer, ['status' => 'rejected', 'cost_usd' => '0.9']);
        // Failed on the way, or finished and paid for: neither is an unusable answer.
        $this->textCall($this->organization, $this->engineer, ['status' => 'failed', 'cost_usd' => '0.6']);
        $this->textCall($this->organization, $this->engineer, ['status' => 'done', 'credits' => '2', 'cost_usd' => '7']);
        $this->textCall($this->organization, $this->engineer, ['status' => 'rejected', 'created_at' => now()->subHours(25)]);

        $this->assertSame(39, $this->spend()->unusableAnswers($this->organization));
        $this->assertSame('1.500000', (string) $this->spend()->absorbedToday($this->organization)->toScale(6));
        $this->assertNull($this->spend()->companyStop($this->organization));

        $this->textCall($this->organization, $this->engineer, ['status' => 'rejected', 'cost_usd' => '0.4']);

        $this->assertSame(40, $this->spend()->unusableAnswers($this->organization));
        $this->assertSame('brake', $this->spend()->companyStop($this->organization));

        // Uncharged cost reaches its own cap of two dollars with one more refused call.
        $this->spend()->liftBrake($this->organization);
        $this->travel(1)->seconds();
        $this->assertSame(0, $this->spend()->unusableAnswers($this->organization));
        $this->assertNull($this->spend()->companyStop($this->organization));

        $this->textCall($this->organization, $this->engineer, ['status' => 'rejected', 'cost_usd' => '0.1']);
        $this->assertSame('absorbed', $this->spend()->companyStop($this->organization));
    }

    public function test_text_calls_count_in_the_platforms_day_and_month(): void
    {
        $other = $this->tenant('Konkan Infra');

        $this->textCall($this->organization, $this->engineer, ['status' => 'done', 'credits' => '900', 'cost_usd' => '9.5']);
        $this->textCall($other, null, ['status' => 'done', 'credits' => '900', 'cost_usd' => '9.5']);
        $this->textCall($other, null, ['status' => 'done', 'cost_usd' => '70', 'created_at' => now('UTC')->startOfMonth()->subDay()]);

        $this->assertSame('19.000000', (string) $this->spend()->platformCostToday()->toScale(6));
        $this->assertNull($this->spend()->platformStop());
        $this->assertNull($this->spend()->companyStop($this->organization));

        // One company reaches its 40 percent share of the day through text calls alone.
        $this->textCall($this->organization, $this->engineer, ['status' => 'done', 'credits' => '50', 'cost_usd' => '0.5']);
        $this->assertSame('share', $this->spend()->companyStop($this->organization));

        $this->textCall($this->tenant('Deccan Projects'), null, ['status' => 'done', 'credits' => '50', 'cost_usd' => '5.5']);
        $this->assertSame('daily', $this->spend()->platformStop());
        $this->assertSame('25.000000', (string) $this->spend()->platformCostThisMonth()->toScale(6));
    }
}
