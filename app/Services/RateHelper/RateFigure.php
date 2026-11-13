<?php

namespace App\Services\RateHelper;

use App\Support\Dec;
use Carbon\CarbonInterface;

/**
 * A rate as it stands in the company's records at this moment, with what a person needs to judge it:
 * what it is for, per which unit, how old it is and where it sits.
 */
final readonly class RateFigure
{
    /**
     * @param  string  $rate  Money, two decimals.
     * @param  string  $dateLabel  What the date is, in English: passed through __() where shown.
     * @param  string  $basis  How the figure came to be, in English: passed through __() where shown.
     * @param  string|null  $origin  The project or tender the row sits in. Shown on the screen only; never stored or sent anywhere.
     * @param  bool  $acceptable  False for a figure shown for reference only.
     * @param  bool  $incomplete  True when part of the figure could not be found.
     * @param  list<array{label:string,amount:?string,note:?string}>  $parts  What the figure is made of, when the source knows.
     */
    public function __construct(
        public string $source,
        public int $rowId,
        public string $description,
        public string $unit,
        public string $rate,
        public ?CarbonInterface $date,
        public string $dateLabel,
        public string $basis,
        public ?string $origin = null,
        public bool $acceptable = true,
        public bool $incomplete = false,
        public array $parts = [],
    ) {}

    /**
     * What the person is shown of this figure, as one value the accept form sends back: the row, its
     * rate, its unit and what it is for. Accepting compares it with the row as it is then, so a rate
     * that changed after the page was opened is never written in the person's name. It is only
     * compared, never read back as a rate.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->source, $this->rowId, Dec::money($this->rate), $this->unit, $this->description], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /** Older than the number of days after which a rate is marked old. A figure with no date is not judged. */
    public function isOld(int $staleAfterDays, ?CarbonInterface $today = null): bool
    {
        return $this->date !== null && $this->date->lt(($today ?? now())->copy()->startOfDay()->subDays($staleAfterDays));
    }
}
