<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\ReadDocumentPage;
use App\Models\AiDocumentSettings;
use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\PageReader;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadLifecycle;
use App\Services\DocumentReading\ReadTending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\FakeSlipKind;
use Tests\Feature\DocumentReading\Support\FakeTextKind;

/**
 * A company that may use the readers, with stand-in readers registered, a fake provider, a fake
 * disk and a fake queue. Page jobs are run by hand, so each test sees one step at a time.
 *
 * An A4 page sent to the first BOQ pass is 4,756 picture tokens plus the assumed 1,200 of prompt.
 */
abstract class PageReadingTestCase extends ReadingTestCase
{
    use BuildsPictures;

    protected const A4_INPUT = 5956;

    protected Organization $organization;

    protected User $owner;

    /** @var list<array<string, mixed>> Every body sent to the Messages address, in order. */
    protected array $sent = [];

    /** @var list<array<string, mixed>> The options of each of those calls. */
    protected array $sentOptions = [];

    /** @var list<array<string, list<string>>> The headers of each of those calls. */
    protected array $sentHeaders = [];

    protected int $counted = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Queue::fake();
        FakeKind::reset();

        $this->withoutShippedReaders();
        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class, FakeTextKind::class, FakeSlipKind::class]]);

        $this->release();
        $this->connect();

        $this->organization = $this->tenant('Sahyadri Builders');
        $this->owner = $this->member($this->organization, 'owner');
        $this->consent($this->organization);
    }

    protected function consent(Organization $organization, bool $on = true, ?string $version = AiDocumentConsent::VERSION): void
    {
        AiDocumentSettings::for($organization)->update(['enabled' => $on, 'consent_version' => $version]);
    }

    /** A read that was started properly: running, with credits on hold, under the current consent. */
    protected function startedRead(?User $by = null, string $held = '100', array $overrides = []): DocumentRead
    {
        $by ??= $this->owner;

        return $this->runningRead(Organization::findOrFail($by->organization_id), $by, $held, [
            'consent_version' => AiDocumentConsent::VERSION,
            'pages_total' => 3,
            ...$overrides,
        ]);
    }

    /** A page picture on the disk with its row, queued unless told otherwise. */
    protected function storedPage(DocumentRead $read, int $number = 1, array $overrides = []): DocumentReadPage
    {
        $pass = $overrides['pass'] ?? 'rows';
        $part = $overrides['part'] ?? 'full';
        $bytes = $this->jpeg(40, 56).pack('N', $read->id * 1000 + $number);
        $path = ReadFiles::page($read, $number, $pass, $part);

        Storage::put($path, $bytes);

        return $this->makePage($read, $number, [
            'status' => DocumentReadPage::QUEUED,
            'image_path' => $path,
            'image_sha256' => hash('sha256', $bytes),
            'image_bytes' => strlen($bytes),
            ...$overrides,
        ]);
    }

    /** @return string The base64 text of a stored page picture, as it travels to the provider. */
    protected function base64Of(DocumentReadPage $page): string
    {
        return base64_encode((string) Storage::get($page->image_path));
    }

    /**
     * Answers the Messages address with these replies in turn; the last one repeats. A reply is a
     * body, a ready response, or a function of the request body that returns either. The token
     * counting address answers with a fixed count.
     *
     * @param  list<mixed>  $replies
     */
    protected function fakeReader(array $replies, int $promptTokens = 900): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages/count_tokens' => function () use ($promptTokens) {
                $this->counted++;

                return Http::response(['input_tokens' => $promptTokens]);
            },
            'api.anthropic.com/v1/messages' => function (Request $request, array $options) use (&$replies) {
                $this->sent[] = $request->data();
                $this->sentOptions[] = $options;
                $this->sentHeaders[] = $request->headers();

                $reply = count($replies) > 1 ? array_shift($replies) : $replies[0];
                $reply = is_callable($reply) ? $reply($request->data()) : $reply;

                return is_array($reply) ? Http::response($reply, 200, ['request-id' => 'req_test_'.count($this->sent)]) : $reply;
            },
        ]);
    }

    /** A complete answer in the stand-in's form. */
    protected function answer(string $pageKind = 'other', int $input = self::A4_INPUT, int $output = 4500, string $stop = 'end_turn', array $extra = []): array
    {
        $form = ['page_kind' => $pageKind, 'rows' => [['y_top' => 120, 'qty' => ['raw' => '12.500', 'state' => 'read'], 'rate' => ['raw' => '4,250.00', 'state' => 'Read']]]];

        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($form)]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
            ...$extra,
        ];
    }

    protected function failure(int $status, array $error = ['type' => 'api_error', 'message' => 'Something went wrong.'], array $headers = [])
    {
        return Http::response(['type' => 'error', 'error' => $error, 'request_id' => 'req_failed'], $status, $headers);
    }

    protected function readPage(DocumentReadPage|int $page): void
    {
        app(PageReader::class)->run($page instanceof DocumentReadPage ? $page->id : $page);
    }

    protected function lifecycle(): ReadLifecycle
    {
        return app(ReadLifecycle::class);
    }

    protected function tending(): ReadTending
    {
        return app(ReadTending::class);
    }

    /** Puts the real queue back, on the database, for a test that looks at what a job carries. */
    protected function useTheQueueTable(): void
    {
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['queue.default' => 'database']);
    }

    /** @return list<int> The page ids of the page jobs handed to the queue, in order. */
    protected function queuedPages(): array
    {
        return Queue::pushed(ReadDocumentPage::class)->map(fn (ReadDocumentPage $job) => $job->pageId)->all();
    }

    /** @return list<AuditLog> */
    protected function audits(string $action): array
    {
        return AuditLog::withoutGlobalScopes()->where('action', $action)->orderBy('id')->get()->all();
    }
}
