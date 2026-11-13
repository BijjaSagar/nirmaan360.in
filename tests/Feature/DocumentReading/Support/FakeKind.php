<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\ReaderRequest;
use RuntimeException;

/**
 * A stand-in for a feature's reader, registered under the "boq" figures: two passes over a page
 * picture, the second only after the first found a table. It records what the page reader asked of
 * it, so the tests can see the order of things without any feature module being built.
 */
class FakeKind implements ReadKind
{
    public const INSTRUCTIONS = 'Copy the table on this page row by row into the form.';

    /** @var list<array{0:int, 1:string}> Page id and pass of every page handed to pageRead. */
    public static array $staged = [];

    /** @var list<int> */
    public static array $finished = [];

    /** @var list<int> */
    public static array $purged = [];

    public static string $cutOff = 'offer_halves';

    /** @var array{all: list<string>, any: list<list<string>>} */
    public static array $abilities = ['all' => ['boq.view'], 'any' => []];

    public static bool $failToStage = false;

    public static int $failuresToFinish = 0;

    public static function reset(): void
    {
        self::$staged = [];
        self::$finished = [];
        self::$purged = [];
        self::$cutOff = 'offer_halves';
        self::$abilities = ['all' => ['boq.view'], 'any' => []];
        self::$failToStage = false;
        self::$failuresToFinish = 0;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_kind' => ['type' => 'string', 'enum' => ['table', 'other', 'blank', 'unusable']],
                'rows' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['y_top' => ['type' => 'integer'], 'qty' => Cell::SCHEMA, 'rate' => Cell::SCHEMA],
                    'required' => ['y_top', 'qty', 'rate'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['page_kind', 'rows'],
            'additionalProperties' => false,
        ];
    }

    public function key(): string
    {
        return 'boq';
    }

    public function passes(): array
    {
        return ['rows', 'amounts'];
    }

    public function limits(): array
    {
        return ['max_pages' => 60, 'min_short_side' => 600];
    }

    public function request(DocumentReadPage $page, ?string $imageBase64, ?string $text): array
    {
        return $imageBase64 !== null
            ? ReaderRequest::image($this->key(), $page, self::INSTRUCTIONS, self::schema(), $imageBase64, $page->documentRead?->options['language'] ?? null)
            : ReaderRequest::text($this->key(), $page, self::INSTRUCTIONS, self::schema(), (string) $text);
    }

    public function accept(array $output, DocumentReadPage $page): ?array
    {
        return $output['page_kind'] === 'unusable' ? null : $output;
    }

    public function chargeable(DocumentReadPage $page): bool
    {
        return ($page->result['page_kind'] ?? null) !== 'blank';
    }

    public function pageRead(DocumentReadPage $page): void
    {
        if (self::$failToStage) {
            throw new RuntimeException('The staging step failed.');
        }

        self::$staged[] = [$page->id, $page->pass];
    }

    public function nextPasses(DocumentReadPage $page): array
    {
        return $page->pass === 'rows' && ($page->result['page_kind'] ?? null) === 'table' ? ['amounts'] : [];
    }

    public function onCutOff(DocumentReadPage $page): string
    {
        return self::$cutOff;
    }

    public function finished(DocumentRead $read): void
    {
        if (self::$failuresToFinish > 0) {
            self::$failuresToFinish--;

            throw new RuntimeException('The finishing step failed.');
        }

        self::$finished[] = $read->id;
    }

    public function abilities(DocumentRead $read): array
    {
        return self::$abilities;
    }

    public function purge(DocumentRead $read): void
    {
        self::$purged[] = $read->id;
    }
}
