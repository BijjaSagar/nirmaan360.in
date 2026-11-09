<?php

namespace App\Services\PriceWatch;

/** One price a source knows: what, per which unit, how much, as on which day, and where it came from. */
final readonly class Quote
{
    public function __construct(
        public string $key,
        public string $name,
        public string $unit,
        public string $rate,
        public string $pricedOn,
        public ?string $source = null,
    ) {}
}
