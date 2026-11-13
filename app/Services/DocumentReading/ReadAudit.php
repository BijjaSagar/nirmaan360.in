<?php

namespace App\Services\DocumentReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The audit rows of a read and of a text call that are written when a person or a job acts:
 *
 *   ai.read_started    a person started a read (the start route calls started())
 *   ai.read_confirmed  a person confirmed what was read (each feature's confirm step calls confirmed())
 *   ai.text_sent       a text call went to the provider (the text call job calls textSent())
 *
 * The rows of the pages themselves, ai.page_sent and ai.read_finished, are written by PageReader
 * and ReadLifecycle where those things happen.
 *
 * An audit row says what was done, never what a document said. So every value written here is a
 * count, an amount of credits, a code or an id that the code made. No file name, no printed value,
 * no transcript, no person's name, no GSTIN and no phone number can get in: a caller cannot pass
 * free text, and a value that does not look like a code is refused.
 */
final class ReadAudit
{
    /** What confirmed() counts. A feature leaves out what it does not have. */
    public const CONFIRM_COUNTS = ['rows_one_by_one', 'rows_by_page', 'cells_typed', 'overrides', 'acknowledgements'];

    /**
     * A person started a read. Call it in the transaction that puts the credits on hold.
     *
     * @param  int  $pages  How many pages this start asked to be read.
     */
    public static function started(DocumentRead $read, User $by, int $pages): AuditLog
    {
        return AuditLog::record('ai.read_started', $read, null, [
            'kind' => self::code($read->kind),
            'pages' => max(0, $pages),
            'cap' => (string) $read->credits_cap,
            'consent_version' => self::code($read->consent_version),
            'channel' => self::code($read->channel),
        ], $by, (int) $read->organization_id);
    }

    /**
     * A person confirmed what a read returned. The counts say how carefully: rows confirmed one by
     * one, rows confirmed a page at a time, cells typed by hand, checks overridden, notes acknowledged.
     *
     * @param  array<string, int>  $counts  Keys from CONFIRM_COUNTS only.
     */
    public static function confirmed(DocumentRead $read, User $by, array $counts): AuditLog
    {
        $unknown = array_diff(array_keys($counts), self::CONFIRM_COUNTS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('An audit row of a confirmed read holds counts only, not: '.implode(', ', $unknown).'.');
        }

        $values = ['kind' => self::code($read->kind)];

        foreach (self::CONFIRM_COUNTS as $key) {
            if (! array_key_exists($key, $counts)) {
                continue;
            }

            if (! is_int($counts[$key]) || $counts[$key] < 0) {
                throw new InvalidArgumentException("The count \"{$key}\" of a confirmed read is a whole number.");
            }

            $values[$key] = $counts[$key];
        }

        return AuditLog::record('ai.read_confirmed', $read, null, $values, $by, (int) $read->organization_id);
    }

    /**
     * A text call was sent to the provider. One row per attempt that left the server.
     *
     * @param  Model  $call  The ai_text_calls row: purpose, model, input_chars and provider_request_id are read from it.
     * @param  string  $outcome  A code such as done, failed or rejected.
     * @param  ?User  $by  The person the call was made for. On the queue nobody is signed in, so pass them.
     */
    public static function textSent(Model $call, string $outcome, ?User $by = null): AuditLog
    {
        return AuditLog::record('ai.text_sent', $call, null, [
            'purpose' => self::code($call->getAttribute('purpose')),
            'model' => self::code($call->getAttribute('model')),
            'input_chars' => (int) $call->getAttribute('input_chars'),
            'outcome' => self::code($outcome),
            'provider_request_id' => self::providerId($call->getAttribute('provider_request_id')),
        ], $by, (int) $call->getAttribute('organization_id'));
    }

    /**
     * A value the code made: a kind, a model id, a version, a request id. Letters, digits and the
     * few marks such ids use, at most 100 of them. Anything else is not written.
     */
    public static function code(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,99}$/', $value) !== 1) {
            throw new InvalidArgumentException('An audit row of a read holds codes and counts, never text.');
        }

        return $value;
    }

    /** An id the provider chose. One that does not look like an id is left out instead of stopping the work. */
    private static function providerId(mixed $value): ?string
    {
        try {
            return self::code($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
