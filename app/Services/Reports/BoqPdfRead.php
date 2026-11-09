<?php

namespace App\Services\Reports;

/** What came out of a PDF: the table as a grid, or the reason there is none. */
final class BoqPdfRead
{
    /**
     * @param  array<int, array<int, ?string>>  $grid  Row => column => text, with the heading in row 1.
     */
    public function __construct(
        public readonly array $grid = [],
        public readonly int $pages = 0,
        public readonly ?string $error = null,
        public readonly bool $scanned = false,
    ) {}

    public static function failed(string $error, bool $scanned = false): self
    {
        return new self([], 0, $error, $scanned);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
