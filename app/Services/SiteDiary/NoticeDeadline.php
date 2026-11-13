<?php

namespace App\Services\SiteDiary;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The date one notice falls on for one hindrance, worked out from the notice periods a person
 * entered and confirmed under Contract terms. It repeats what was entered and adds calendar days;
 * it is not a reading of the agreement, and every screen that shows it says where it came from.
 */
final readonly class NoticeDeadline
{
    /** Every period gave a date, so $date is the date the periods give. */
    public const KNOWN = 'known';

    /** Two periods go together as "whichever comes first" and one cannot be worked out yet: the date is not later than $date. */
    public const NOT_LATER_THAN = 'not_later_than';

    /** No date yet: a period counts from a day that is not entered. */
    public const OPEN = 'open';

    /** No date: the confirmed periods of this notice do not fit together, so the app cannot tell which applies. */
    public const UNCLEAR = 'unclear';

    /**
     * @param  string  $code  The notice, as config/modules/contract_terms.php names it.
     * @param  ?string  $combine  earliest or latest when several periods go together, else null.
     * @param  list<NoticePart>  $parts
     */
    public function __construct(
        public string $code,
        public string $state,
        public ?CarbonImmutable $date,
        public ?string $combine,
        public array $parts,
    ) {}

    public function hasDate(): bool
    {
        return $this->date !== null;
    }

    /** Days from today to the date: 0 on the day, below zero once it has passed. Null when there is no date. */
    public function daysLeft(CarbonInterface|string $today): ?int
    {
        return $this->date === null ? null : HindranceMath::daysUntil($this->date, $today);
    }

    /** @return array{code: string, state: string, date: ?string, combine: ?string, parts: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'state' => $this->state,
            'date' => $this->date?->toDateString(),
            'combine' => $this->combine,
            'parts' => array_map(fn (NoticePart $part) => $part->toArray(), $this->parts),
        ];
    }
}
