<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AiDocumentSettings;
use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\ProviderBlock;
use App\Services\DocumentReading\ReaderSettings;
use App\Services\DocumentReading\ReadQuote;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCallHandlers;
use App\Services\DocumentReading\TextRequest;
use App\Support\PlatformSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;
use Throwable;

/** Asking for a text call: what is made and held, and everything that refuses it before anything is made. */
class TextCallStartTest extends TextCallTestCase
{
    /** @template T of Throwable @param class-string<T> $class @return T */
    private function refusal(string $class, callable $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($class, $thrown, $thrown->getMessage());
            $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count(), 'A call was made although it was refused.');
            $this->assertSame([], $this->queuedCalls());
            Http::assertNothingSent();

            return $thrown;
        }

        $this->fail('Nothing was refused.');
    }

    public function test_a_call_is_made_for_the_record_holds_its_maximum_and_goes_to_the_queue(): void
    {
        $project = $this->makeProject($this->organization);
        $record = $this->record(overrides: ['project_id' => $project->id]);

        $call = $this->start($record);

        $this->assertSame([
            'organization_id' => $this->organization->id,
            'project_id' => $project->id,
            'user_id' => $this->engineer->id,
            'purpose' => 'rate_match',
            'subject_type' => DocumentRead::class,
            'subject_id' => $record->id,
            'client_uuid' => null,
            'model' => 'claude-haiku-5-5',
            'status' => AiTextCall::QUEUED,
            'attempts' => 0,
            'credits' => '0.0000',
        ], $call->only(['organization_id', 'project_id', 'user_id', 'purpose', 'subject_type', 'subject_id', 'client_uuid', 'model', 'status', 'attempts', 'credits']));

        // The maximum is the dearest the call can be: every character of the request taken as a token, room for one correction, and all it may write.
        $body = (new FakeTextHandler)->request($call);
        $chars = TextRequest::chars($body);
        $this->assertSame($chars['message'], $call->input_chars);
        $this->assertSame(app(ReadQuote::class)->worst('claude-haiku-5-5', $chars['all'] + 800, 3000), $call->max_credits);
        $this->assertTrue($call->max_credits > '0.1800' && $call->max_credits < '0.3000', $call->max_credits);

        // While it waits, that much of the wallet is spoken for.
        $this->assertSame($call->max_credits, $this->credits()->held($this->organization));
        $this->assertSame($this->wallet($this->organization)->available(), '500.0000');

        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->callId === $call->id && $job->queue === null && $job->delay === null);
        $this->assertSame([$call->id], $this->queuedCalls());

        // Nothing was sent by asking, and the request was built once without a row to look at what it would be.
        Http::assertNothingSent();
        $this->assertSame([null, 0], FakeTextHandler::$asked[0]);
        $this->assertSame([], $this->audits());
    }

    public function test_the_job_goes_to_the_interactive_lane_when_the_platform_admin_has_named_one(): void
    {
        app(PlatformSettings::class)->set(['ai_queue_interactive' => 'ai-interactive', 'ai_queue_pages' => 'ai-pages']);

        $call = $this->start();

        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->callId === $call->id && $job->queue === 'ai-interactive');
    }

    public function test_the_project_can_be_named_when_the_record_has_none_of_its_own(): void
    {
        $project = $this->makeProject($this->organization);
        $elsewhere = $this->makeProject($this->tenant('Konkan Infra'));

        $this->assertNull($this->start($this->record())->project_id);
        $this->assertSame($project->id, $this->start($this->record(), with: ['project' => $project])->project_id);
        // Named as none, although the record has one.
        $this->assertNull($this->start($this->record(overrides: ['project_id' => $project->id]), with: ['project' => null])->project_id);

        try {
            $this->start($this->record(), with: ['project' => $elsewhere]);
            $this->fail('A project of another company was taken.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        // A call about a project itself belongs to that project.
        config(['modules.text_stand_ins.text_calls' => ['rate_match' => [Project::class => (new class extends FakeTextHandler
        {
            public function request(AiTextCall $call): array
            {
                return TextRequest::body($call, self::INSTRUCTIONS, self::schema(), ['Rank the candidates.']);
            }
        })::class]]]);

        $this->assertSame($project->id, $this->calls()->start('rate_match', $project, $this->owner)->project_id);
    }

    public function test_pressing_twice_makes_one_call(): void
    {
        $record = $this->record();

        $first = $this->start($record);
        $again = $this->start($record, $this->owner);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame([$first->id], $this->queuedCalls());

        // The same while it is with the model. Another record, or another purpose for this one, is another call.
        AiTextCall::withoutGlobalScopes()->whereKey($first->id)->update(['status' => AiTextCall::RUNNING]);
        $this->assertSame($first->id, $this->start($record)->id);
        $this->assertNotSame($first->id, $this->start($this->record())->id);

        $this->consent($this->organization);
        $this->assertNotSame($first->id, $this->start($record, purpose: 'voice')->id);

        // Once it has ended, asking again is a new call.
        AiTextCall::withoutGlobalScopes()->whereKey($first->id)->update(['status' => AiTextCall::DONE]);
        $this->assertNotSame($first->id, $this->start($record)->id);
    }

    public function test_a_feature_can_have_several_calls_about_one_record_by_giving_each_its_own_client_uuid(): void
    {
        $record = $this->record();
        [$one, $two] = [(string) Str::uuid(), (string) Str::uuid()];

        $first = $this->start($record, with: ['client_uuid' => $one]);
        $second = $this->start($record, with: ['client_uuid' => $two]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame([$first->id, $second->id], $this->queuedCalls());
        // Each still answers to its own value, and both hold their maximum.
        $this->assertSame($first->id, $this->start($record, with: ['client_uuid' => $one])->id);
        $this->assertSame($second->id, $this->start($record, with: ['client_uuid' => $two])->id);
        $this->assertSame(2, AiTextCall::withoutGlobalScopes()->count());
        $this->assertTrue($this->credits()->held($this->organization) > $first->max_credits);
    }

    public function test_a_repeated_client_uuid_returns_the_call_it_made_and_never_one_about_something_else(): void
    {
        $record = $this->record();
        $uuid = (string) Str::uuid();

        $first = $this->start($record, with: ['client_uuid' => $uuid]);
        $this->assertSame($uuid, $first->client_uuid);

        // Even after it has ended: the request that made it is the same request.
        AiTextCall::withoutGlobalScopes()->whereKey($first->id)->update(['status' => AiTextCall::DONE]);
        $this->assertSame($first->id, $this->start($record, with: ['client_uuid' => $uuid])->id);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());

        try {
            $this->start($this->record(), with: ['client_uuid' => $uuid]);
            $this->fail('A client_uuid was taken for another record.');
        } catch (ValidationException $refused) {
            $this->assertSame(['client_uuid'], array_keys($refused->errors()));
        }

        // Another company may use the same value: it names a call of theirs.
        $other = $this->tenant('Konkan Infra');
        $theirs = $this->start($this->record($other), $this->member($other, 'owner'), with: ['client_uuid' => $uuid]);
        $this->assertNotSame($first->id, $theirs->id);
    }

    public function test_someone_limited_to_other_projects_cannot_pick_up_a_call_by_its_client_uuid(): void
    {
        $hidden = $this->makeProject($this->organization, ['name' => 'Secret Plaza']);
        $record = $this->record(overrides: ['project_id' => $hidden->id]);
        $uuid = (string) Str::uuid();
        $this->start($record, $this->owner, with: ['client_uuid' => $uuid]);

        $outsider = $this->member($this->organization, 'engineer');
        $outsider->forceFill(['limited_to_projects' => true])->save();
        $this->actingAs($outsider);

        $this->expectException(ValidationException::class);
        $this->start($record, $outsider, with: ['client_uuid' => $uuid]);
    }

    public function test_a_call_is_about_a_saved_record_of_the_persons_own_company(): void
    {
        $theirs = $this->record($this->tenant('Konkan Infra'));

        foreach ([$theirs, new DocumentRead(['organization_id' => $this->organization->id, 'kind' => 'boq'])] as $record) {
            try {
                $this->start($record);
                $this->fail('A call was made about a record it may not be about.');
            } catch (LogicException) {
                $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
            }
        }
    }

    public function test_a_purpose_nobody_handles_cannot_be_asked_for(): void
    {
        $this->refusal(LogicException::class, fn () => $this->start(purpose: 'translate'));
        $this->refusal(LogicException::class, fn () => $this->start(purpose: 'poetry'));
    }

    public function test_it_needs_the_permission_an_active_account_and_a_plan_with_ai(): void
    {
        $viewer = $this->member($this->organization, 'viewer');
        $this->assertFalse($viewer->hasPermission('agents.use'));
        $this->refusal(AuthorizationException::class, fn () => $this->start(by: $viewer));

        $this->engineer->forceFill(['is_active' => false])->save();
        $this->refusal(AuthorizationException::class, fn () => $this->start());
        $this->engineer->forceFill(['is_active' => true])->save();

        $onContractorPlan = $this->tenant('Konkan Infra', 'contractor');
        $refused = $this->refusal(AuthorizationException::class, fn () => $this->start($this->record($onContractorPlan), $this->member($onContractorPlan, 'owner')));
        $this->assertSame('Your plan does not include this module. Upgrade to continue.', $refused->getMessage());
    }

    public function test_a_purpose_that_sends_a_persons_own_words_needs_the_companys_switch_and_the_others_do_not(): void
    {
        // Rate matching sends only what the code prepared, with personal details taken out.
        $this->assertSame(AiTextCall::QUEUED, $this->start()->status);
        AiTextCall::withoutGlobalScopes()->delete();
        Queue::fake();

        $refused = $this->refusal(ReadRefused::class, fn () => $this->start(purpose: 'voice'));
        $this->assertSame(['code' => 'consent_required'], $refused->toArray());
        $this->assertSame(409, $refused->status);

        // An older notice is not consent to this one.
        $this->consent($this->organization, true, 'ai-docs-v0');
        $this->refusal(ReadRefused::class, fn () => $this->start(purpose: 'voice'));

        $this->consent($this->organization);
        $this->assertSame('voice', $this->start(purpose: 'voice')->purpose);
    }

    public function test_it_is_refused_while_the_provider_cannot_be_reached_without_saying_why(): void
    {
        ProviderBlock::set(ModelGatewayException::BILLING);

        $refused = $this->refusal(ReadRefused::class, fn () => $this->start());
        $this->assertSame(['code' => 'not_connected'], $refused->toArray());
        $this->assertSame(409, $refused->status);
        $this->assertStringNotContainsStringIgnoringCase('billing', $refused->getMessage());

        ProviderBlock::clear();
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);

        $this->assertSame(['code' => 'not_connected'], $this->refusal(ReadRefused::class, fn () => $this->start())->toArray());
    }

    public function test_it_is_refused_until_the_readers_are_released_and_while_a_platform_limit_stands(): void
    {
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $refused = $this->refusal(SpendRefused::class, fn () => $this->start());
        $this->assertSame([409, ['code' => 'not_released']], [$refused->status, $refused->toArray()]);

        // Today's limit for every company is used up by pages read elsewhere.
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_reader_daily_usd' => '1']);
        $other = $this->tenant('Konkan Infra');
        $this->paidPage($this->makeRead($other), 1, '1.000000');

        $refused = $this->refusal(SpendRefused::class, fn () => $this->start());
        $this->assertSame([409, ['code' => 'reader_paused', 'period' => 'daily']], [$refused->status, $refused->toArray()]);
    }

    public function test_a_company_the_platform_admin_has_blocked_cannot_ask(): void
    {
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);

        $refused = $this->refusal(SpendRefused::class, fn () => $this->start());

        $this->assertSame([409, ['code' => 'company_limit', 'limit' => 'blocked']], [$refused->status, $refused->toArray()]);
    }

    public function test_a_persons_daily_budget_counts_what_calls_still_running_may_cost(): void
    {
        AiDocumentSettings::for($this->organization)->update(['budgets' => ['user' => ['rate_match' => '0.5']]]);

        $first = $this->start($this->record());
        $second = $this->start($this->record());
        $this->assertTrue($first->max_credits === $second->max_credits && $first->max_credits > '0.1800');

        // Two are on hold; a third would pass half a credit.
        try {
            $this->start($this->record());
            $this->fail('The budget did not stop the third call.');
        } catch (SpendRefused $refused) {
            $this->assertSame([402, ['code' => 'daily_budget', 'feature' => 'rate_match', 'scope' => 'user']], [$refused->status, $refused->toArray()]);
        }

        $this->assertSame(2, AiTextCall::withoutGlobalScopes()->count());

        // It is this person's budget: a colleague can still ask.
        $this->assertSame(AiTextCall::QUEUED, $this->start($this->record(), $this->owner)->status);
    }

    public function test_the_company_budget_stops_everyone(): void
    {
        AiDocumentSettings::for($this->organization)->update(['budgets' => ['company' => ['rate_match' => '0.1']]]);

        $refused = $this->refusal(SpendRefused::class, fn () => $this->start(by: $this->owner));

        $this->assertSame([402, ['code' => 'daily_budget', 'feature' => 'rate_match', 'scope' => 'company']], [$refused->status, $refused->toArray()]);
    }

    public function test_it_is_refused_when_the_wallet_cannot_cover_the_most_it_may_cost(): void
    {
        $this->setCredits($this->organization, '0.1000');

        $refused = $this->refusal(SpendRefused::class, fn () => $this->start());

        $this->assertSame([402, ['code' => 'no_credits', 'limit' => 'wallet']], [$refused->status, $refused->toArray()]);
        $this->assertSame('Your company does not have enough AI credits for this.', $refused->getMessage());
    }

    public function test_credits_a_read_has_on_hold_are_not_there_for_a_text_call(): void
    {
        $this->setCredits($this->organization, '10');
        $this->runningRead($this->organization, $this->owner, '9.9');

        $this->assertSame(['code' => 'no_credits', 'limit' => 'wallet'], $this->refusal(SpendRefused::class, fn () => $this->start())->toArray());

        // And a text call's own maximum is not there for the next one.
        DocumentRead::withoutGlobalScopes()->where('status', DocumentRead::RUNNING)->update(['credits_held' => '9.6']);
        $this->assertSame(AiTextCall::QUEUED, $this->start($this->record())->status);

        try {
            $this->start($this->record());
            $this->fail('The second call found credits the first one holds.');
        } catch (SpendRefused $refused) {
            $this->assertSame('no_credits', $refused->reason);
        }
    }

    public function test_a_trial_gets_its_number_of_text_calls_and_no_more(): void
    {
        config(['modules.document_reading.trial.text_calls' => 2]);
        $trial = $this->trialTenant();
        $owner = $this->member($trial, 'owner');

        $first = $this->start($this->record($trial), $owner);
        $this->start($this->record($trial), $owner);

        try {
            $this->start($this->record($trial), $owner);
            $this->fail('The trial made a third call.');
        } catch (SpendRefused $refused) {
            $this->assertSame([409, ['code' => 'company_limit', 'limit' => 'trial_text_calls']], [$refused->status, $refused->toArray()]);
        }

        // A call that failed was still one of them.
        AiTextCall::withoutGlobalScopes()->whereKey($first->id)->update(['status' => AiTextCall::FAILED]);
        $this->expectException(SpendRefused::class);
        $this->start($this->record($trial), $owner);
    }

    public function test_a_request_that_may_not_be_sent_is_found_before_anything_is_held(): void
    {
        FakeTextHandler::$spoil = function (array $body) {
            $body['tools'] = [['name' => 'approve_bill']];

            return $body;
        };

        $thrown = $this->refusal(LogicException::class, fn () => $this->start());
        $this->assertStringContainsString('"tools" is not part of a text call', $thrown->getMessage());

        // A detail the handler put in after the body was built is caught the same way.
        FakeTextHandler::$spoil = function (array $body) {
            $body['messages'][0]['content'][0]['text'] .= ' Phone 98220 12345.';

            return $body;
        };

        $this->assertStringContainsString('personal detail', $this->refusal(LogicException::class, fn () => $this->start())->getMessage());
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
    }

    public function test_a_handler_with_nothing_to_ask_makes_no_call(): void
    {
        $record = $this->record();
        $handler = new class extends FakeTextHandler
        {
            public function request(AiTextCall $call): array
            {
                throw new NothingToSend('nothing_to_rank');
            }
        };
        config(['modules.text_stand_ins.text_calls' => ['rate_match' => $handler::class]]);

        $thrown = $this->refusal(NothingToSend::class, fn () => $this->start($record));

        $this->assertSame('nothing_to_rank', $thrown->reason);
    }

    public function test_what_a_call_may_cost_at_most_can_be_said_on_a_button(): void
    {
        // A whole number of credits, never less than a call of that purpose can cost.
        $haiku = $this->calls()->upTo('rate_match');
        $sonnet = $this->calls()->upTo('wording');

        $this->assertSame('1.0000', $haiku);
        $this->assertSame('4.0000', $sonnet);
        $this->assertSame('3.0000', $this->calls()->upTo('wording', 500));

        // However long a feature says its request is, it is priced as no longer than a call may be.
        $this->assertSame($this->calls()->upTo('wording', 40000), $this->calls()->upTo('wording', 900000));
    }

    public function test_handlers_are_found_by_purpose_and_by_the_record_a_call_is_about(): void
    {
        $forReads = new class extends FakeTextHandler {};

        // The purposes the modules really deployed register (voice DPR's "voice" among them) come before the stand-ins'.
        $deployed = app(TextCallHandlers::class)->purposes();

        config(['modules.text_stand_ins.text_calls' => ['rate_match' => FakeTextHandler::class, 'wording' => [DocumentRead::class => $forReads::class]]]);
        config(['modules.more_stand_ins.text_calls' => ['rate_match' => FakeTextHandler::class, 'wording' => ['App\Models\SiteLetter' => FakeTextHandler::class]]]);

        $handlers = app(TextCallHandlers::class);

        $this->assertSame(array_values(array_unique([...$deployed, 'rate_match', 'wording'])), $handlers->purposes());
        $this->assertInstanceOf(FakeTextHandler::class, $handlers->get('rate_match'));
        $this->assertInstanceOf(FakeTextHandler::class, $handlers->get('rate_match', DocumentRead::class));
        $this->assertInstanceOf($forReads::class, $handlers->get('wording', DocumentRead::class));
        $this->assertNotInstanceOf($forReads::class, $handlers->get('wording', 'App\Models\SiteLetter'));
        $this->assertNull($handlers->find('wording'));
        $this->assertNull($handlers->find('wording', 'App\Models\Quotation'));
        $this->assertNull($handlers->find('voice'));
        $this->assertNull($handlers->find(null));

        // Each asking gets a handler of its own, so nothing one call left behind reaches another.
        $this->assertNotSame($handlers->get('rate_match'), $handlers->get('rate_match'));

        // The company's settings screen offers a daily budget for each purpose that is registered, in either form.
        $this->assertSame(['wording', 'rate_match'], array_values(array_intersect(app(ReaderSettings::class)->features(), ['wording', 'rate_match', 'voice', 'translate'])));
    }

    public function test_a_registration_that_cannot_work_is_refused_outright(): void
    {
        $cases = [
            'two classes for one purpose' => ['a' => ['rate_match' => FakeTextHandler::class], 'b' => ['rate_match' => (new class extends FakeTextHandler {})::class]],
            'two classes for one record' => ['a' => ['wording' => [DocumentRead::class => FakeTextHandler::class]], 'b' => ['wording' => [DocumentRead::class => (new class extends FakeTextHandler {})::class]]],
            'a class that is no handler' => ['a' => ['rate_match' => FakeKind::class]],
            'a purpose without figures' => ['a' => ['poetry' => FakeTextHandler::class]],
        ];

        foreach ($cases as $name => $modules) {
            config(['modules.text_stand_ins' => null, 'modules.a.text_calls' => $modules['a'], 'modules.b.text_calls' => $modules['b'] ?? []]);

            try {
                app(TextCallHandlers::class)->all();
                $this->fail("Accepted: {$name}.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_job_is_handed_over_only_when_the_call_is_really_made(): void
    {
        $this->useTheQueueTable();

        // The row and its job belong to one transaction: when the request around them is undone, neither is left.
        DB::beginTransaction();
        $this->start();
        $this->assertSame(0, DB::table('jobs')->count());
        DB::rollBack();

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('jobs')->count());

        $call = $this->start();

        $job = DB::table('jobs')->sole();
        $this->assertSame($call->id, unserialize(json_decode($job->payload, true)['data']['command'])->callId);

        // The job carries the id of the call and nothing of what will be sent.
        $this->assertStringNotContainsString('cement concrete', $job->payload);
        $this->assertStringNotContainsString('PCC', $job->payload);
    }
}
