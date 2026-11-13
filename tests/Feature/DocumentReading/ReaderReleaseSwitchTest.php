<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\AuditLog;
use App\Models\DocumentReadAttempt;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ProviderBlock;
use App\Services\DocumentReading\ReaderDashboard;
use App\Services\DocumentReading\ReaderSettings;
use App\Services\DocumentReading\ReadKinds;
use App\Services\DocumentReading\ReadQueues;
use App\Support\PlatformSettings;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\FakeSlipKind;

/**
 * The release switch, the spending limits and the per-company controls on the platform admin's AI
 * reader page. The switch is off until the platform admin turns it on, and only with the provider's
 * monthly cap on record, spend alerts confirmed and a dry run confirmed for every deployed reader.
 * Whether a company is offered a reader is then AiSpend::readersOn, the one test.
 */
class ReaderReleaseSwitchTest extends ReaderTrialTestCase
{
    private function spend(): AiSpend
    {
        return app(AiSpend::class);
    }

    private function patchSettings(array $data)
    {
        return $this->actingAs($this->admin)->from('/admin/ai-reader')->patch('/admin/ai-reader/settings', $data);
    }

    private function enterCap(string $cap = '1000'): void
    {
        $this->patchSettings(['action' => 'limits', 'monthly_cap' => $cap])->assertSessionHasNoErrors();
    }

    /** Every reader deployed in these tests: the stand-ins for the BOQ, tender and slip readers. */
    private const DEPLOYED = ['boq', 'tender', 'slip'];

    public function test_the_switch_is_off_by_default(): void
    {
        $this->assertFalse($this->spend()->switchedOn());
        $this->assertFalse($this->spend()->released());
        $this->assertFalse($this->spend()->readersOn());
        $this->assertFalse($this->spend()->readersOn($this->organization));
        $this->assertFalse(app(ReaderSettings::class)->offered($this->organization));
    }

    public function test_the_readers_cannot_be_released_without_the_cap_the_alerts_and_every_dry_run(): void
    {
        $this->patchSettings(['action' => 'release', 'alerts_set' => '1', 'dry_run' => self::DEPLOYED])
            ->assertRedirect('/admin/ai-reader')->assertSessionHasErrors('monthly_cap');
        $this->assertFalse($this->spend()->switchedOn());

        $this->enterCap();

        $this->patchSettings(['action' => 'release', 'dry_run' => self::DEPLOYED])->assertSessionHasErrors('alerts_set');
        $this->assertFalse($this->spend()->switchedOn());

        $this->patchSettings(['action' => 'release', 'alerts_set' => '1', 'dry_run' => ['boq', 'slip']])->assertSessionHasErrors('dry_run');
        $this->assertFalse($this->spend()->switchedOn());
        $this->assertFalse($this->spend()->readersOn($this->organization));
        $this->assertSame([], $this->platformAudits('ai.readers_released'));
    }

    public function test_releasing_turns_on_the_one_test_for_every_company_and_is_recorded_for_no_company(): void
    {
        $this->enterCap('500');

        $this->patchSettings(['action' => 'release', 'alerts_set' => '1', 'dry_run' => [...self::DEPLOYED, 'not_a_reader']])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertTrue($this->spend()->switchedOn());
        $this->assertTrue($this->spend()->released());
        $this->assertTrue($this->spend()->readersOn());
        $this->assertTrue($this->spend()->readersOn($this->organization));
        $this->assertTrue(app(ReaderSettings::class)->offered($this->organization));

        [$audit] = $this->platformAudits('ai.readers_released');
        $this->assertNull($audit->organization_id);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertEqualsCanonicalizing(self::DEPLOYED, $audit->new_values['dry_run_done']);
        $this->assertSame('500', $audit->new_values['monthly_cap_usd']);
        $this->assertNotNull(app(PlatformSettings::class)->get('ai_spend_alerts_confirmed_at'));

        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertSee('Offered to companies')->assertSee('Switch the readers off for every company');
    }

    public function test_the_page_says_which_part_of_the_test_is_missing(): void
    {
        $this->enterCap();
        $this->patchSettings(['action' => 'release', 'alerts_set' => '1', 'dry_run' => self::DEPLOYED])->assertSessionHasNoErrors();

        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);

        $this->assertTrue($this->spend()->released());
        $this->assertFalse($this->spend()->readersOn());
        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()
            ->assertSee('Not offered to companies')
            ->assertSee('no API key is saved, so no company is offered a reader');
    }

    public function test_a_reader_deployed_after_the_release_is_named_until_its_dry_run_is_confirmed(): void
    {
        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class]]);
        $this->app->forgetInstance(ReadKinds::class);

        $this->enterCap();
        $this->patchSettings(['action' => 'release', 'alerts_set' => '1', 'dry_run' => ['boq']])->assertSessionHasNoErrors();

        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class, FakeSlipKind::class]]);
        $this->app->forgetInstance(ReadKinds::class);

        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertSee('Deployed after the release, with no dry run confirmed: Challan photos.');
    }

    public function test_switching_off_stops_the_offer_and_keeps_the_cap(): void
    {
        $this->enterCap();
        $this->patchSettings(['action' => 'release', 'alerts_set' => '1', 'dry_run' => self::DEPLOYED])->assertSessionHasNoErrors();

        $this->patchSettings(['action' => 'monthly_cap'])->assertSessionHasErrors('action');

        // The cap cannot be taken away while the switch is on.
        $this->patchSettings(['action' => 'limits', 'monthly_cap' => ''])->assertSessionHasErrors('monthly_cap');
        $this->assertNotNull($this->spend()->monthlyCap());

        $this->patchSettings(['action' => 'withdraw'])->assertSessionHasNoErrors();

        $this->assertFalse($this->spend()->switchedOn());
        $this->assertFalse($this->spend()->readersOn($this->organization));
        $this->assertSame('1000', (string) $this->spend()->monthlyCap());
        $this->assertCount(1, $this->platformAudits('ai.readers_withdrawn'));

        // Switching off twice records nothing more.
        $this->patchSettings(['action' => 'withdraw'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->platformAudits('ai.readers_withdrawn'));
    }

    public function test_the_limits_are_checked_saved_with_their_old_values_and_fall_back_when_emptied(): void
    {
        $this->patchSettings(['action' => 'limits', 'monthly_cap' => 'a lot', 'daily_limit' => '0', 'month_share' => '1.5', 'trial_allowance' => '-1'])
            ->assertSessionHasErrors(['monthly_cap', 'daily_limit', 'month_share', 'trial_allowance']);
        $this->assertNull($this->spend()->monthlyCap());

        $this->patchSettings(['action' => 'limits', 'monthly_cap' => '1000', 'daily_limit' => '12.50', 'month_share' => '0.5', 'trial_allowance' => '3'])->assertSessionHasNoErrors();

        $this->assertSame('1000', (string) $this->spend()->monthlyCap());
        $this->assertSame('12.50', (string) $this->spend()->dailyCeiling());
        $this->assertSame('500.0', (string) $this->spend()->monthlyLimit());
        $this->assertSame('3', (string) $this->spend()->trialDailyAllowance());

        [$audit] = $this->platformAudits('ai.platform_settings_changed');
        $this->assertNull($audit->organization_id);
        $this->assertSame(['ai_tier_monthly_cap_usd' => '1000', 'ai_reader_daily_usd' => '12.50', 'ai_reader_month_share' => '0.5', 'ai_trial_daily_usd' => '3'], $audit->new_values);
        $this->assertSame(['ai_tier_monthly_cap_usd' => null, 'ai_reader_daily_usd' => null, 'ai_reader_month_share' => null, 'ai_trial_daily_usd' => null], $audit->old_values);

        // The same figures again change nothing and record nothing.
        $this->patchSettings(['action' => 'limits', 'monthly_cap' => '1000', 'daily_limit' => '12.50', 'month_share' => '0.5', 'trial_allowance' => '3'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->platformAudits('ai.platform_settings_changed'));

        $this->patchSettings(['action' => 'limits', 'monthly_cap' => '1000', 'daily_limit' => '', 'month_share' => '', 'trial_allowance' => '0'])->assertSessionHasNoErrors();

        $this->assertSame((string) config('modules.document_reading.ceilings.daily_usd'), (string) $this->spend()->dailyCeiling());
        $this->assertSame('0', (string) $this->spend()->trialDailyAllowance());
    }

    public function test_a_queue_name_is_saved_only_when_it_looks_like_one(): void
    {
        $this->patchSettings(['action' => 'queues', 'queues' => ['pages' => 'ai pages; rm']])->assertSessionHasErrors('queues.pages');
        $this->assertNull(app(ReadQueues::class)->name(ReadQueues::PAGES));

        $this->patchSettings(['action' => 'queues', 'queues' => ['pages' => 'ai-pages', 'interactive' => '', 'prepare' => '']])->assertSessionHasNoErrors();

        $this->assertSame('ai-pages', app(ReadQueues::class)->name(ReadQueues::PAGES));
        $this->assertNull(app(ReadQueues::class)->name(ReadQueues::INTERACTIVE));
        $this->assertCount(1, $this->platformAudits('ai.platform_settings_changed'));
    }

    public function test_the_admin_lifts_a_trial_allowance_blocks_a_company_and_forgives_its_brake(): void
    {
        $trialCompany = $this->trialTenant('Krishna Developers');
        $spend = $this->spend();

        $this->assertTrue($spend->onTrial($trialCompany));

        $this->actingAs($this->admin)->patch("/admin/ai-reader/companies/{$trialCompany->id}", ['action' => 'lift_quota'])->assertSessionHasNoErrors();
        $this->assertFalse($spend->onTrial($trialCompany));
        $this->assertSame($this->admin->id, AiDocumentSettings::for($trialCompany)->quota_lifted_by);

        $this->actingAs($this->admin)->patch("/admin/ai-reader/companies/{$trialCompany->id}", ['action' => 'restore_quota'])->assertSessionHasNoErrors();
        $this->assertTrue($spend->onTrial($trialCompany));

        $this->actingAs($this->admin)->patch("/admin/ai-reader/companies/{$this->organization->id}", ['action' => 'block'])->assertSessionHasNoErrors();
        $this->assertSame('blocked', $spend->companyStop($this->organization));

        $this->actingAs($this->admin)->patch("/admin/ai-reader/companies/{$this->organization->id}", ['action' => 'unblock'])->assertSessionHasNoErrors();
        $this->assertNull($spend->companyStop($this->organization));

        $this->actingAs($this->admin)->patch("/admin/ai-reader/companies/{$this->organization->id}", ['action' => 'lift_brake'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->patch("/admin/ai-reader/companies/{$this->organization->id}", ['action' => 'delete'])->assertSessionHasErrors('action');

        foreach (['ai.company_lift_quota', 'ai.company_restore_quota', 'ai.company_block', 'ai.company_unblock', 'ai.company_brake_lifted'] as $action) {
            [$audit] = $this->platformAudits($action);
            $this->assertNull($audit->organization_id, $action);
        }

        // Blocking writes nothing the company's own audit screen shows.
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('action', 'like', 'ai.company%')->count());
    }

    public function test_use_by_company_counts_each_call_of_the_month_as_the_limits_do(): void
    {
        $this->consent($this->organization);
        $read = $this->makeRead($this->organization, $this->owner);
        $first = $this->paidPage($read, 1, '0.120000', ['credits' => '6']);
        // Read again this month: both calls count, the page once.
        $this->settledCall($first, ['cost_usd' => '0.030000', 'credits' => '1.5', 'absorbed_usd' => '0.010000']);
        $this->makePage($read, 2, ['status' => 'failed', 'attempts' => 1, 'finished_at' => now()]);

        // A call of last month counts in none of this month's figures.
        $old = $this->paidPage($read, 3, '0.500000', ['credits' => '20', 'finished_at' => now()->subMonths(2)]);
        DocumentReadAttempt::withoutGlobalScopes()->where('document_read_page_id', $old->id)->update(['finished_at' => now()->subMonths(2)]);

        $row = app(ReaderDashboard::class)->companies()->firstWhere(fn (array $row) => $row['organization']->is($this->organization));

        $this->assertSame(2, $row['pages']);
        $this->assertSame(1, $row['read_pages']);
        $this->assertSame(1, $row['failed_pages']);
        $this->assertSame('0.1500', $row['cost_usd']);
        $this->assertSame('7.50', $row['credits']);
        $this->assertSame('0.0100', $row['absorbed_usd']);
        $this->assertTrue($row['consent']);

        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertSee('Sahyadri Builders')->assertSee('0.1500');
    }

    public function test_the_page_lists_a_company_found_by_name(): void
    {
        $this->actingAs($this->admin)->get('/admin/ai-reader?company=sahyadri')->assertOk()->assertSee('Sahyadri Builders')->assertSee('Switch off');
        $this->actingAs($this->admin)->get('/admin/ai-reader?company=%25')->assertOk()->assertDontSee('Sahyadri Builders');
    }

    public function test_the_stop_set_when_the_provider_refused_is_cleared_here(): void
    {
        ProviderBlock::set('billing');
        $this->assertNotNull(ProviderBlock::current());

        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertSee('Clear the stop');
        $this->actingAs($this->admin)->delete('/admin/ai-reader/provider-stop')->assertSessionHasNoErrors();

        $this->assertNull(ProviderBlock::current());
        [$audit] = $this->platformAudits('ai.provider_stop_cleared');
        $this->assertSame('billing', $audit->new_values['reason']);
    }
}
