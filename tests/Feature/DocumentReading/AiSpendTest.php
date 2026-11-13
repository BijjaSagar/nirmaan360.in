<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\AgentRegistry;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\SpendRefused;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The limits around the wallet: the release switch, daily budgets, each company's brakes, the trial
 * allowance and the platform's own limits. Every company here is on the Business plan with 500 credits.
 */
class AiSpendTest extends ReadingTestCase
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

    private function spend(): AiSpend
    {
        return app(AiSpend::class);
    }

    /** A page that was charged or cost something, on a read of its own, with the record of its one call. */
    private function spentPage(Organization $organization, ?User $by, string $kind, array $page, array $read = []): DocumentRead
    {
        $made = $this->makeRead($organization, $by, ['kind' => $kind, 'status' => DocumentRead::READY, ...$read]);
        $row = $this->makePage($made, 1, ['pass' => 'main', 'status' => 'read', 'attempts' => 1, 'finished_at' => now(), ...$page]);
        $this->settledCall($row, ['cost_usd' => $row->cost_usd, 'credits' => $row->credits, 'finished_at' => $row->finished_at]);

        return $made;
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

    private function assertRoom(string $feature, string $credits, ?User $by = null, ?DocumentRead $for = null, ?Organization $organization = null): void
    {
        $organization ??= $this->organization;

        $this->spend()->assertRoom($organization, $by ?? $this->engineer, $feature, $credits, $for ?? $this->makeRead($organization, null, ['kind' => $feature]));
    }

    // ---- release

    public function test_readers_are_released_only_with_the_switch_on_and_the_monthly_cap_on_record(): void
    {
        $settings = app(PlatformSettings::class);

        foreach ([
            [['ai_readers_released' => null, 'ai_tier_monthly_cap_usd' => null], false],
            [['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => null], false],
            [['ai_readers_released' => null, 'ai_tier_monthly_cap_usd' => '500'], false],
            [['ai_readers_released' => '0', 'ai_tier_monthly_cap_usd' => '500'], false],
            [['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => 'soon'], false],
            [['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '0'], false],
            [['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '500'], true],
        ] as [$values, $released]) {
            $settings->set($values);
            $this->assertSame($released, $this->spend()->released(), json_encode($values));
        }
    }

    public function test_nothing_is_put_on_hold_before_the_readers_are_released(): void
    {
        app(PlatformSettings::class)->set(['ai_readers_released' => null]);
        $read = $this->makeRead($this->organization, $this->owner);

        $refused = $this->refusal(fn () => $this->credits()->hold($read, '10', $this->owner));

        $this->assertSame(409, $refused->status);
        $this->assertSame(['code' => 'not_released'], $refused->toArray());
        $this->assertSame(DocumentRead::QUOTED, $read->fresh()->status);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
    }

    // ---- daily budgets

    public function test_budgets_come_from_the_defaults_unless_the_company_has_set_its_own(): void
    {
        $other = $this->tenant('Konkan Infra');

        $this->assertSame('30.0000', $this->spend()->budget($this->organization, 'user', 'slip'));
        $this->assertSame('150.0000', $this->spend()->budget($this->organization, 'company', 'slip'));
        $this->assertSame('20.0000', $this->spend()->budget($this->organization, 'user', 'voice'));

        // BOQ, bill and tender reads have no daily budget, and the company total is off.
        foreach (['boq', 'bill', 'tender'] as $kind) {
            $this->assertNull($this->spend()->budget($this->organization, 'user', $kind));
            $this->assertNull($this->spend()->budget($this->organization, 'company', $kind));
        }

        $this->assertNull($this->spend()->budget($this->organization, 'company', 'total'));

        AiDocumentSettings::for($this->organization)->update(['budgets' => [
            'user' => ['slip' => '12', 'voice' => 'plenty'],
            'company' => ['boq' => 40.5, 'total' => 300, 'slip' => -5],
        ]]);

        $this->assertSame('12.0000', $this->spend()->budget($this->organization, 'user', 'slip'));
        $this->assertSame('40.5000', $this->spend()->budget($this->organization, 'company', 'boq'));
        $this->assertSame('300.0000', $this->spend()->budget($this->organization, 'company', 'total'));

        // A figure that is not an amount never removes a limit.
        $this->assertSame('20.0000', $this->spend()->budget($this->organization, 'user', 'voice'));
        $this->assertSame('150.0000', $this->spend()->budget($this->organization, 'company', 'slip'));

        $this->assertSame('30.0000', $this->spend()->budget($other, 'user', 'slip'));
        $this->assertNull($this->spend()->budget($other, 'company', 'total'));
    }

    public function test_a_persons_daily_budget_stops_the_credit_that_would_pass_it(): void
    {
        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '25.5']);
        $this->runningRead($this->organization, $this->engineer, '2.5', ['kind' => 'slip']);

        // 25.5 charged and 2.5 on hold: 2 more fit a budget of 30, and not a sliver beyond.
        $this->assertSame('28.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));
        $this->assertRoom('slip', '2');

        $refused = $this->refusal(fn () => $this->assertRoom('slip', '2.0001'));

        $this->assertSame(402, $refused->status);
        $this->assertSame(['code' => 'daily_budget', 'feature' => 'slip', 'scope' => 'user'], $refused->toArray());
        $this->assertStringContainsString('challan photos', $refused->getMessage());

        // Somebody else's budget is their own, and so is another kind of read.
        $this->assertRoom('slip', '30', $this->member($this->organization, 'store'));
        $this->assertRoom('boq', '400');
    }

    public function test_someone_acting_on_another_persons_read_is_told_whose_budget_is_used_up(): void
    {
        $storeKeeper = $this->member($this->organization, 'store');
        $this->spentPage($this->organization, $storeKeeper, 'slip', ['credits' => '29']);
        $read = $this->makeRead($this->organization, $storeKeeper, ['kind' => 'slip']);

        // The owner acts, the store keeper's budget is checked: the sentence names the store keeper.
        $refused = $this->refusal(fn () => $this->spend()->assertRoom($this->organization, $storeKeeper, 'slip', '2', $read, $this->owner));
        $this->assertSame(['code' => 'daily_budget', 'feature' => 'slip', 'scope' => 'user'], $refused->toArray());
        $this->assertSame("{$storeKeeper->name} has used their AI budget for challan photos today, and this document counts against that budget. The budget starts again tomorrow.", $refused->getMessage());

        // Acting on one's own read, or naming nobody else, it is one's own budget.
        foreach ([$storeKeeper, null] as $by) {
            $own = $this->refusal(fn () => $this->spend()->assertRoom($this->organization, $storeKeeper, 'slip', '2', $read, $by));
            $this->assertSame('You have used your AI budget for challan photos today. It starts again tomorrow.', $own->getMessage());
        }

        // A company budget is everyone's, so its sentence is the same whoever acts.
        AiDocumentSettings::for($this->organization)->update(['budgets' => ['company' => ['slip' => '30'], 'user' => ['slip' => '100']]]);
        $company = $this->refusal(fn () => $this->spend()->assertRoom($this->organization, $storeKeeper, 'slip', '2', $read, $this->owner));
        $this->assertSame(['code' => 'daily_budget', 'feature' => 'slip', 'scope' => 'company'], $company->toArray());
        $this->assertStringStartsWith('Your company has used its AI budget for challan photos today.', $company->getMessage());
    }

    public function test_the_company_budget_counts_everyone_together(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->spentPage($this->organization, $this->member($this->organization, 'store'), 'slip', ['credits' => '29.8']);
        }

        $newcomer = $this->member($this->organization, 'engineer');

        $this->assertSame('149.0000', (string) $this->spend()->spentToday($this->organization, 'slip'));
        $this->assertRoom('slip', '1', $newcomer);

        $refused = $this->refusal(fn () => $this->assertRoom('slip', '1.0001', $newcomer));

        $this->assertSame(['code' => 'daily_budget', 'feature' => 'slip', 'scope' => 'company'], $refused->toArray());

        // Another company has spent nothing.
        $other = $this->tenant('Konkan Infra');
        $this->assertRoom('slip', '30', $this->member($other, 'engineer'), null, $other);
    }

    public function test_a_company_total_when_set_covers_every_feature(): void
    {
        AiDocumentSettings::for($this->organization)->update(['budgets' => ['company' => ['total' => '50']]]);
        $this->spentPage($this->organization, $this->owner, 'boq', ['credits' => '30', 'pass' => 'rows']);
        $this->runningRead($this->organization, $this->engineer, '14', ['kind' => 'slip']);

        $this->assertSame('44.0000', (string) $this->spend()->spentToday($this->organization, 'total'));
        $this->assertRoom('tender', '6', $this->owner);

        $refused = $this->refusal(fn () => $this->assertRoom('tender', '6.0001', $this->owner));

        $this->assertSame(['code' => 'daily_budget', 'feature' => 'total', 'scope' => 'company'], $refused->toArray());
    }

    public function test_the_budget_day_starts_at_midnight_in_india(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 00:30:00', 'Asia/Kolkata'));

        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '29', 'finished_at' => Carbon::parse('2026-11-12 23:50:00', 'Asia/Kolkata')]);
        $this->assertSame('0.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));

        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '4', 'finished_at' => Carbon::parse('2026-11-13 00:10:00', 'Asia/Kolkata')]);
        $this->assertSame('4.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));

        // 20:00 UTC on the 12th is already the 13th in India, so it counts as today wherever the server's clock is set.
        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '1.5', 'finished_at' => Carbon::parse('2026-11-12 20:00:00', 'UTC')->setTimezone(config('app.timezone'))]);
        $this->assertSame('5.5000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));

        Carbon::setTestNow();
    }

    public function test_a_page_read_again_today_counts_only_todays_charge(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', 'Asia/Kolkata'));

        // Charged 5 credits yesterday; the page row adds that up with today's 5.
        $read = $this->makeRead($this->organization, $this->engineer, ['kind' => 'slip', 'status' => DocumentRead::READY]);
        $page = $this->makePage($read, 1, ['pass' => 'main', 'status' => 'read', 'attempts' => 2, 'credits' => '10', 'finished_at' => now()]);
        $this->settledCall($page, ['credits' => '5', 'cost_usd' => '0.04', 'finished_at' => Carbon::parse('2026-11-12 18:00:00', 'Asia/Kolkata')]);
        $this->settledCall($page, ['credits' => '5', 'cost_usd' => '0.04']);

        $this->assertSame('5.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));
        $this->assertSame('5.0000', (string) $this->spend()->spentToday($this->organization, 'slip'));
        // So 25 more still fit the engineer's 30.
        $this->assertRoom('slip', '25');

        Carbon::setTestNow();
    }

    public function test_a_charge_counts_against_the_person_the_read_was_booked_to_when_it_was_made(): void
    {
        $read = $this->makeRead($this->organization, $this->engineer, ['kind' => 'slip', 'status' => DocumentRead::READY]);
        $page = $this->makePage($read, 1, ['pass' => 'main', 'status' => 'read', 'attempts' => 2, 'credits' => '20', 'finished_at' => now()]);
        $this->settledCall($page, ['credits' => '12']);

        // The engineer left, and the owner took the read over for its second call.
        $read->forceFill(['user_id' => $this->owner->id])->save();
        $this->settledCall($page, ['credits' => '8']);

        $this->assertSame('12.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));
        $this->assertSame('8.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->owner));
    }

    public function test_a_hold_that_is_being_replaced_is_not_counted_twice(): void
    {
        $read = $this->runningRead($this->organization, $this->engineer, '20', ['kind' => 'slip']);

        $this->assertRoom('slip', '30', $this->engineer, $read);
        $this->assertSame('20.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer));
        $this->assertSame('0.0000', (string) $this->spend()->spentToday($this->organization, 'slip', $this->engineer, $read));

        $this->refusal(fn () => $this->assertRoom('slip', '10.0001', $this->engineer));
    }

    public function test_a_daily_budget_stops_a_hold_and_leaves_the_read_as_it_was(): void
    {
        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '29.9']);
        $read = $this->makeRead($this->organization, $this->engineer, ['kind' => 'slip']);

        $refused = $this->refusal(fn () => $this->credits()->hold($read, '0.2', $this->engineer));

        $this->assertSame('daily_budget', $refused->reason);
        $this->assertSame(DocumentRead::QUOTED, $read->fresh()->status);
        $this->assertSame('0.0000', $read->fresh()->credits_held);

        $this->credits()->hold($read, '0.1', $this->engineer);
        $this->assertSame(DocumentRead::RUNNING, $read->fresh()->status);
    }

    // ---- notices to owners

    public function test_owners_are_told_once_at_each_step_of_a_company_budget(): void
    {
        $second = $this->member($this->organization, 'owner');
        $elsewhere = $this->member($this->tenant('Konkan Infra'), 'owner');
        $read = $this->makeRead($this->organization, $this->engineer, ['kind' => 'slip', 'status' => DocumentRead::READY]);
        $spend = fn (int $page, string $credits) => $this->settledCall($this->makePage($read, $page, ['pass' => 'main', 'status' => 'read', 'attempts' => 1, 'credits' => $credits, 'finished_at' => now()]), ['credits' => $credits]);

        // Under half of 150: nothing is said.
        $spend(1, '74.9');
        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->assertSame([], $this->noticesOf($this->owner));

        $spend(2, '0.1');
        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->assertSame(['50% of today\'s AI budget for challan photos is used'], $this->noticesOf($this->owner));

        $spend(3, '45');
        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->assertCount(2, $this->noticesOf($this->owner));
        $this->assertContains('80% of today\'s AI budget for challan photos is used', $this->noticesOf($this->owner));

        $spend(4, '30');
        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->assertCount(3, $this->noticesOf($this->owner));
        $this->assertContains('Today\'s AI budget for challan photos is used up', $this->noticesOf($this->owner));

        // Every owner hears, nobody else in the company, and no other company.
        $this->assertCount(3, $this->noticesOf($second));
        $this->assertSame([], $this->noticesOf($this->engineer));
        $this->assertSame([], $this->noticesOf($elsewhere));

        // A feature without a budget, such as a BOQ read, causes no notice at all.
        $this->spend()->afterSpend($this->organization, 'boq', '400');
        $this->assertCount(3, $this->noticesOf($this->owner));
    }

    public function test_a_step_that_was_jumped_over_is_not_announced_afterwards(): void
    {
        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '130']);

        $this->spend()->afterSpend($this->organization, 'slip', '400');
        $this->spend()->afterSpend($this->organization, 'slip', '400');

        $this->assertSame(['80% of today\'s AI budget for challan photos is used'], $this->noticesOf($this->owner));

        // Tomorrow is a new day with its own notices.
        $this->travel(1)->days();
        $this->spentPage($this->organization, $this->engineer, 'slip', ['credits' => '80']);
        $this->spend()->afterSpend($this->organization, 'slip', '400');

        $this->assertCount(2, $this->noticesOf($this->owner));
        $this->assertContains('50% of today\'s AI budget for challan photos is used', $this->noticesOf($this->owner));
    }

    public function test_owners_are_told_once_a_day_when_little_is_left_and_in_their_own_language(): void
    {
        $this->owner->forceFill(['locale' => 'mr'])->save();
        $hindi = $this->member($this->organization, 'owner');
        $hindi->forceFill(['locale' => 'hi'])->save();

        $this->spend()->afterSpend($this->organization, 'boq', '100');
        $this->assertSame([], $this->noticesOf($this->owner));

        $this->spend()->afterSpend($this->organization, 'boq', '99.9999');
        $this->spend()->afterSpend($this->organization, 'boq', '12');

        $this->assertSame(['AI क्रेडिट कमी उरले आहेत'], $this->noticesOf($this->owner));
        $this->assertSame(['AI क्रेडिट कम बचे हैं'], $this->noticesOf($hindi));

        $body = json_decode(DB::table('notifications')->where('notifiable_id', $hindi->id)->value('data'), true);
        $this->assertSame('100 से कम AI क्रेडिट इस्तेमाल के लिए बचे हैं। जब कोई नहीं बचेगा, तो और क्रेडिट जुड़ने तक AI का काम रुका रहेगा।', $body['body']);
        // A phone shows it as a push, so it neither says buy nor links to the page that sells credits (App Store guideline 3.1.1).
        $this->assertNull($body['url'] ?? null);
        $this->assertStringNotContainsString('खरीद', $body['body']);

        $this->travel(1)->days();
        $this->spend()->afterSpend($this->organization, 'boq', '12');
        $this->assertCount(2, $this->noticesOf($this->owner));
    }

    public function test_a_hold_that_empties_the_wallet_tells_the_owners(): void
    {
        $read = $this->makeRead($this->organization, $this->owner);

        $this->credits()->hold($read, '401', $this->owner);

        $this->assertSame(['AI credits are running low'], $this->noticesOf($this->owner));
    }

    // ---- limits on one company

    public function test_a_company_the_platform_admin_has_blocked_sends_nothing(): void
    {
        $other = $this->tenant('Konkan Infra');
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);

        $this->assertSame('blocked', $this->spend()->companyStop($this->organization));
        $this->assertSame('company_limit', $this->spend()->pauseReason($this->organization));
        $this->assertNull($this->spend()->companyStop($other));
        $this->assertNull($this->spend()->pauseReason($other));

        $refused = $this->refusal(fn () => $this->assertRoom('boq', '10'));

        $this->assertSame(409, $refused->status);
        $this->assertSame(['code' => 'company_limit', 'limit' => 'blocked'], $refused->toArray());
    }

    public function test_no_company_takes_more_than_its_share_of_the_platforms_day(): void
    {
        $other = $this->tenant('Konkan Infra');

        // 40 percent of 25 dollars is 10.
        $this->spentPage($this->organization, $this->owner, 'boq', ['cost_usd' => '9.999999', 'pass' => 'rows']);
        $this->assertNull($this->spend()->companyStop($this->organization));

        $this->spentPage($this->organization, $this->owner, 'boq', ['cost_usd' => '0.000001', 'pass' => 'rows', 'status' => 'failed']);
        $this->assertSame('share', $this->spend()->companyStop($this->organization));
        $this->assertSame('company_limit', $this->spend()->pauseReason($this->organization));
        $this->assertNull($this->spend()->companyStop($other));

        $refused = $this->refusal(fn () => $this->assertRoom('boq', '10'));
        $this->assertSame(['code' => 'company_limit', 'limit' => 'share'], $refused->toArray());

        // A lower platform limit lowers every company's share with it.
        app(PlatformSettings::class)->set(['ai_reader_daily_usd' => '50']);
        $this->assertNull($this->spend()->companyStop($this->organization));

        // Yesterday's cost is yesterday's.
        $this->travel(1)->days();
        app(PlatformSettings::class)->set(['ai_reader_daily_usd' => null]);
        $this->assertNull($this->spend()->companyStop($this->organization));
    }

    /** A page of its own with one call that cost this much and was charged for none of it. */
    private function burntPage(Organization $organization, string $usd, $when = null): DocumentReadPage
    {
        $read = $this->makeRead($organization, null, ['status' => DocumentRead::READY]);
        $page = $this->makePage($read, 1, ['status' => 'failed', 'fail_reason' => 'timeout', 'attempts' => 1, 'cost_usd' => $usd, 'finished_at' => $when ?? now()]);
        $this->settledCall($page, ['cost_usd' => $usd, 'absorbed_usd' => $usd, 'finished_at' => $when ?? now()]);

        return $page;
    }

    public function test_cost_nobody_was_charged_for_is_capped_per_day_and_lower_on_a_trial(): void
    {
        $trial = $this->trialTenant();

        $this->burntPage($this->organization, '1.999999');
        $this->burntPage($this->organization, '5', now()->subDays(2));
        $this->burntPage($trial, '0.499999');

        $this->assertNull($this->spend()->companyStop($this->organization));
        $this->assertNull($this->spend()->companyStop($trial));

        $this->burntPage($this->organization, '0.000001');
        $this->burntPage($trial, '0.000001');

        $this->assertSame('absorbed', $this->spend()->companyStop($this->organization));
        $this->assertSame('company_limit', $this->spend()->pauseReason($this->organization));
        $this->assertSame('absorbed', $this->spend()->companyStop($trial));
        $this->assertSame('2.000000', (string) $this->spend()->absorbedToday($this->organization)->toScale(6));
    }

    public function test_cost_nobody_was_charged_for_counts_on_the_day_of_its_call_not_the_day_its_read_moves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 22:30:00', 'Asia/Kolkata'));

        // A long read loses 1.70 dollars to failed pages before midnight.
        $read = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::RUNNING, 'absorbed_usd' => '1.70', 'last_progress_at' => now()]);

        foreach (range(1, 17) as $number) {
            $this->settledCall($this->makePage($read, $number, ['status' => 'failed', 'fail_reason' => 'timeout', 'attempts' => 1]), ['cost_usd' => '0.10', 'absorbed_usd' => '0.10']);
        }

        $this->assertSame('1.700000', (string) $this->spend()->absorbedToday($this->organization)->toScale(6));

        // Just after midnight its next page is read and partly charged: only that page's share counts today.
        Carbon::setTestNow(Carbon::parse('2026-11-13 00:01:00', 'Asia/Kolkata'));
        $read->forceFill(['absorbed_usd' => '1.75', 'last_progress_at' => now()])->save();
        $this->settledCall($this->makePage($read, 18, ['status' => 'read', 'attempts' => 1]), ['cost_usd' => '0.08', 'credits' => '4', 'absorbed_usd' => '0.05']);

        $this->assertSame('0.050000', (string) $this->spend()->absorbedToday($this->organization)->toScale(6));
        $this->assertNull($this->spend()->companyStop($this->organization));
        $this->assertNull($this->spend()->pauseReason($this->organization));

        Carbon::setTestNow();
    }

    public function test_too_many_unusable_answers_in_a_day_stop_the_company_until_the_admin_lifts_it(): void
    {
        $read = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::READY]);
        $unusable = fn (DocumentReadPage $page, int $times, $when = null) => collect(range(1, $times))
            ->each(fn () => $this->settledCall($page, ['behaviour_failure' => true, 'cost_usd' => '0.05', 'absorbed_usd' => '0.0001', 'finished_at' => $when ?? now()]));
        $failed = function (int $number, int $times, $when = null) use ($read, $unusable) {
            $page = $this->makePage($read, $number, ['status' => 'failed', 'fail_reason' => 'invalid_output', 'attempts' => $times, 'behaviour_failures' => $times, 'finished_at' => $when ?? now()]);
            $unusable($page, $times, $when);

            return $page;
        };

        $failed(1, 3);
        $second = $failed(2, 36);
        $failed(3, 9, now()->subHours(25));
        // A call that went wrong on the way is nobody's fault and is not counted.
        $lost = $this->makePage($read, 4, ['status' => 'failed', 'fail_reason' => 'timeout', 'attempts' => 5, 'finished_at' => now()]);
        $this->settledCall($lost, ['cost_usd' => '0.1', 'absorbed_usd' => '0.1']);

        $this->assertSame(39, $this->spend()->unusableAnswers($this->organization));
        $this->assertNull($this->spend()->companyStop($this->organization));

        $failed(5, 1);
        $this->assertSame('brake', $this->spend()->companyStop($this->organization));
        $this->assertSame(['code' => 'company_limit', 'limit' => 'brake'], $this->refusal(fn () => $this->assertRoom('boq', '10'))->toArray());
        $this->assertNull($this->spend()->companyStop($this->tenant('Konkan Infra')));

        // Lifted: what happened before no longer counts, what happens after does.
        $this->travel(1)->minutes();
        $this->spend()->liftBrake($this->organization);
        $this->travel(1)->minutes();

        $this->assertSame(0, $this->spend()->unusableAnswers($this->organization));
        $this->assertNull($this->spend()->companyStop($this->organization));

        // A page that failed before the lift is read again and comes back fine: its old answers stay forgiven.
        $second->forceFill(['status' => 'read', 'fail_reason' => null, 'attempts' => 37, 'finished_at' => now()])->save();
        $this->settledCall($second, ['cost_usd' => '0.05', 'credits' => '6']);
        $this->assertSame(0, $this->spend()->unusableAnswers($this->organization));

        // Read again and unusable once more: that one answer counts, not the 36 before the lift.
        $unusable($second, 1);
        $this->assertSame(1, $this->spend()->unusableAnswers($this->organization));

        $failed(6, 39);
        $this->assertSame('brake', $this->spend()->companyStop($this->organization));
    }

    // ---- trial companies

    public function test_a_trial_company_has_a_fixed_page_allowance_a_lower_read_limit_and_no_stronger_model(): void
    {
        $trial = $this->trialTenant();
        $read = $this->makeRead($trial, null, ['status' => DocumentRead::READY]);

        $this->assertTrue($this->spend()->onTrial($trial));
        $this->assertFalse($this->spend()->onTrial($this->organization));
        $this->assertSame(20, $this->spend()->trialPagesLeft($trial));
        $this->assertNull($this->spend()->trialPagesLeft($this->organization));
        $this->assertSame('60.0000', $this->spend()->maxCreditsPerRead($trial));
        $this->assertSame('650.0000', $this->spend()->maxCreditsPerRead($this->organization));
        $this->assertFalse($this->spend()->strongerModelAllowed($trial));
        $this->assertTrue($this->spend()->strongerModelAllowed($this->organization));

        foreach (range(1, 19) as $number) {
            $this->makePage($read, $number, ['status' => 'read', 'attempts' => 1]);
        }

        // The second pass and the two halves of a page already sent are the same page. A page not sent yet is none.
        $this->makePage($read, 1, ['pass' => 'amounts', 'status' => 'read', 'attempts' => 1]);
        $this->makePage($read, 2, ['part' => 'top', 'status' => 'failed', 'attempts' => 2]);
        $this->makePage($read, 20);

        $this->assertSame(1, $this->spend()->trialPagesLeft($trial));
        $this->assertNull($this->spend()->companyStop($trial));

        $this->makePage($this->makeRead($trial), 1, ['status' => 'failed', 'attempts' => 1]);

        $this->assertSame(0, $this->spend()->trialPagesLeft($trial));
        $this->assertSame('trial_pages', $this->spend()->companyStop($trial));
        $this->assertSame('company_limit', $this->spend()->pauseReason($trial));
        $this->assertSame(['code' => 'company_limit', 'limit' => 'trial_pages'], $this->refusal(fn () => $this->assertRoom('boq', '5', $this->member($trial, 'owner'), null, $trial))->toArray());

        // A page that was already counted may still be finished: its second pass, or another try.
        $secondPass = $this->makePage($read, 19, ['pass' => 'amounts']);
        $neverSent = $read->pages()->where('page_no', 20)->sole();

        $this->assertNull($this->spend()->pauseReason($trial, $secondPass));
        $this->assertSame('company_limit', $this->spend()->pauseReason($trial, $neverSent));
        $this->assertNull($this->spend()->companyStop($trial, false, $read));
        $this->assertRoom('boq', '5', $this->member($trial, 'owner'), $read, $trial);

        // The platform admin lifts the allowance for this one company.
        AiDocumentSettings::for($trial)->update(['quota_lifted_by' => $this->owner->id, 'quota_lifted_at' => now()]);

        $this->assertFalse($this->spend()->onTrial($trial));
        $this->assertNull($this->spend()->trialPagesLeft($trial));
        $this->assertNull($this->spend()->companyStop($trial));
        $this->assertSame('650.0000', $this->spend()->maxCreditsPerRead($trial));
        $this->assertTrue($this->spend()->strongerModelAllowed($trial));
    }

    public function test_an_owner_can_lower_the_limit_of_one_read_but_never_raise_it(): void
    {
        $settings = AiDocumentSettings::for($this->organization);

        $settings->update(['max_credits_per_read' => '120.5']);
        $this->assertSame('120.5000', $this->spend()->maxCreditsPerRead($this->organization));

        $settings->update(['max_credits_per_read' => '9000']);
        $this->assertSame('650.0000', $this->spend()->maxCreditsPerRead($this->organization));

        $settings->update(['max_credits_per_read' => '0']);
        $this->assertSame('650.0000', $this->spend()->maxCreditsPerRead($this->organization));

        $trial = $this->trialTenant();
        AiDocumentSettings::for($trial)->update(['max_credits_per_read' => '25']);
        $this->assertSame('25.0000', $this->spend()->maxCreditsPerRead($trial));
    }

    // ---- limits of the platform

    public function test_the_platforms_daily_limit_stops_every_company(): void
    {
        $companies = [$this->organization, $this->tenant('Konkan Infra'), $this->tenant('Deccan Projects')];

        foreach ($companies as $company) {
            $this->spentPage($company, null, 'boq', ['cost_usd' => '8.333333', 'pass' => 'rows']);
        }

        // 24.999999 of 25 dollars: still open.
        $this->assertNull($this->spend()->platformStop());
        $this->assertNull($this->spend()->pauseReason($this->organization));

        $this->spentPage($companies[1], null, 'slip', ['cost_usd' => '0.000001', 'status' => 'failed']);

        $this->assertSame('daily', $this->spend()->platformStop());

        // Every company is stopped, including one that has spent nothing itself.
        $fresh = $this->tenant('Narmada Works');
        $this->assertSame('platform_ceiling', $this->spend()->pauseReason($fresh));

        $refused = $this->refusal(fn () => $this->assertRoom('boq', '10', $this->member($fresh, 'owner'), null, $fresh));
        $this->assertSame(409, $refused->status);
        $this->assertSame(['code' => 'reader_paused', 'period' => 'daily'], $refused->toArray());

        // The platform admin raises the limit, or the day ends.
        app(PlatformSettings::class)->set(['ai_reader_daily_usd' => '80']);
        $this->assertNull($this->spend()->platformStop());

        app(PlatformSettings::class)->set(['ai_reader_daily_usd' => null]);
        $this->travel(1)->days();
        $this->assertNull($this->spend()->platformStop());
    }

    public function test_the_monthly_limit_counts_agents_and_readers_against_the_cap_the_admin_typed(): void
    {
        // 02:00 on 1 December in India is still 30 November for the provider, whose month runs on UTC.
        Carbon::setTestNow(Carbon::parse('2026-12-01 02:00:00', 'Asia/Kolkata'));
        $this->release('100');

        $agent = app(AgentRegistry::class)->find('project_brain');
        $run = AgentRun::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id, 'agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id,
            'autonomy' => 'L0', 'model' => 'claude-sonnet-5-5', 'input' => 'hello', 'messages' => [], 'max_steps' => 5, 'max_seconds' => 60, 'max_credits' => '10',
        ]);
        $step = fn (int $number, string $cost, string $when) => AgentStep::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id, 'agent_run_id' => $run->id, 'number' => $number, 'model' => 'claude-sonnet-5-5', 'cost_usd' => $cost,
        ])->forceFill(['created_at' => Carbon::parse($when, 'Asia/Kolkata')])->save();

        // The readers may bring the month to 60 percent of 100 dollars.
        $this->assertSame('60.00', (string) $this->spend()->monthlyLimit()->toScale(2));

        $step(1, '40', '2026-11-10 12:00:00');
        $step(2, '500', '2026-10-31 12:00:00');
        $this->spentPage($this->tenant('Konkan Infra'), null, 'boq', ['cost_usd' => '19.99', 'pass' => 'rows', 'finished_at' => Carbon::parse('2026-11-20 12:00:00', 'Asia/Kolkata')]);

        $this->assertSame('59.990000', (string) $this->spend()->platformCostThisMonth()->toScale(6));
        $this->assertNull($this->spend()->platformStop());

        $step(3, '0.01', '2026-11-30 23:00:00');

        $this->assertSame('monthly', $this->spend()->platformStop());
        $this->assertSame('platform_ceiling', $this->spend()->pauseReason($this->organization));

        $refused = $this->refusal(fn () => $this->assertRoom('boq', '10'));
        $this->assertSame(['code' => 'reader_paused', 'period' => 'monthly'], $refused->toArray());
        $this->assertSame('The reader is paused for this month.', $refused->getMessage());

        // A larger share, typed by the admin, opens it again; so does the provider's new month at 05:30 in India.
        app(PlatformSettings::class)->set(['ai_reader_month_share' => '0.75']);
        $this->assertNull($this->spend()->platformStop());

        app(PlatformSettings::class)->set(['ai_reader_month_share' => null]);
        Carbon::setTestNow(Carbon::parse('2026-12-01 05:31:00', 'Asia/Kolkata'));
        $this->assertNull($this->spend()->platformStop());

        Carbon::setTestNow();
    }

    public function test_a_page_read_again_counts_only_its_new_call_in_the_new_day_and_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'Asia/Kolkata'));

        // It cost 8 cents on 31 October; read again on 1 November, the row adds up to 16 cents.
        $read = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::READY]);
        $page = $this->makePage($read, 1, ['status' => 'read', 'attempts' => 2, 'cost_usd' => '0.16', 'finished_at' => now()]);
        $this->settledCall($page, ['cost_usd' => '0.08', 'finished_at' => Carbon::parse('2026-10-31 12:00:00', 'Asia/Kolkata')]);
        $this->settledCall($page, ['cost_usd' => '0.08']);

        $this->assertSame('0.080000', (string) $this->spend()->platformCostThisMonth()->toScale(6));
        $this->assertSame('0.080000', (string) $this->spend()->platformCostToday()->toScale(6));

        Carbon::setTestNow();
    }

    public function test_platform_admins_are_told_at_each_step_of_a_limit_and_when_it_stops_the_readers(): void
    {
        $admin = $this->member($this->tenant('Platform Staff'), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $companies = [$this->organization, $this->tenant('Konkan Infra'), $this->tenant('Deccan Projects')];
        $cost = fn (int $company, string $usd) => $this->spentPage($companies[$company], null, 'boq', ['cost_usd' => $usd, 'pass' => 'rows']);

        $cost(0, '6');
        $cost(1, '6');
        $this->spend()->platformStop();
        $this->assertSame([], $this->noticesOf($admin));

        $cost(2, '0.5');
        $this->spend()->platformStop();
        $this->spend()->platformStop();
        $this->assertSame(['AI reader spending has reached 50% of today\'s limit'], $this->noticesOf($admin));

        $cost(2, '7.5');
        $this->spend()->platformStop();
        $this->assertCount(2, $this->noticesOf($admin));
        $this->assertContains('AI reader spending has reached 80% of today\'s limit', $this->noticesOf($admin));

        $cost(0, '3');
        $cost(1, '3');
        $this->assertSame('daily', $this->spend()->platformStop());
        $this->spend()->platformStop();
        $this->assertCount(3, $this->noticesOf($admin));
        $this->assertContains('The AI readers have stopped: today\'s spending limit is reached', $this->noticesOf($admin));

        // Owners of the companies are not told about the platform's own limits.
        $this->assertSame([], $this->noticesOf($this->owner));
    }

    public function test_totals_over_every_company_are_added_up_once_in_a_short_while(): void
    {
        config(['modules.document_reading.ceilings.cache_seconds' => 60]);

        $this->spentPage($this->organization, null, 'boq', ['cost_usd' => '3', 'pass' => 'rows']);
        $this->assertSame('3.000000', (string) $this->spend()->platformCostToday()->toScale(6));

        $this->spentPage($this->tenant('Konkan Infra'), null, 'boq', ['cost_usd' => '4', 'pass' => 'rows']);
        $this->assertSame('3.000000', (string) $this->spend()->platformCostToday()->toScale(6));

        $this->travel(61)->seconds();
        $this->assertSame('7.000000', (string) $this->spend()->platformCostToday()->toScale(6));
    }
}
