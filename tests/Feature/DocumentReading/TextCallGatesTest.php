<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Models\User;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\ProviderBlock;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;

/**
 * Every reason a waiting call is not sent after all. Each one ends the call as failed with its own
 * reason, gives back what it held, tells the feature, and makes no request.
 */
class TextCallGatesTest extends TextCallTestCase
{
    private function waiting(string $purpose = self::PURPOSE, ?User $by = null, ?DocumentRead $record = null): AiTextCall
    {
        if ($purpose === 'voice') {
            $this->consent($this->organization);
        }

        $call = $this->start($record, $by, $purpose);
        $this->fakeModel([$this->answer()]);

        return $call;
    }

    private function assertNotSent(AiTextCall $call, string $reason): void
    {
        Http::assertNothingSent();

        $call->refresh();
        $this->assertSame([AiTextCall::FAILED, $reason, 0, '0.0000', '0.000000'], [$call->status, $call->fail_reason, $call->attempts, $call->credits, $call->cost_usd]);
        $this->assertSame([[$call->id, $reason]], FakeTextHandler::$failed);
        $this->assertSame([], FakeTextHandler::$done);
        $this->assertSame([], $this->audits());
        $this->assertSame([$call->id], $this->queuedCalls());
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        $this->assertNotCharged();
    }

    public function test_a_call_that_is_not_waiting_is_left_alone(): void
    {
        $call = $this->waiting();

        foreach ([AiTextCall::RUNNING, AiTextCall::DONE, AiTextCall::FAILED, AiTextCall::REJECTED] as $status) {
            AiTextCall::withoutGlobalScopes()->whereKey($call->id)->update(['status' => $status]);

            $this->runCall($call);

            $this->assertSame([$status, 0], [$call->fresh()->status, $call->fresh()->attempts]);
        }

        $this->runCall(987654);

        Http::assertNothingSent();
        $this->assertSame([], FakeTextHandler::$failed);
    }

    public function test_it_is_not_sent_once_the_person_who_asked_is_gone(): void
    {
        $call = $this->waiting();
        User::whereKey($this->engineer->id)->delete();

        $this->runCall($call);

        $this->assertNull($call->fresh()->user_id);
        $this->assertNotSent($call, 'starter_gone');
    }

    public function test_it_is_not_sent_once_the_person_who_asked_is_switched_off(): void
    {
        $call = $this->waiting();
        $this->engineer->forceFill(['is_active' => false])->save();

        $this->runCall($call);

        $this->assertNotSent($call, 'starter_gone');
    }

    public function test_it_is_not_sent_once_the_persons_account_was_deleted_and_kept_only_as_a_name(): void
    {
        $call = $this->waiting();
        $this->engineer->forceFill(['anonymised_at' => now()])->save();

        $this->runCall($call);

        $this->assertNotSent($call, 'starter_gone');
    }

    public function test_it_is_not_sent_once_the_person_may_no_longer_use_the_ai_features(): void
    {
        $call = $this->waiting();
        $this->engineer->forceFill(['role' => 'viewer'])->save();

        $this->runCall($call);

        $this->assertNotSent($call, 'starter_gone');
    }

    public function test_it_is_not_sent_once_the_person_no_longer_sees_the_project(): void
    {
        $project = $this->makeProject($this->organization);
        $other = $this->makeProject($this->organization, ['name' => 'Another Site']);
        $call = $this->waiting(record: $this->record(overrides: ['project_id' => $project->id]));

        $this->engineer->forceFill(['limited_to_projects' => true])->save();
        DB::table('user_project_access')->insert(['organization_id' => $this->organization->id, 'user_id' => $this->engineer->id, 'project_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->runCall($call);

        $this->assertNotSent($call, 'starter_gone');
    }

    public function test_someone_limited_to_the_calls_own_project_is_still_sent_for(): void
    {
        $project = $this->makeProject($this->organization);
        $call = $this->waiting(record: $this->record(overrides: ['project_id' => $project->id]));

        $this->engineer->forceFill(['limited_to_projects' => true])->save();
        DB::table('user_project_access')->insert(['organization_id' => $this->organization->id, 'user_id' => $this->engineer->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
    }

    public function test_a_spoken_report_is_not_sent_once_the_company_has_switched_the_readers_off(): void
    {
        $call = $this->waiting('voice');
        $this->consent($this->organization, false);

        $this->runCall($call);

        $this->assertNotSent($call, 'consent_off');
    }

    public function test_nor_under_a_notice_the_company_agreed_to_before_it_was_changed(): void
    {
        $call = $this->waiting('voice');
        $this->consent($this->organization, true, 'ai-docs-v0');

        $this->runCall($call);

        $this->assertNotSent($call, 'consent_off');
    }

    public function test_a_call_that_sends_only_what_the_code_prepared_does_not_need_the_switch(): void
    {
        $call = $this->waiting();
        $this->assertNull(AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $this->organization->id)->first());

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertSame([], FakeTextHandler::$failed);
        // The switch was not even looked for hard enough to make the company's settings row.
        $this->assertNull(AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $this->organization->id)->first());
    }

    public function test_such_a_call_goes_out_with_personal_details_taken_out_and_a_spoken_report_goes_as_it_was_heard(): void
    {
        FakeTextHandler::$line = 'Shuttering as told by Patil on 98220 12345';

        $this->runCall($this->waiting());
        $this->runCall($this->waiting('voice'));

        $this->assertStringContainsString('Shuttering as told by Patil on [redacted]', $this->messageOf($this->sent[0]));
        $this->assertStringNotContainsString('98220', $this->messageOf($this->sent[0]));
        $this->assertStringContainsString('Shuttering as told by Patil on 98220 12345', $this->messageOf($this->sent[1]));
    }

    public function test_it_is_not_sent_once_the_plan_has_no_ai(): void
    {
        $call = $this->waiting();
        $this->organization->update(['plan' => 'contractor']);

        $this->runCall($call);

        $this->assertNotSent($call, 'plan');
    }

    public function test_it_is_not_sent_without_a_saved_key_or_while_the_provider_refuses_every_call(): void
    {
        $call = $this->waiting();
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);

        $this->runCall($call);

        $this->assertNotSent($call, 'not_connected');
    }

    public function test_it_is_not_sent_while_a_stop_on_the_provider_stands(): void
    {
        $call = $this->waiting();
        ProviderBlock::set(ModelGatewayException::SPEND_LIMIT);

        $this->runCall($call);

        $this->assertNotSent($call, 'not_connected');
    }

    public function test_it_is_not_sent_once_the_readers_are_switched_off_again(): void
    {
        $call = $this->waiting();
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);

        $this->runCall($call);

        $this->assertNotSent($call, 'not_released');
    }

    public function test_it_is_not_sent_once_the_platforms_limit_for_the_day_is_reached(): void
    {
        $call = $this->waiting();
        app(PlatformSettings::class)->set(['ai_reader_daily_usd' => '1']);
        $this->paidPage($this->makeRead($this->tenant('Konkan Infra')), 1, '1.000000');

        $this->runCall($call);

        $this->assertNotSent($call, 'platform_ceiling');
    }

    public function test_it_is_not_sent_once_the_company_is_blocked_or_has_caused_too_many_unusable_answers(): void
    {
        $call = $this->waiting();
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);

        $this->runCall($call);

        $this->assertNotSent($call, 'company_limit');

        // The brake: refused answers of the last day, counted when the next call is about to be sent.
        AiDocumentSettings::for($this->organization)->update(['platform_block' => false]);
        config(['modules.document_reading.failed_pages_per_day' => 2]);
        FakeTextHandler::reset();
        $second = $this->start($this->record());

        foreach ([1, 2] as $ignored) {
            AiTextCall::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'purpose' => 'rate_match', 'model' => 'claude-haiku-5-5', 'max_credits' => '1', 'status' => AiTextCall::REJECTED]);
        }

        $this->runCall($second);

        Http::assertNothingSent();
        $this->assertSame([AiTextCall::FAILED, 'company_limit'], [$second->fresh()->status, $second->fresh()->fail_reason]);
    }

    public function test_a_trial_s_last_allowed_call_is_sent_although_it_uses_the_allowance_up(): void
    {
        config(['modules.document_reading.trial.text_calls' => 1]);
        $trial = $this->trialTenant();
        $owner = $this->member($trial, 'owner');

        $call = $this->start($this->record($trial), $owner);
        $this->fakeModel([$this->answer()]);

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_a_call_whose_handler_is_no_longer_there_ends_without_one(): void
    {
        $call = $this->waiting();
        config(['modules.text_stand_ins.text_calls' => ['voice' => FakeTextHandler::class]]);

        $this->runCall($call);

        Http::assertNothingSent();
        $this->assertSame([AiTextCall::FAILED, 'internal_error'], [$call->fresh()->status, $call->fresh()->fail_reason]);
        $this->assertSame([], FakeTextHandler::$failed);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
    }

    public function test_the_gates_are_passed_again_before_the_corrective_request(): void
    {
        $call = $this->waiting();
        FakeTextHandler::$refuse = fn () => ['note' => ['digit']];

        $this->runCall($call);
        $this->assertSame(AiTextCall::QUEUED, $call->fresh()->status);

        $this->engineer->forceFill(['is_active' => false])->save();
        $this->runCall($call);

        Http::assertSentCount(1);
        $this->assertSame([AiTextCall::FAILED, 'starter_gone', 1], [$call->fresh()->status, $call->fresh()->fail_reason, $call->fresh()->attempts]);
        $this->assertSame([[$call->id, 'starter_gone']], FakeTextHandler::$failed);
    }
}
