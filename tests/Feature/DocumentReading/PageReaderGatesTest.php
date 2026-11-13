<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadAccess;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\DocumentReading\Support\FakeKind;

/**
 * Every reason a queued page is not sent after all. Each one pauses the read with its own reason,
 * gives back the hold, leaves the page waiting, and makes no call.
 */
class PageReaderGatesTest extends PageReadingTestCase
{
    private function assertHeldBack(DocumentRead $read, DocumentReadPage $page, string $reason): void
    {
        Http::assertNothingSent();

        $read->refresh();
        $this->assertSame([DocumentRead::PAUSED, $reason, '0.0000'], [$read->status, $read->pause_reason, $read->credits_held]);
        $this->assertSame([DocumentReadPage::WAITING, 0], [$page->fresh()->status, $page->fresh()->attempts]);
        $this->assertSame([], $this->usageLines(Organization::find($read->organization_id)));
        $this->assertSame([], $this->audits('ai.page_sent'));
        $this->assertSame([], $this->queuedPages());

        $audit = collect($this->audits('ai.read_paused'))->last();
        $this->assertSame(['status' => DocumentRead::PAUSED, 'pause_reason' => $reason], $audit->new_values);
        $this->assertSame($read->organization_id, $audit->organization_id);
    }

    private function queued(?User $by = null, array $overrides = []): array
    {
        $read = $this->startedRead($by, overrides: $overrides);
        $page = $this->storedPage($read);
        $this->fakeReader([$this->answer()]);

        return [$read, $page];
    }

    public function test_a_page_that_is_not_queued_or_whose_read_is_not_running_is_left_alone(): void
    {
        [$read, $page] = $this->queued();

        $set = fn (string $status) => DocumentReadPage::withoutGlobalScopes()->whereKey($page->id)->update(['status' => $status]);

        $set(DocumentReadPage::WAITING);
        $this->readPage($page);
        $this->assertSame(DocumentReadPage::WAITING, $page->fresh()->status);

        $this->readPage(999999);

        // A page still queued under a read that was paused goes back to waiting; under a cancelled read it is skipped.
        $set(DocumentReadPage::QUEUED);
        $read->forceFill(['status' => DocumentRead::PAUSED, 'credits_held' => '0'])->save();
        $this->readPage($page);
        $this->assertSame(DocumentReadPage::WAITING, $page->fresh()->status);

        $set(DocumentReadPage::QUEUED);
        $read->forceFill(['status' => DocumentRead::CANCELLED])->save();
        $this->readPage($page);
        $this->assertSame(DocumentReadPage::SKIPPED, $page->fresh()->status);

        Http::assertNothingSent();
        $this->assertSame(0, $page->fresh()->attempts);
    }

    public function test_a_running_read_with_nothing_on_hold_sends_nothing(): void
    {
        [$read, $page] = $this->queued();
        $read->forceFill(['credits_held' => '0'])->save();

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_CREDIT_CAP);
    }

    public function test_pages_stop_when_the_starter_is_switched_off(): void
    {
        [$read, $page] = $this->queued($this->member($this->organization, 'engineer'));
        $read->starter->forceFill(['is_active' => false])->save();

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_STARTER_GONE);
    }

    public function test_pages_stop_when_the_starter_no_longer_may_use_the_agents(): void
    {
        [$read, $page] = $this->queued($this->member($this->organization, 'engineer'));
        $read->starter->forceFill(['role' => 'viewer'])->save();

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_STARTER_GONE);
    }

    public function test_pages_stop_when_the_starter_no_longer_holds_what_the_kind_asks_for(): void
    {
        FakeKind::$abilities = ['all' => ['boq.view'], 'any' => [['boq.manage', 'measurement.approve']]];
        [$read, $page] = $this->queued($this->member($this->organization, 'engineer'));

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_STARTER_GONE);
    }

    public function test_pages_stop_when_the_starter_is_taken_off_the_project(): void
    {
        $project = $this->makeProject($this->organization);
        $engineer = $this->member($this->organization, 'engineer');
        $engineer->forceFill(['limited_to_projects' => true])->save();
        DB::table('user_project_access')->insert(['user_id' => $engineer->id, 'project_id' => $project->id, 'organization_id' => $this->organization->id, 'created_at' => now(), 'updated_at' => now()]);

        [$read, $page] = $this->queued($engineer, ['project_id' => $project->id]);
        $this->assertTrue(ReadAccess::holds($engineer, $read, new FakeKind));

        DB::table('user_project_access')->where('user_id', $engineer->id)->delete();
        $this->assertFalse(ReadAccess::holds($engineer->fresh(), $read, new FakeKind));

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_STARTER_GONE);
    }

    public function test_pages_stop_when_the_starter_has_left_or_belongs_to_another_company(): void
    {
        [$read, $page] = $this->queued();
        $read->forceFill(['user_id' => null])->save();
        $this->readPage($page);
        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_STARTER_GONE);

        $outsider = $this->member($this->tenant('Narmada Projects'), 'owner');
        $second = $this->startedRead();
        $secondPage = $this->storedPage($second);
        $second->forceFill(['user_id' => $outsider->id])->save();
        $this->readPage($secondPage);
        $this->assertHeldBack($second, $secondPage, DocumentRead::PAUSE_STARTER_GONE);
    }

    public function test_pages_stop_when_consent_is_withdrawn(): void
    {
        [$read, $page] = $this->queued();
        $this->consent($this->organization, false);

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_CONSENT_OFF);
    }

    public function test_a_new_notice_stops_reads_that_were_agreed_under_the_old_one(): void
    {
        // The company agreed to an older wording.
        [$read, $page] = $this->queued();
        $this->consent($this->organization, true, 'ai-docs-v0');
        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->readPage($page);
        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_CONSENT_OFF);

        // The company has agreed to today's wording, but this read was started under an older one, or records none.
        $this->consent($this->organization);
        $this->assertTrue(AiDocumentConsent::current($this->organization->id));

        foreach (['ai-docs-v0', null] as $version) {
            $old = $this->startedRead(overrides: ['consent_version' => $version]);
            $oldPage = $this->storedPage($old);
            $this->readPage($oldPage);
            $this->assertHeldBack($old, $oldPage, DocumentRead::PAUSE_CONSENT_OFF);
        }
    }

    public function test_a_company_that_never_opened_the_consent_screen_has_not_agreed(): void
    {
        [$read, $page] = $this->queued();
        AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $this->organization->id)->delete();

        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_CONSENT_OFF);
        // Asking does not create the row.
        $this->assertSame(0, AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());
    }

    public function test_pages_stop_when_the_plan_no_longer_includes_the_ai_features(): void
    {
        [$read, $page] = $this->queued();
        $this->organization->update(['plan' => 'contractor']);

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_PLAN);
    }

    public function test_pages_stop_when_no_key_is_saved(): void
    {
        [$read, $page] = $this->queued();
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_NOT_CONNECTED);
    }

    public function test_pages_stop_when_the_readers_are_switched_off_again(): void
    {
        [$read, $page] = $this->queued();
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_NOT_RELEASED);
    }

    public function test_pages_stop_at_the_platform_s_limit_for_the_day(): void
    {
        [$read, $page] = $this->queued();
        app(PlatformSettings::class)->set(['ai_reader_daily_usd' => '1']);

        $elsewhere = $this->startedRead($this->member($this->tenant('Narmada Projects'), 'owner'));
        $this->paidPage($elsewhere, 1, '1.000000');

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_PLATFORM_CEILING);
    }

    public function test_pages_stop_when_the_company_is_blocked_or_has_reached_one_of_its_own_limits(): void
    {
        [$read, $page] = $this->queued();
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_COMPANY_LIMIT);
    }

    public function test_a_trial_company_s_twenty_first_page_is_held_back_but_a_page_already_begun_may_be_finished(): void
    {
        $trial = $this->trialTenant();
        $owner = $this->member($trial, 'owner');
        $this->consent($trial);

        $read = $this->startedRead($owner, '50', ['pages_total' => 25]);

        for ($number = 1; $number <= 20; $number++) {
            $this->makePage($read, $number, ['status' => DocumentReadPage::READ, 'attempts' => 1, 'finished_at' => now()->subDay()]);
        }

        // The second pass of page 20 is part of a page that already counts.
        $begun = $this->storedPage($read, 20, ['pass' => 'amounts', 'model' => 'claude-haiku-5-5']);
        $fresh = $this->storedPage($read, 21, ['status' => DocumentReadPage::WAITING]);
        $this->fakeReader([$this->answer('other', 5956, 1200)]);
        $this->readPage($begun);
        $this->assertSame(DocumentReadPage::READ, $begun->fresh()->status);

        // Page 21 was handed to the queue as the next one. It would be the trial's twenty-first page.
        $this->assertSame([$fresh->id], $this->queuedPages());
        $this->readPage($fresh);

        Http::assertSentCount(1);
        $this->assertSame([DocumentRead::PAUSED, DocumentRead::PAUSE_COMPANY_LIMIT], [$read->fresh()->status, $read->fresh()->pause_reason]);
        $this->assertSame(DocumentReadPage::WAITING, $fresh->fresh()->status);
    }

    public function test_a_read_of_a_kind_that_is_no_longer_deployed_is_paused_not_failed(): void
    {
        [$read, $page] = $this->queued();
        config(['modules.reader_stand_ins.read_kinds' => []]);

        $this->readPage($page);

        $this->assertHeldBack($read, $page, DocumentRead::PAUSE_INFRA);
    }

    public function test_a_page_tried_far_too_often_is_failed_without_another_call(): void
    {
        [$read, $page] = $this->queued();
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $page->forceFill(['attempts' => 200])->save();

        $this->readPage($page);

        Http::assertNothingSent();
        $this->assertSame([DocumentReadPage::FAILED, 'internal_error', 201], [$page->fresh()->status, $page->fresh()->fail_reason, $page->fresh()->attempts]);
        $this->assertSame(1, PlatformSetting::whereKey('anthropic_api_key')->count());
    }
}
