<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\AiTextCall;
use App\Services\DocumentReading\Contracts\ChecksTextAnswer;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use App\Services\DocumentReading\Contracts\TriesPastedText;
use App\Services\DocumentReading\TextRequest;
use Closure;
use LogicException;

/**
 * A stand-in for a feature's text call that the platform admin can try on pasted notes. A company's
 * call would build its request from a record; this one has none, so only its twin for pasted text
 * is ever asked for anything.
 */
class FakePastedHandler implements ChecksTextAnswer, TextCallHandler, TriesPastedText
{
    public const INSTRUCTIONS = 'Group the notes into the hindrances they describe.';

    /** @var ?Closure(array<string, mixed>): array<string, list<string>> */
    public static ?Closure $refuse = null;

    /** @var list<array{0: int, 1: ?array}> The attempt and the correction of every request the twin built. */
    public static array $asked = [];

    public function __construct(private ?string $pasted = null) {}

    public static function reset(): void
    {
        self::$refuse = null;
        self::$asked = [];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'groups' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['title' => ['type' => 'string'], 'notes' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                    'required' => ['title', 'notes'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['groups'],
            'additionalProperties' => false,
        ];
    }

    public function onPasted(string $pasted): TextCallHandler
    {
        return new self($pasted);
    }

    public function request(AiTextCall $call): array
    {
        if ($this->pasted === null) {
            throw new LogicException('A company\'s call is never made in these tests.');
        }

        self::$asked[] = [(int) $call->attempts, $call->correction];

        $blocks = [TextRequest::data('notes', $this->pasted), 'Group the notes.'];

        if ($call->correction !== null) {
            $blocks[] = 'An earlier answer was not used: '.json_encode($call->correction).'.';
        }

        return TextRequest::body($call, self::INSTRUCTIONS, self::schema(), $blocks);
    }

    public function refused(AiTextCall $call, array $output): array
    {
        return self::$refuse === null ? [] : (self::$refuse)($output);
    }

    public function done(AiTextCall $call, array $output): void
    {
        throw new LogicException('A dry run keeps nothing in a company.');
    }

    public function failed(AiTextCall $call, string $reason): void
    {
        throw new LogicException('A dry run puts no fallback in a company.');
    }
}
