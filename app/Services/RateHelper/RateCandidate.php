<?php

namespace App\Services\RateHelper;

/** A row a source offers for a BOQ line: where it is, and how alike the two descriptions are. Never a rate. */
final readonly class RateCandidate
{
    /** @param  int|null  $score  0 to 100. Null for a figure that is not matched by description, such as the cost of a line's own materials. */
    public function __construct(
        public string $source,
        public int $rowId,
        public ?int $score = null,
    ) {}
}
