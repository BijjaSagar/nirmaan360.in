<?php

namespace App\Services\AutoBoq;

use App\Support\Dec;
use Brick\Math\BigDecimal;

/** One line of the preview: what the BOQ line would be, with the working that led to its quantity. */
final class AutoBoqLine
{
    public readonly string $qty;

    /**
     * @param  list<string>  $working  One row per group or part, such as "F1: 4 × 1.5 × 1.5 × 0.45 = 4.050".
     * @param  bool  $computed  False for a finishing or MEP line that needs its own drawing and comes out at zero.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $description,
        public readonly string $unit,
        BigDecimal|string $qty,
        public readonly string $category,
        public readonly string $group,
        public readonly array $working = [],
        public readonly ?string $note = null,
        public readonly bool $computed = true,
    ) {
        $this->qty = Dec::qty($qty);
    }

    /** The working as it is stored in the BOQ line's details. */
    public function details(): string
    {
        $lines = $this->working;

        if ($this->note !== null) {
            $lines[] = $this->note;
        }

        return implode("\n", $lines);
    }

    public function exact(): BigDecimal
    {
        return Dec::of($this->qty);
    }
}
