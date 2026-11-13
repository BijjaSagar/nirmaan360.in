<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiTextCall;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\DocumentReading\ReaderTrials;
use App\Services\DocumentReading\TextCallHandlers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\DocumentReading\Support\FakePastedHandler;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;

/**
 * A text call tried on pasted notes, and a reader tried on pasted page text: the same request
 * builder and the same checks as a company's call, with nothing of any company in it. Also the
 * migration of the dry-run tables.
 */
class ReaderTrialTextTest extends ReaderTrialTestCase
{
    private const MIGRATION = 'database/migrations/2027_01_10_100003_create_reader_trial_tables.php';

    protected function setUp(): void
    {
        parent::setUp();

        FakePastedHandler::reset();
        config(['modules.text_stand_ins.text_calls' => ['wording' => FakePastedHandler::class, 'rate_match' => FakeTextHandler::class]]);
        $this->app->forgetInstance(TextCallHandlers::class);
    }

    /** @param  array<string, mixed>  $output */
    private function textAnswer(array $output, int $input = 900, int $output_tokens = 300): array
    {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($output)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output_tokens],
        ];
    }

    private function notesTrial(): ReaderTrial
    {
        $this->actingAs($this->admin)->post('/admin/ai-reader/trials', ['allowed' => '1', 'purpose' => 'wording'])->assertRedirect();

        return ReaderTrial::query()->latest('id')->firstOrFail();
    }

    public function test_only_a_text_call_that_can_work_from_pasted_text_is_offered(): void
    {
        $purposes = array_column(app(ReaderTrials::class)->targets()['purposes'], 'purpose');

        $this->assertSame(['wording'], $purposes);

        $this->actingAs($this->admin)->from('/admin/ai-reader')->post('/admin/ai-reader/trials', ['allowed' => '1', 'purpose' => 'rate_match'])->assertSessionHasErrors('purpose');
        $this->actingAs($this->admin)->get('/admin/ai-reader')->assertOk()->assertSee('Text call: letters and explanations');
    }

    public function test_pasted_notes_go_through_the_handlers_own_request_and_checks_and_nothing_is_kept_in_a_company(): void
    {
        $this->fakeReader([$this->textAnswer(['groups' => [['title' => 'Late drawings', 'notes' => [1, 2]]]])]);

        $trial = $this->notesTrial();
        $this->assertSame(ReaderTrial::TEXT, $trial->material);
        $this->assertSame('claude-sonnet-5-5', $trial->model);

        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/text", ['text' => "1. Drawings for slab came late.\n2. Call 9876543210 for the revised drawing."])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/start")->assertSessionHasNoErrors();
        $this->workTheQueue();

        $page = $this->pageOf($trial);
        $this->assertSame(ReaderTrialPage::READ, $page->status);
        $this->assertSame('Late drawings', $page->result['groups'][0]['title']);
        $this->assertSame(ReaderTrial::DONE, $trial->refresh()->status);

        [$body] = $this->sent;
        $sent = json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(FakePastedHandler::INSTRUCTIONS, $sent);
        $this->assertStringContainsString('Drawings for slab came late.', $sent);
        // The purpose redacts personal details, for a dry run as for a company.
        $this->assertStringNotContainsString('9876543210', $sent);

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        $this->actingAs($this->admin)->get("/admin/ai-reader/trials/{$trial->id}")->assertOk()->assertSee('Late drawings');
    }

    public function test_an_answer_the_checks_refuse_is_asked_for_once_more_then_counted_as_refused(): void
    {
        FakePastedHandler::$refuse = fn (array $output) => ['groups' => ['figure_in_text']];
        $this->fakeReader([$this->textAnswer(['groups' => [['title' => 'Late by 12 days', 'notes' => [1]]]])]);

        $trial = $this->notesTrial();
        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/text", ['text' => 'Drawings came late.'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/start");
        $this->workTheQueue();

        $page = $this->pageOf($trial);
        $this->assertSame(ReaderTrialPage::FAILED, $page->status);
        $this->assertSame('rejected', $page->fail_reason);
        $this->assertSame(2, (int) $page->refused);
        $this->assertCount(2, $this->sent);
        $this->assertSame([[1, null], [2, ['groups' => ['figure_in_text']]]], FakePastedHandler::$asked);
        $this->assertFalse($page->mayBeSentAgain());
    }

    public function test_a_reader_is_tried_on_pasted_page_text(): void
    {
        $this->fakeReader([$this->answer('other', 2000, 800)]);

        $trial = $this->makeTrial(['kind' => 'tender', 'pass' => 'text', 'material' => 'text', 'pages_total' => 2]);
        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/text", ['page_no' => 2, 'text' => 'Last date of submission: 12.03.2027'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/text", ['page_no' => 3, 'text' => 'x'])->assertSessionHasErrors('page_no');
        $this->uploadPage($trial)->assertStatus(409);

        $this->startTrial($trial);
        $this->workTheQueue();

        $this->assertSame(ReaderTrialPage::READ, $this->pageOf($trial, 2, 'text')->status);
        $this->assertStringContainsString('<page number=\"2\">', json_encode($this->sent[0]));
        $this->assertStringContainsString('Last date of submission', json_encode($this->sent[0]));
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $trial = $this->trialWithPages(1);
        $this->assertTrue(Schema::hasTable('reader_trial_pages'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('reader_trials'));
        $this->assertFalse(Schema::hasTable('reader_trial_pages'));
        $this->assertTrue(Schema::hasTable('document_read_pages'));

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('reader_trials'));
        $this->assertFalse(Schema::hasColumn('reader_trials', 'organization_id'));
        $this->assertFalse(Schema::hasColumn('reader_trial_pages', 'organization_id'));
        $this->assertSame(0, DB::table('reader_trials')->where('id', $trial->id)->count());
    }
}
