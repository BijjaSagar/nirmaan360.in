<?php

namespace Tests\Feature\SiteDiary;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AgentCreditLedger;
use App\Models\AiDocumentSettings;
use App\Models\AiTextCall;
use App\Models\Organization;
use App\Services\Agents\CreditService;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\TextCallRunner;
use App\Support\PlatformSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\SetsAsideShippedReaders;

/**
 * The invented company of the letter tests, with the AI switched on for it: the platform admin has
 * released the readers with a monthly cap on record, a key is saved, a reader is deployed (the stand-in
 * of the reading tests, so the consent switch exists) and the owner has agreed to the notice.
 *
 * The provider is faked: nothing leaves the test. Call jobs are run by hand, one step at a time.
 */
abstract class SiteDiaryAiTestCase extends SiteLetterTestCase
{
    use SetsAsideShippedReaders;

    /** @var list<array<string, mixed>> Every body sent to the Messages address, in order. */
    protected array $sent = [];

    /** @var list<mixed>|null What the fake provider still has to answer with. */
    protected ?array $replies = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // The stand-in reader is the only one, so a test can take it away and leave no reader deployed.
        $this->withoutShippedReaders();

        config([
            'modules.document_reading.ceilings.cache_seconds' => 0,
            'modules.document_reading.typical.cache_seconds' => 0,
            // A reader exists, so a company can agree to the notice that grouping is sent under.
            'modules.reader_stand_ins.read_kinds' => [FakeKind::class],
        ]);

        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000', 'anthropic_api_key' => 'test-key-value']);
        $this->consent($this->org);
    }

    protected function consent(Organization $organization, bool $on = true): void
    {
        AiDocumentSettings::for($organization)->update(['enabled' => $on, 'consent_version' => $on ? AiDocumentConsent::VERSION : null]);
    }

    /**
     * Answers the Messages address with these replies in turn; the last one repeats.
     *
     * @param  list<mixed>  $replies  A body, or a ready response.
     */
    protected function fakeModel(array $replies): void
    {
        $first = $this->replies === null;
        $this->replies = $replies;

        if (! $first) {
            return;
        }

        Http::fake([
            'api.anthropic.com/v1/messages' => function (Request $request) {
                $this->sent[] = $request->data();
                $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];

                return is_array($reply) ? Http::response($reply, 200, ['request-id' => 'req_diary_'.count($this->sent)]) : $reply;
            },
        ]);
    }

    /** A complete answer of the model: the form as JSON text, with what it used. */
    protected function answer(array $form, int $input = 2400, int $output = 400): array
    {
        return [
            'id' => 'msg_diary_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($form, JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
        ];
    }

    /** What a worker does with the job of every call waiting on the queue. */
    protected function runCalls(): void
    {
        foreach (Queue::pushed(RunTextCall::class) as $job) {
            app(TextCallRunner::class)->run($job->callId);
        }
    }

    /** What a worker does with one call. */
    protected function runCall(AiTextCall $call): void
    {
        app(TextCallRunner::class)->run($call->id);
    }

    /** The whole text of the one user message of a sent body. */
    protected function messageOf(array $body): string
    {
        return implode("\n", array_column($body['messages'][0]['content'], 'text'));
    }

    protected function available(?Organization $organization = null): string
    {
        return app(CreditService::class)->wallet($organization ?? $this->org)->available();
    }

    /** @return list<AgentCreditLedger> What the company was charged for AI work. */
    protected function charges(?Organization $organization = null): array
    {
        return AgentCreditLedger::withoutGlobalScopes()->where('organization_id', ($organization ?? $this->org)->id)->where('type', 'usage')->get()->all();
    }
}
