<?php

namespace App\Services\TenderReading;

/**
 * The things the tender reader looks for, from config/modules/tender_reading.php. Labels only: the
 * app holds no value of any tender's deposit, period or percentage.
 */
final class TenderFields
{
    public const TEXT = 'text';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public const AMOUNT = 'amount';

    public const PERCENT = 'percent';

    public const PERIOD = 'period';

    public const URL = 'url';

    public const KINDS = [self::TEXT, self::DATE, self::DATETIME, self::AMOUNT, self::PERCENT, self::PERIOD, self::URL];

    /** @return list<string> Every field key, in the order of the config. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, array{kind: string, group: string, label: string, repeatable?: bool}> */
    public static function all(): array
    {
        return (array) config('modules.tender_reading.fields', []);
    }

    public static function exists(?string $field): bool
    {
        return is_string($field) && isset(self::all()[$field]);
    }

    public static function kind(string $field): string
    {
        $kind = self::all()[$field]['kind'] ?? self::TEXT;

        return in_array($kind, self::KINDS, true) ? $kind : self::TEXT;
    }

    /** The English label of a field, to pass through __() where it is shown. */
    public static function label(string $field): string
    {
        return (string) (self::all()[$field]['label'] ?? $field);
    }

    public static function group(string $field): string
    {
        return (string) (self::all()[$field]['group'] ?? 'conditions');
    }

    /** A field that may appear many times with different labels: one per deduction, document or clause. */
    public static function repeatable(string $field): bool
    {
        return (bool) (self::all()[$field]['repeatable'] ?? false);
    }

    /** @return array<string, string> Group key => English heading, in sheet order. */
    public static function groups(): array
    {
        return (array) config('modules.tender_reading.groups', []);
    }

    public static function setting(string $key, int $default): int
    {
        return max(0, (int) config("modules.tender_reading.{$key}", $default));
    }
}
