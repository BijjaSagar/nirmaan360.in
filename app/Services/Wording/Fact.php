<?php

namespace App\Services\Wording;

use App\Support\Dec;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One figure, date, name or reference that a document states, with the record it came from.
 *
 * The value is kept in one fixed form per kind, so the same record always gives the same fact
 * on every database: money to two places, quantity to three, a percentage without trailing
 * zeros, a whole number for a count or a number of days, and a date as year-month-day.
 */
final readonly class Fact
{
    public const MONEY = 'money';

    public const QTY = 'qty';

    public const PERCENT = 'percent';

    public const COUNT = 'count';

    public const DAYS = 'days';

    public const DATE = 'date';

    public const REFERENCE = 'reference';

    public const TEXT = 'text';

    public const KINDS = [self::MONEY, self::QTY, self::PERCENT, self::COUNT, self::DAYS, self::DATE, self::REFERENCE, self::TEXT];

    /** The form a key takes inside a template: {{key}}. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public string $value;

    /**
     * @param  string  $key  Lower-case letters, digits and underscores, for example bill_2_outstanding.
     * @param  mixed  $value  A decimal string or whole number for a figure (never a float), a date, or text.
     * @param  string  $label  What the figure is, shown beside it on the review screen. Never the figure itself.
     */
    public function __construct(public string $key, public string $kind, mixed $value, public string $label, public FactSource $source)
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("'{$key}' cannot be used as a fact key.");
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("'{$kind}' is not a kind of fact.");
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException("Fact '{$key}' needs a label.");
        }

        $this->value = self::canonical($key, $kind, $value);
    }

    /** How the fact is printed in a document. */
    public function display(string $locale): string
    {
        return FactFormat::display($this->kind, $this->value, $locale);
    }

    /** @return array{key: string, kind: string, value: string, label: string, source: array{type: string, id: ?string, field: ?string}} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'kind' => $this->kind, 'value' => $this->value, 'label' => $this->label, 'source' => $this->source->toArray()];
    }

    /** @param  array<string, mixed>  $fact */
    public static function fromArray(array $fact): self
    {
        return new self(
            (string) ($fact['key'] ?? ''),
            (string) ($fact['kind'] ?? ''),
            $fact['value'] ?? null,
            (string) ($fact['label'] ?? ''),
            FactSource::fromArray((array) ($fact['source'] ?? [])),
        );
    }

    private static function canonical(string $key, string $kind, mixed $value): string
    {
        // An empty value would be read as zero by Dec. A figure that is missing must never be printed as 0.
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new InvalidArgumentException("Fact '{$key}' has no value.");
        }

        return match ($kind) {
            self::MONEY => Dec::money(self::decimal($key, $value)),
            self::QTY => Dec::qty(self::decimal($key, $value)),
            self::PERCENT => self::percent($key, $value),
            self::COUNT, self::DAYS => self::whole($key, $value),
            self::DATE => self::date($key, $value),
            default => self::text($key, $value),
        };
    }

    private static function decimal(string $key, mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException("Fact '{$key}' was given a float. Pass the decimal string from the record.");
        }

        if (! is_int($value) && ! is_string($value) && ! $value instanceof BigNumber) {
            throw new InvalidArgumentException("Fact '{$key}' is not a number.");
        }

        // Only plain decimals: no exponent, no fraction, no sign other than a leading minus.
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Fact '{$key}' is not a plain decimal number.");
        }

        try {
            return (string) Dec::of($value)->toBigDecimal();
        } catch (MathException) {
            throw new InvalidArgumentException("Fact '{$key}' is not a plain decimal number.");
        }
    }

    private static function percent(string $key, mixed $value): string
    {
        $number = Dec::of(self::decimal($key, $value));

        // 18.00 and 18.0000 are the same percentage, whichever column they came from.
        return $number->isZero() ? '0' : (string) $number->strippedOfTrailingZeros();
    }

    private static function whole(string $key, mixed $value): string
    {
        $number = Dec::of(self::decimal($key, $value));

        if ($number->isNegative() || ! $number->isEqualTo($number->toScale(0, RoundingMode::Down))) {
            throw new InvalidArgumentException("Fact '{$key}' must be a whole number that is not below zero.");
        }

        return (string) $number->toScale(0, RoundingMode::Down);
    }

    private static function date(string $key, mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        throw new InvalidArgumentException("Fact '{$key}' is not a date written as year-month-day.");
    }

    private static function text(string $key, mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("Fact '{$key}' is not text.");
        }

        $text = trim((string) $value);

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException("Fact '{$key}' is not valid text.");
        }

        return $text;
    }
}
