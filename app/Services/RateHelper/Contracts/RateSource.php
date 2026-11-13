<?php

namespace App\Services\RateHelper\Contracts;

use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\RateHelper\RateCandidate;
use App\Services\RateHelper\RateFigure;

/**
 * Somewhere in the company's own records that a rate for a BOQ line can be found. List the class under
 * "sources" in config/modules/rate_helper.php.
 *
 * Every query a source makes goes through the tenant models as the signed-in person, never
 * withoutGlobalScopes() and never by organisation alone, so someone limited to chosen projects gets
 * nothing from a project outside their list. A source asked on behalf of anyone but the signed-in
 * person answers with nothing.
 *
 * Report honestly: a source with nothing behind it says it is not connected, and the screen then says so.
 */
interface RateSource
{
    /** A short stable key, kept in the shortlist and on an accepted line. For example rate_library. */
    public function key(): string;

    /** English label, passed through __() where shown. Add it to lang/modules/rate_helper/{hi,mr}.json. */
    public function label(): string;

    /** False when nothing stands behind the source for this company. */
    public function isConnected(Organization $organization): bool;

    /** Whether this person's role may read what the source holds. */
    public function allows(User $viewer): bool;

    /**
     * Rows that could price this BOQ line: same unit, not a lump sum, and a description alike enough.
     * No rate travels with a candidate.
     *
     * @return iterable<RateCandidate>
     */
    public function candidates(User $viewer, BoqItem $item): iterable;

    /**
     * The rate of one row, read now, as this person. Null when the row is gone, has no rate, or is
     * not this person's to see. Called when the list is shown and again when a rate is accepted.
     */
    public function rateOf(User $viewer, int $rowId): ?RateFigure;
}
