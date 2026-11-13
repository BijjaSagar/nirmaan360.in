<?php

namespace App\Services\SiteDiary;

use App\Models\ContractTerm;
use Carbon\CarbonImmutable;

/**
 * One confirmed notice period applied to one hindrance: the number of days the person entered,
 * the day the count starts on and the date that gives. Both dates are null while the starting
 * day is not known, and $missing then names what is missing.
 */
final readonly class NoticePart
{
    /** The hindrance has no end date yet. */
    public const NO_END = 'hindrance_end';

    /** No confirmed stipulated completion date covers the day the hindrance started, or more than one does. */
    public const NO_COMPLETION_DATE = 'stipulated_completion';

    public function __construct(
        public ContractTerm $term,
        public int $days,
        public string $countedFrom,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $date,
        public ?string $missing = null,
    ) {}

    /** @return array{term_id: int, days: int, counted_from: string, from: ?string, date: ?string, missing: ?string} */
    public function toArray(): array
    {
        return [
            'term_id' => (int) $this->term->id,
            'days' => $this->days,
            'counted_from' => $this->countedFrom,
            'from' => $this->from?->toDateString(),
            'date' => $this->date?->toDateString(),
            'missing' => $this->missing,
        ];
    }
}
