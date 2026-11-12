<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AgentCreditLedger;
use App\Models\AgentCreditWallet;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ApprovalUpdate;
use App\Services\Agents\CreditService;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Companies, wallets, reads and pages for the tests of the reader's tables, credits, limits and quotes. */
abstract class ReadingTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // Each test sees its own rows at once. The tests of the two caches switch them back on.
        config([
            'modules.document_reading.ceilings.cache_seconds' => 0,
            'modules.document_reading.typical.cache_seconds' => 0,
        ]);
    }

    /** What the platform admin does before any company is offered a reader. */
    protected function release(string $monthlyCap = '1000'): void
    {
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => $monthlyCap]);
    }

    protected function connect(): void
    {
        app(PlatformSettings::class)->set(['anthropic_api_key' => 'test-key-value']);
    }

    protected function credits(): CreditService
    {
        return app(CreditService::class);
    }

    protected function setCredits(Organization $organization, string $included, string $purchased = '0'): void
    {
        $this->credits()->wallet($organization);

        AgentCreditWallet::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->update(['included_balance' => $included, 'purchased_balance' => $purchased]);
    }

    protected function wallet(Organization $organization): AgentCreditWallet
    {
        return $this->credits()->wallet($organization);
    }

    /** A company on a trial of the Business plan, as a web sign-up gets. */
    protected function trialTenant(string $name = 'Kaveri Constructions'): Organization
    {
        $organization = $this->tenant($name);
        $organization->update(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(20)]);

        return $organization->refresh();
    }

    protected function makeRead(Organization $organization, ?User $by = null, array $overrides = []): DocumentRead
    {
        return DocumentRead::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'user_id' => $by?->id,
            'kind' => 'boq',
            'status' => DocumentRead::QUOTED,
            ...$overrides,
        ])->refresh();
    }

    /** A stored A4 page picture waiting for the first BOQ pass, unless told otherwise. */
    protected function makePage(DocumentRead $read, int $number = 1, array $overrides = []): DocumentReadPage
    {
        return DocumentReadPage::withoutGlobalScopes()->create([
            'document_read_id' => $read->id,
            'page_no' => $number,
            'pass' => 'rows',
            'model' => 'claude-sonnet-5-5',
            'image_width' => 1622,
            'image_height' => 2296,
            ...$overrides,
        ])->refresh();
    }

    /** A page the worker has claimed and got an answer for, ready to be charged or burned. */
    protected function answeredPage(DocumentRead $read, int $number = 1, string $costUsd = '0.050000', array $overrides = []): DocumentReadPage
    {
        return $this->makePage($read, $number, ['status' => DocumentReadPage::READ, 'attempts' => 1, 'cost_usd' => $costUsd, 'finished_at' => now(), ...$overrides]);
    }

    /** A read that is running with this much on hold, without going through the checks of hold(). */
    protected function runningRead(Organization $organization, User $by, string $held, array $overrides = []): DocumentRead
    {
        return $this->makeRead($organization, $by, ['status' => DocumentRead::RUNNING, 'credits_cap' => $held, 'credits_held' => $held, 'started_at' => now(), ...$overrides]);
    }

    /** @return list<AgentCreditLedger> */
    protected function usageLines(Organization $organization): array
    {
        return AgentCreditLedger::withoutGlobalScopes()->where('organization_id', $organization->id)->where('type', 'usage')->orderBy('id')->get()->all();
    }

    /** The wallet is the sum of its ledger, to the last decimal. */
    protected function assertLedgerMatchesWallet(Organization $organization): void
    {
        $total = AgentCreditLedger::withoutGlobalScopes()->where('organization_id', $organization->id)->get()
            ->reduce(fn ($sum, $row) => $sum->plus(Dec::of($row->credits)), Dec::of(0));

        $this->assertSame($this->wallet($organization)->available(), (string) $total->toScale(4));
    }

    /** @return list<string> Titles of the bell notices a person has, oldest first. */
    protected function noticesOf(User $user): array
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->where('type', ApprovalUpdate::class)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => json_decode($row->data, true)['title'])->all();
    }
}
