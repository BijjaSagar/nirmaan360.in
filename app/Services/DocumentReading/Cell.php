<?php

namespace App\Services\DocumentReading;

/**
 * One printed value as the model returns it: the text as printed, and the model's own remark on it.
 *
 * The remark is not a measure of confidence. It can add review work and never removes any: a value
 * marked "read" is still parsed by code and still confirmed by a person.
 */
final class Cell
{
    public const READ = 'read';

    public const UNCLEAR = 'unclear';

    public const ABSENT = 'absent';

    public const STATES = [self::READ, self::UNCLEAR, self::ABSENT];

    /** The schema of a value cell, the same in every reader. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'raw' => ['type' => 'string'],
            'state' => ['type' => 'string', 'enum' => self::STATES],
        ],
        'required' => ['raw', 'state'],
        'additionalProperties' => false,
    ];

    /** The printed text, trimmed. */
    public static function raw(array $cell): string
    {
        return trim((string) ($cell['raw'] ?? ''));
    }

    /**
     * The remark, made to agree with the text in the direction that adds review work: text marked
     * "absent" is unclear, not dropped, and an empty cell marked "read" is absent.
     */
    public static function state(array $cell): string
    {
        $state = strtolower((string) ($cell['state'] ?? ''));
        $empty = self::raw($cell) === '';

        return match (true) {
            ! in_array($state, self::STATES, true) => self::UNCLEAR,
            $empty && $state === self::READ => self::ABSENT,
            ! $empty && $state === self::ABSENT => self::UNCLEAR,
            default => $state,
        };
    }
}
