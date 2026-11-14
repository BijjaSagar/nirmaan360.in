<?php

namespace App\Services\Imports;

/**
 * Where a sheet's headings are and what each of its columns holds, as BoqHeadings found it or as
 * the person set it on the preview. Columns are numbered from 1, as in the grid.
 */
final class SheetLayout
{
    /**
     * What a column can hold. "reference" is a schedule or DSR reference kept in the line's details; "per" says
     * what the rate is for when it is not one unit, as "100 Sqm" or "Qtl".
     */
    public const FIELDS = ['serial', 'code', 'description', 'unit', 'qty', 'rate', 'amount', 'remark', 'section', 'category', 'details', 'reference', 'per'];

    /**
     * @param  ?int  $row  The first heading row, as numbered on the sheet. Null when no heading was found.
     * @param  int  $span  How many rows the heading takes: 1, 2 or 3.
     * @param  array<int, ?string>  $fields  Column => what it holds, null for a column that is left out.
     * @param  array<int, string>  $headings  Column => its heading as written, the rows of a long heading joined.
     * @param  array<int, string>  $how  Column => how its meaning was decided: heading, values, saved or chosen.
     * @param  array<int, string>  $units  Column => the unit its heading names, such as cum in "Quantity (Cum)".
     */
    public function __construct(
        public readonly ?int $row,
        public readonly int $span = 1,
        public readonly array $fields = [],
        public readonly array $headings = [],
        public readonly array $how = [],
        public readonly array $units = [],
        public readonly float $score = 0.0,
    ) {}

    public static function none(): self
    {
        return new self(null);
    }

    /** @return array<string, int> Field => column, for the columns that are not left out. */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->fields as $column => $field) {
            if ($field !== null) {
                $columns[$field] ??= $column;
            }
        }

        return $columns;
    }

    /**
     * Every column read as this field, left to right. Only the description can have two: "Description" merged
     * over two columns, the item in one and its parts in the next.
     *
     * @return list<int>
     */
    public function columnsOf(string $field): array
    {
        $columns = array_keys(array_filter($this->fields, fn (?string $value) => $value === $field));
        sort($columns);

        return $columns;
    }

    /** A heading row with a description column and a quantity or a rate column: enough to read lines from. */
    public function found(): bool
    {
        $columns = $this->columns();

        return $this->row !== null && isset($columns['description']) && (isset($columns['qty']) || isset($columns['rate']));
    }

    /** The last row of the heading. Lines start below it. */
    public function lastRow(): int
    {
        return ($this->row ?? 0) + max(1, $this->span) - 1;
    }

    /** The unit the quantity heading names, else the rate heading, as in "Quantity (Cum)" or "Rate per Sqm". */
    public function headingUnit(): ?string
    {
        $columns = $this->columns();

        foreach (['qty', 'rate', 'amount'] as $field) {
            if (isset($columns[$field], $this->units[$columns[$field]])) {
                return $this->units[$columns[$field]];
            }
        }

        return null;
    }

    /** The template and our own export: every column the plain import needs, and a section column. */
    public function isTemplate(): bool
    {
        return array_diff(['section', 'code', 'description', 'unit', 'qty', 'rate'], array_keys($this->columns())) === [];
    }

    /**
     * What the headings say, column by column, the same for every file laid out the same way. A saved
     * layout is found again by this.
     */
    public function signature(): string
    {
        $parts = [];

        foreach ($this->headings as $column => $heading) {
            $key = BoqHeadings::normalise($heading);

            if ($key !== '') {
                $parts[] = $column.':'.$key;
            }
        }

        return sha1($this->span.'|'.implode('|', $parts));
    }

    /**
     * The same heading with these meanings for its columns. A column not named keeps its meaning.
     *
     * @param  array<int, ?string>  $fields  Column => field, or null to leave the column out.
     */
    public function withFields(array $fields, string $how = 'chosen'): self
    {
        $current = $this->fields;
        $marks = $this->how;

        foreach ($fields as $column => $field) {
            $field = in_array($field, self::FIELDS, true) ? $field : null;

            if (($current[$column] ?? null) === $field) {
                // Leaving a column out that was already left out is still the person's choice.
                if ($field === null && array_key_exists($column, $current)) {
                    $marks[$column] = $how;
                }

                continue;
            }

            // A field belongs to one column. Giving it to this one takes it from any other.
            if ($field !== null) {
                foreach ($current as $other => $existing) {
                    if ($existing === $field && $other !== $column) {
                        $current[$other] = null;
                        $marks[$other] = $how;
                    }
                }
            }

            $current[$column] = $field;
            $marks[$column] = $how;
        }

        ksort($current);

        return new self($this->row, $this->span, $current, $this->headings, $marks, $this->units, $this->score);
    }

    public function toArray(): array
    {
        return [
            'row' => $this->row, 'span' => $this->span, 'fields' => $this->fields, 'headings' => $this->headings,
            'how' => $this->how, 'units' => $this->units, 'score' => $this->score,
        ];
    }

    public static function fromArray(array $data): self
    {
        $int = fn (array $map) => array_combine(array_map('intval', array_keys($map)), array_values($map)) ?: [];

        return new self(
            isset($data['row']) ? (int) $data['row'] : null,
            (int) ($data['span'] ?? 1),
            $int((array) ($data['fields'] ?? [])),
            $int((array) ($data['headings'] ?? [])),
            $int((array) ($data['how'] ?? [])),
            $int((array) ($data['units'] ?? [])),
            (float) ($data['score'] ?? 0),
        );
    }
}
