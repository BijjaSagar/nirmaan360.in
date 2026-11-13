<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Services\DocumentReading\Contracts\ChecksTextAnswer;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\TextRequest;
use Closure;
use RuntimeException;

/**
 * A stand-in for a feature's text call: it ranks two invented candidates for the record the call is
 * about. It records what the runner asked of it, so the tests can see the order of things without
 * any feature module being built. The record is a document read, because every test has that table.
 */
class FakeTextHandler implements ChecksTextAnswer, TextCallHandler
{
    public const INSTRUCTIONS = 'Rank the candidates for the line by how well they describe the same work.';

    /** @var list<array{0: ?int, 1: int}> Call id and attempt of every request that was built. */
    public static array $asked = [];

    /** @var list<array{0: int, 1: array<string, mixed>, 2: string}> Call id, the answer and the credits on the row when done() ran. */
    public static array $done = [];

    /** @var list<array{0: int, 1: string}> Call id and reason of every failed(). */
    public static array $failed = [];

    /** What the material says about the line. A test puts a phone number or a tag in here. */
    public static string $line = 'Providing and laying cement concrete in foundation';

    /** @var ?Closure(array<string, mixed>): array<string, list<string>> */
    public static ?Closure $refuse = null;

    public static bool $failToKeep = false;

    public static bool $failToFallBack = false;

    /** @var ?Closure(array<string, mixed>): array<string, mixed> Changes the body before it is returned, to build one that may not be sent. */
    public static ?Closure $spoil = null;

    public static function reset(): void
    {
        self::$asked = [];
        self::$done = [];
        self::$failed = [];
        self::$line = 'Providing and laying cement concrete in foundation';
        self::$refuse = null;
        self::$failToKeep = false;
        self::$failToFallBack = false;
        self::$spoil = null;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'picks' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'candidate' => ['type' => 'string'],
                        'same_work' => ['type' => 'string', 'enum' => ['yes', 'similar', 'no']],
                        'note' => ['type' => 'string'],
                    ],
                    'required' => ['candidate', 'same_work', 'note'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['picks'],
            'additionalProperties' => false,
        ];
    }

    public function request(AiTextCall $call): array
    {
        // Nobody is signed in on the queue, so the record is found by its company as well as its id.
        $record = DocumentRead::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);

        if ($record === null) {
            throw new NothingToSend;
        }

        self::$asked[] = [$call->id, (int) $call->attempts];

        $blocks = [
            TextRequest::data('line', ['ref' => 'L1', 'description' => self::$line, 'unit' => 'cum']),
            TextRequest::data('candidates', [
                ['ref' => 'C1', 'description' => 'PCC in foundation and plinth', 'unit' => 'cum', 'source' => 'rate_library'],
                ['ref' => 'C2', 'description' => 'Brick masonry in superstructure', 'unit' => 'cum', 'source' => 'own_boq'],
            ]),
            'Rank the candidates for line L1.',
        ];

        if ($call->correction !== null) {
            $blocks[] = 'An earlier answer was not used: '.json_encode($call->correction).'.';
        }

        $body = TextRequest::body($call, self::INSTRUCTIONS, self::schema(), $blocks);

        return self::$spoil === null ? $body : (self::$spoil)($body);
    }

    public function refused(AiTextCall $call, array $output): array
    {
        return self::$refuse === null ? [] : (self::$refuse)($output);
    }

    public function done(AiTextCall $call, array $output): void
    {
        if (self::$failToKeep) {
            throw new RuntimeException('Keeping the answer failed.');
        }

        self::$done[] = [$call->id, $output, (string) $call->credits];

        DocumentRead::withoutGlobalScopes()->where('organization_id', $call->organization_id)->whereKey($call->subject_id)
            ->update(['review' => json_encode(['kept' => count($output['picks'])])]);
    }

    public function failed(AiTextCall $call, string $reason): void
    {
        self::$failed[] = [$call->id, $reason];

        DocumentRead::withoutGlobalScopes()->where('organization_id', $call->organization_id)->whereKey($call->subject_id)
            ->update(['review' => json_encode(['fallback' => $reason])]);

        if (self::$failToFallBack) {
            throw new RuntimeException('The fallback failed.');
        }
    }
}
