<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AiDocumentSettings;
use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\TextCallRunner;
use App\Services\DocumentReading\TextCalls;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;

/**
 * A company that may make text calls, with a stand-in handler registered, a fake provider and a
 * fake queue. Call jobs are run by hand, so each test sees one step at a time.
 *
 * The record a call is about is a document read: every test database has that table, it belongs to
 * a company and can belong to a project, and its "review" column is where the stand-in keeps what
 * it was given.
 */
abstract class TextCallTestCase extends ReadingTestCase
{
    /** The purpose the stand-in is registered for when a test does not say otherwise. Not under the consent switch. */
    protected const PURPOSE = 'rate_match';

    protected Organization $organization;

    protected User $owner;

    protected User $engineer;

    /** @var list<array<string, mixed>> Every body sent to the Messages address, in order. */
    protected array $sent = [];

    /** @var list<array<string, list<string>>> The headers of each of those calls. */
    protected array $sentHeaders = [];

    /** @var list<mixed>|null What the fake provider still has to answer with. */
    protected ?array $replies = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeTextHandler::reset();

        config(['modules.text_stand_ins.text_calls' => $this->handlers()]);

        $this->release();
        $this->connect();

        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
        $this->engineer = $this->member($this->organization, 'engineer');
    }

    /** @return array<string, mixed> What the stand-in module lists under "text_calls". */
    protected function handlers(): array
    {
        return ['rate_match' => FakeTextHandler::class, 'voice' => FakeTextHandler::class];
    }

    protected function consent(Organization $organization, bool $on = true, ?string $version = AiDocumentConsent::VERSION): void
    {
        AiDocumentSettings::for($organization)->update(['enabled' => $on, 'consent_version' => $version]);
    }

    /** The record a call is about. */
    protected function record(?Organization $organization = null, array $overrides = []): DocumentRead
    {
        return $this->makeRead($organization ?? $this->organization, null, ['status' => DocumentRead::READY, ...$overrides]);
    }

    protected function calls(): TextCalls
    {
        return app(TextCalls::class);
    }

    protected function start(?DocumentRead $record = null, ?User $by = null, string $purpose = self::PURPOSE, array $with = []): AiTextCall
    {
        return $this->calls()->start($purpose, $record ?? $this->record(), $by ?? $this->engineer, $with);
    }

    /** What a worker does with the job of a call. A new runner each time, as each job gets. */
    protected function runCall(AiTextCall|int $call): void
    {
        app(TextCallRunner::class)->run($call instanceof AiTextCall ? $call->id : $call);
    }

    /**
     * Answers the Messages address with these replies in turn; the last one repeats. A reply is a
     * body, a ready response, or a function of the request body that returns either. Calling it
     * again replaces the replies that are left.
     *
     * @param  list<mixed>  $replies
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
                $this->sentHeaders[] = $request->headers();

                $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];
                $reply = is_callable($reply) ? $reply($request->data()) : $reply;

                return is_array($reply) ? Http::response($reply, 200, ['request-id' => 'req_text_'.count($this->sent)]) : $reply;
            },
        ]);
    }

    /**
     * A complete answer of the model: the form as JSON text, with what it used.
     *
     * @param  array<string, mixed>|string|null  $form  Null gives the stand-in's own usual answer.
     */
    protected function answer(array|string|null $form = null, int $input = 1400, int $output = 300, string $stop = 'end_turn', array $extra = []): array
    {
        $form ??= ['picks' => [['candidate' => 'C1', 'same_work' => 'Yes', 'note' => 'Same work, same unit.']]];

        return [
            'id' => 'msg_text_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => is_string($form) ? $form : json_encode($form, JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
            ...$extra,
        ];
    }

    protected function failure(int $status, array $error = ['type' => 'api_error', 'message' => 'Something went wrong.'], array $headers = [])
    {
        return Http::response(['type' => 'error', 'error' => $error, 'request_id' => 'req_failed'], $status, $headers);
    }

    /** Puts the real queue back, on the database, for a test that looks at what a job carries. */
    protected function useTheQueueTable(): void
    {
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'database']);
    }

    /** Puts the real queue back and has it run each job at once, as soon as the work that queued it is saved. */
    protected function runJobsAtOnce(): void
    {
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'sync']);
    }

    /** @return list<int> The call ids of the call jobs handed to the queue, in order. */
    protected function queuedCalls(): array
    {
        return Queue::pushed(RunTextCall::class)->map(fn (RunTextCall $job) => $job->callId)->all();
    }

    /** @return list<AuditLog> */
    protected function audits(string $action = 'ai.text_sent'): array
    {
        return AuditLog::withoutGlobalScopes()->where('action', $action)->orderBy('id')->get()->all();
    }

    /** @return list<string> The outcome of every audit row that says a text call was sent. */
    protected function sentOutcomes(): array
    {
        return array_map(fn (AuditLog $row) => $row->new_values['outcome'], $this->audits());
    }

    protected function assertNotCharged(?Organization $organization = null): void
    {
        $organization ??= $this->organization;

        $this->assertSame([], $this->usageLines($organization));
        $this->assertSame('500.0000', $this->wallet($organization)->available());
    }

    /** The whole text of the one user message of a sent body. */
    protected function messageOf(array $body): string
    {
        return implode("\n", array_column($body['messages'][0]['content'], 'text'));
    }
}
