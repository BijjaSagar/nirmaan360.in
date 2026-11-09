<?php

namespace App\Services\Reports;

use App\Support\Dec;
use Carbon\Carbon;

/**
 * One finished report: a titled table that the screen, the Excel export, the PDF and the
 * scheduled email all render. Money and quantities are kept as exact decimal strings.
 *
 * A column is ['key', 'label', 'type'] where type is text, date, int, qty, money or dec1.
 */
final class ReportResult
{
    /**
     * @param  list<array{key:string,label:string,type:string}>  $columns
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>|null  $totals  Column key => total, for the columns that add up
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly array $notes = [],
        public readonly bool $truncated = false,
    ) {}

    /** A cell as text for the screen and the PDF. */
    public static function display(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => Dec::indian($value, 2),
            'qty' => Dec::indian($value, 3),
            'dec1' => Dec::indian($value, 1),
            'int' => (string) (int) $value,
            'date' => Carbon::parse($value)->format('d M Y'),
            default => (string) $value,
        };
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** A copy limited to the first rows, for the screen preview and the PDF. */
    public function limited(int $max): self
    {
        if (count($this->rows) <= $max) {
            return $this;
        }

        return new self($this->key, $this->title, $this->subtitle, $this->columns, array_slice($this->rows, 0, $max), $this->totals, $this->notes, true);
    }
}
