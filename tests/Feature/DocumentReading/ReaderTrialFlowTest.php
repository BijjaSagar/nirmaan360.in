<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AuditLog;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\TrialFiles;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** A dry run from its first page to its last answer, and what it leaves behind. */
class ReaderTrialFlowTest extends ReaderTrialTestCase
{
    /**
     * Every row of every table that has an organization_id, as text. Audit rows that belong to no
     * company are left out: they are the platform's own trail of the dry run.
     *
     * @return array<string, string>
     */
    private function companyRows(): array
    {
        $rows = [];

        foreach (Schema::getTables() as $table) {
            $name = (string) $table['name'];

            if (! Schema::hasColumn($name, 'organization_id')) {
                continue;
            }

            $query = DB::table($name)->orderBy(Schema::hasColumn($name, 'id') ? 'id' : 'organization_id');

            if ($name === 'audit_logs') {
                $query->whereNotNull('organization_id');
            }

            $rows[$name] = json_encode($query->get());
        }

        return $rows;
    }

    public function test_a_dry_run_reads_its_pages_one_at_a_time_and_ends(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);

        $trial = $this->trialWithPages(2);
        $this->startTrial($trial);

        $this->assertSame(ReaderTrial::RUNNING, $trial->refresh()->status);
        $this->assertCount(1, $this->queuedTrialPages());

        $this->assertSame(2, $this->workTheQueue());

        $trial->refresh();
        $this->assertSame(ReaderTrial::DONE, $trial->status);
        $this->assertNotNull($trial->finished_at);
        $this->assertSame(10400, (int) $trial->input_tokens);
        $this->assertSame(6000, (int) $trial->output_tokens);

        foreach ($this->pagesOf($trial) as $page) {
            $this->assertSame(ReaderTrialPage::READ, $page->status);
            $this->assertSame('other', $page->result['page_kind']);
            $this->assertSame(1, (int) $page->attempts);
        }

        $this->assertCount(2, $this->sent);
        $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}")->assertOk()->assertSee('12.500')->assertSee('rows.qty');
        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertSee("#{$trial->id}");
        $this->actingAs($this->admin)->getJson("/admin/ai-reader/trials/{$trial->id}/status")->assertOk()
            ->assertJsonPath('status', ReaderTrial::DONE)->assertJsonPath('pages.read', 2)->assertJsonPath('moving', false);
    }

    public function test_a_dry_run_changes_no_row_of_any_company_and_charges_nobody(): void
    {
        $this->setCredits($this->organization, '500');
        $this->setCredits($this->admin->organization, '500');
        $this->fakeReader([$this->answer('table', 5200, 3000)]);
        $before = $this->companyRows();

        $this->actingAs($this->admin)->post('/admin/ai-reader/trials', ['allowed' => '1', 'kind' => 'boq', 'pass' => 'rows', 'pages_total' => 1])->assertRedirect();
        $trial = ReaderTrial::query()->sole();
        $this->uploadPage($trial)->assertCreated();
        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/start")->assertRedirect();
        $this->workTheQueue();
        $this->actingAs($this->admin)->getJson("/admin/ai-reader/trials/{$trial->id}/status")->assertOk();
        $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}")->assertOk();

        // The second pass followed the first, as on a company's page.
        $this->assertSame(['rows', 'amounts'], array_map(fn (ReaderTrialPage $page) => $page->pass, $this->pagesOf($trial)));
        $this->assertSame(ReaderTrial::DONE, $trial->refresh()->status);
        $this->assertTrue(Dec::of((string) $trial->cost_usd)->isPositive());

        $this->assertSame($before, $this->companyRows());

        // The release switch is not needed for a dry run, and a dry run does not turn it on.
        $this->assertFalse(app(AiSpend::class)->switchedOn());

        // What left the server carried no company, person or file name.
        foreach ($this->sent as $body) {
            $sent = json_encode($body);
            $this->assertStringNotContainsString('Sahyadri', $sent);
            $this->assertStringNotContainsString('Platform Staff', $sent);
            $this->assertStringNotContainsString('page-1.jpg', $sent);
            $this->assertStringNotContainsString($this->admin->name, $sent);
        }

        $audits = AuditLog::withoutGlobalScopes()->whereIn('action', ['ai.trial_created', 'ai.trial_page_stored', 'ai.trial_started', 'ai.trial_page_sent'])->get();
        $this->assertCount(5, $audits);
        $this->assertTrue($audits->every(fn (AuditLog $audit) => $audit->organization_id === null && $audit->user_id === $this->admin->id));
    }

    public function test_each_call_is_recorded_for_the_platform_without_what_the_page_says(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        $trial = $this->trialWithPages(1);
        $this->startTrial($trial);
        $this->workTheQueue();

        $page = $this->pageOf($trial);
        [$sent] = $this->platformAudits('ai.trial_page_sent');

        $this->assertSame($page->id, $sent->auditable_id);
        $this->assertSame($page->image_sha256, $sent->new_values['sha256']);
        $this->assertSame((int) $page->image_bytes, $sent->new_values['bytes']);
        $this->assertSame('claude-sonnet-5-5', $sent->new_values['model']);
        $this->assertSame('req_test_1', $sent->new_values['provider_request_id']);
        $this->assertSame('read', $sent->new_values['outcome']);

        $recorded = json_encode(AuditLog::withoutGlobalScopes()->get()->map->only(['new_values', 'old_values']));
        $this->assertStringNotContainsString('12.500', $recorded);
        $this->assertStringNotContainsString('4,250.00', $recorded);
        $this->assertStringNotContainsString(substr(base64_encode((string) Storage::get($page->image_path)), 0, 40), $recorded);
    }

    public function test_the_cost_counts_toward_the_month_and_the_dry_runs_allowance_but_not_the_companies_day(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        $spend = app(AiSpend::class);
        $trial = $this->trialWithPages(1);

        $this->startTrial($trial);
        $this->workTheQueue();

        $cost = (string) $trial->refresh()->cost_usd;
        $this->assertTrue(Dec::of((string) $cost)->isPositive());
        $this->assertTrue($spend->platformCostThisMonth()->isEqualTo(Dec::of((string) $cost)));
        $this->assertTrue($spend->trialCostToday()->isEqualTo(Dec::of((string) $cost)));
        $this->assertTrue($spend->platformCostToday()->isZero());
    }

    public function test_the_dry_runs_stop_at_their_own_daily_allowance(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        // One page costs 0.0404 US dollars at the Sonnet prices in the config.
        app(PlatformSettings::class)->set(['ai_trial_daily_usd' => '0.03']);

        $trial = $this->trialWithPages(2);
        $this->startTrial($trial);
        $this->workTheQueue();

        $trial->refresh();
        $this->assertSame(ReaderTrial::PAUSED, $trial->status);
        $this->assertSame(ReaderTrial::PAUSE_ALLOWANCE, $trial->pause_reason);
        $this->assertCount(1, $this->sent);
        $this->assertSame(ReaderTrialPage::WAITING, $this->pageOf($trial, 2)->status);

        $this->actingAs($this->admin)->postJson("/admin/ai-reader/trials/{$trial->id}/start")
            ->assertStatus(409)->assertJsonPath('code', ReaderTrial::PAUSE_ALLOWANCE);
        $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}")->assertOk()->assertSee('allowance for dry runs is used', false);

        // A company's day is not touched: its readers are not paused by the dry runs.
        $this->assertNull(app(AiSpend::class)->platformStop());
    }

    public function test_an_allowance_of_zero_switches_dry_runs_off(): void
    {
        app(PlatformSettings::class)->set(['ai_trial_daily_usd' => '0']);
        $trial = $this->trialWithPages(1);

        $this->actingAs($this->admin)->postJson("/admin/ai-reader/trials/{$trial->id}/start")->assertStatus(409)->assertJsonPath('code', ReaderTrial::PAUSE_ALLOWANCE);
        $this->assertSame(ReaderTrial::DRAFT, $trial->refresh()->status);
        $this->assertSame([], $this->sent);
    }

    public function test_the_dry_runs_hold_to_the_months_limit_for_the_readers(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        app(PlatformSettings::class)->set(['ai_tier_monthly_cap_usd' => '0.03', 'ai_reader_month_share' => '1']);

        $trial = $this->trialWithPages(2);
        $this->startTrial($trial);
        $this->workTheQueue();

        $this->assertSame(ReaderTrial::PAUSE_MONTH, $trial->refresh()->pause_reason);
        $this->assertCount(1, $this->sent);
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        $trial = $this->trialWithPages(1);

        $this->actingAs($this->admin)->postJson("/admin/ai-reader/trials/{$trial->id}/start")->assertStatus(409)->assertJsonPath('code', ReaderTrial::PAUSE_NOT_CONNECTED);
        $this->assertSame([], $this->sent);
    }

    public function test_a_call_that_went_wrong_is_tried_again_and_sent_again_on_start(): void
    {
        $this->fakeReader([$this->failure(500), $this->failure(500), $this->failure(500), $this->answer()]);

        $trial = $this->trialWithPages(1);
        $this->startTrial($trial);
        $page = $this->pageOf($trial);

        // Twice it waits and goes again by itself; the third time it is given up.
        foreach ([ReaderTrialPage::QUEUED, ReaderTrialPage::QUEUED, ReaderTrialPage::FAILED] as $expected) {
            $this->runTrialPage($page);
            $this->assertSame($expected, $page->refresh()->status);
            $this->travel(2)->minutes();
        }

        $this->assertSame('unavailable', $page->fail_reason);
        $this->assertTrue($page->mayBeSentAgain());
        $this->assertSame(ReaderTrial::DONE, $trial->refresh()->status);
        $this->assertCount(3, $this->sent);

        $this->startTrial($trial);
        $this->runTrialPage($page);

        $this->assertSame(ReaderTrialPage::READ, $this->pageOf($trial)->status);
        $this->assertCount(4, $this->sent);
    }

    public function test_an_answer_that_cannot_be_used_is_a_measurement_and_is_not_sent_again(): void
    {
        $this->fakeReader([$this->answer('unusable')]);

        $trial = $this->trialWithPages(1);
        $this->startTrial($trial);
        $this->workTheQueue();

        $page = $this->pageOf($trial);
        $this->assertSame(ReaderTrialPage::FAILED, $page->status);
        $this->assertSame('invalid_output', $page->fail_reason);
        $this->assertFalse($page->mayBeSentAgain());

        $this->actingAs($this->admin)->postJson("/admin/ai-reader/trials/{$trial->id}/start")->assertStatus(409)->assertJsonPath('code', 'wrong_state');
        $this->assertCount(1, $this->sent);
    }

    public function test_a_dry_run_needs_the_tick_that_the_document_may_be_used(): void
    {
        $this->actingAs($this->admin)->from('/admin/ai-reader')
            ->post('/admin/ai-reader/trials', ['kind' => 'boq', 'pass' => 'rows', 'pages_total' => 1])
            ->assertSessionHasErrors('allowed');

        $this->actingAs($this->admin)->from('/admin/ai-reader')
            ->post('/admin/ai-reader/trials', ['allowed' => '1', 'kind' => 'not_deployed', 'pass' => 'rows', 'pages_total' => 1])
            ->assertSessionHasErrors('kind');

        $this->actingAs($this->admin)->from('/admin/ai-reader')
            ->post('/admin/ai-reader/trials', ['allowed' => '1', 'kind' => 'boq', 'pass' => 'rows', 'pages_total' => 1, 'model' => 'claude-unknown', 'max_tokens' => 50])
            ->assertSessionHasErrors(['model', 'max_tokens']);

        $this->assertSame(0, ReaderTrial::count());
    }

    public function test_an_upload_is_redrawn_by_the_server_and_kept_in_the_dry_runs_own_folder(): void
    {
        $trial = $this->makeTrial(['pages_total' => 1]);
        $upload = $this->pagePicture();

        $this->uploadPage($trial, 1, $upload)->assertCreated()->assertJsonPath('page_no', 1);
        $this->uploadPage($trial, 2)->assertStatus(422);
        $this->uploadPage($trial, 1, 'not a picture')->assertStatus(422);

        $page = $this->pageOf($trial);
        $this->assertSame(TrialFiles::picture($trial->refresh(), 1), $page->image_path);
        $this->assertStringStartsWith('platform/reader-trials/'.$trial->uuid.'/', $page->image_path);
        $this->assertNotSame(hash('sha256', $upload), $page->image_sha256);
        $this->assertSame($page->image_sha256, hash('sha256', (string) Storage::get($page->image_path)));
        $this->assertCount(1, $this->platformAudits('ai.trial_page_stored'));
    }

    public function test_cells_are_marked_wrong_counted_by_field_and_script_and_downloaded_as_counts(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        $trial = $this->trialWithPages(1);
        $this->startTrial($trial);
        $this->workTheQueue();

        $mark = fn (array $data) => $this->actingAs($this->admin)->postJson("/admin/ai-reader/trials/{$trial->id}/marks", ['page_no' => 1, 'pass' => 'rows', ...$data]);

        $mark(['row' => '0', 'field' => 'rows.qty', 'wrong' => true])->assertOk()
            ->assertJsonPath('score.cells_total', 2)->assertJsonPath('score.cells_wrong', 1)
            ->assertJsonPath('score.scripts.ascii.cells_wrong', 1);
        $mark(['row' => '', 'field' => '*', 'wrong' => true])->assertOk()->assertJsonPath('score.answers_wrong', 1);
        $mark(['row' => '7', 'field' => 'rows.qty', 'wrong' => true])->assertStatus(422);
        $mark(['row' => '0', 'field' => 'rows.qty', 'wrong' => false])->assertOk()->assertJsonPath('score.cells_wrong', 0);
        $mark(['row' => '0', 'field' => 'rows.rate', 'wrong' => true])->assertOk();

        $csv = $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}/scores.csv")->assertOk()->streamedContent();

        $this->assertStringContainsString("cells,,all,2,1\n", $csv);
        $this->assertStringContainsString("field,rows,rows.rate,1,1\n", $csv);
        $this->assertStringContainsString("digit_script,,ascii,2,1\n", $csv);
        $this->assertStringNotContainsString('4,250', $csv);
    }

    public function test_after_a_day_the_files_and_answers_go_and_the_counts_tokens_and_cost_stay(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        $trial = $this->trialWithPages(1);
        $this->startTrial($trial);
        $this->workTheQueue();
        $this->actingAs($this->admin)->postJson("/admin/ai-reader/trials/{$trial->id}/marks", ['page_no' => 1, 'pass' => 'rows', 'row' => '0', 'field' => 'rows.qty', 'wrong' => true])->assertOk();

        $cost = (string) $trial->refresh()->cost_usd;
        $page = $this->pageOf($trial);
        $this->assertTrue(Storage::exists($page->image_path));

        $this->travel(23)->hours();
        $this->tending()->all();
        $this->assertTrue(Storage::exists($page->image_path));

        $this->travel(2)->hours();
        $this->tending()->all();

        $trial->refresh();
        $page->refresh();
        $this->assertNotNull($trial->purged_at);
        $this->assertSame([], Storage::allFiles(TrialFiles::directory($trial)));
        $this->assertNull($page->image_path);
        $this->assertNull($page->result);
        $this->assertNull($page->marks);
        $this->assertSame($cost, (string) $trial->cost_usd);
        $this->assertSame(5200, (int) $trial->input_tokens);
        $this->assertSame(1, (int) $trial->score['cells_wrong']);
        $this->assertSame(ReaderTrialPage::READ, $page->status);
        $this->assertCount(1, $this->platformAudits('ai.trial_purged'));

        $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}")->assertOk()->assertSee('have been deleted');
    }

    public function test_deleting_a_dry_run_removes_its_files_and_keeps_its_cost_in_the_totals(): void
    {
        $this->fakeReader([$this->answer('other', 5200, 3000)]);
        $trial = $this->trialWithPages(1);
        $this->startTrial($trial);
        $this->workTheQueue();
        $cost = app(AiSpend::class)->platformCostThisMonth();

        $this->actingAs($this->admin)->delete("/admin/ai-reader/trials/{$trial->id}")->assertRedirect('/admin/ai-reader');

        $this->assertSame(ReaderTrial::DISCARDED, $trial->refresh()->status);
        $this->assertSame([], Storage::allFiles(TrialFiles::directory($trial)));
        $this->assertTrue(app(AiSpend::class)->platformCostThisMonth()->isEqualTo($cost));
        $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}")->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertDontSee("#{$trial->id}</a>", false);
    }
}
