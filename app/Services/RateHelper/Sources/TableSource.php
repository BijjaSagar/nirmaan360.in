<?php

namespace App\Services\RateHelper\Sources;

use App\Models\BoqItem;
use App\Models\User;
use App\Services\RateHelper\CandidatePool;
use App\Services\RateHelper\Contracts\BatchedRateSource;
use App\Services\RateHelper\RateCandidate;
use App\Services\RateHelper\RateFigure;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A source whose candidates are rows of one of the company's own tables, matched to a BOQ line by
 * unit and by description.
 *
 * What it has read is kept on the instance, so an instance serves one request and is then thrown
 * away (see RateSources::open). A rate read while a list was shown is never the rate written later.
 */
abstract class TableSource implements BatchedRateSource
{
    /** @var array<string, CandidatePool> */
    private array $pools = [];

    /** @var array<int, RateFigure|null> */
    private array $figures = [];

    public function candidates(User $viewer, BoqItem $item): iterable
    {
        if (! $this->answers($viewer)) {
            return;
        }

        $pool = $this->pools[$this->poolKey($item)] ??= $this->pool($item);
        $matches = $pool->matches(
            $item->description,
            $item->unit,
            (int) config('modules.rate_helper.min_score', 20),
            (int) config('modules.rate_helper.shortlist_size', 5),
        );

        foreach ($matches as $rowId => $score) {
            yield new RateCandidate($this->key(), $rowId, $score);
        }
    }

    public function prime(User $viewer, array $rowIds): void
    {
        if (! $this->answers($viewer)) {
            return;
        }

        $missing = array_values(array_diff(array_unique(array_map('intval', $rowIds)), array_keys($this->figures)));

        if ($missing === []) {
            return;
        }

        foreach ($missing as $rowId) {
            $this->figures[$rowId] = null;
        }

        foreach ($this->rows($missing) as $row) {
            $this->figures[(int) $row->getKey()] = $this->figure($row);
        }
    }

    public function rateOf(User $viewer, int $rowId): ?RateFigure
    {
        if (! $this->answers($viewer)) {
            return null;
        }

        $this->prime($viewer, [$rowId]);

        return $this->figures[$rowId] ?? null;
    }

    /**
     * The tenant scopes follow the signed-in person, so a source only answers for that person, and
     * only while they may see money, the company has the source, and their role may read it.
     */
    protected function answers(User $viewer): bool
    {
        return Auth::id() !== null
            && (int) Auth::id() === (int) $viewer->getKey()
            && Money::visible($viewer)
            && $viewer->organization !== null
            && $this->isConnected($viewer->organization)
            && $this->allows($viewer);
    }

    protected function poolLimit(): int
    {
        return (int) config('modules.rate_helper.pool_limit', 5000);
    }

    /** One pool is built per value of this key. A source whose rows do not depend on the line's project has one pool. */
    protected function poolKey(BoqItem $item): string
    {
        return 'all';
    }

    /** Every row this person can reach that has a rate, newest first, as ids and words. Read through the scoped model. */
    abstract protected function pool(BoqItem $item): CandidatePool;

    /**
     * These rows, read through the scoped model as the signed-in person.
     *
     * @param  list<int>  $rowIds
     * @return iterable<Model>
     */
    abstract protected function rows(array $rowIds): iterable;

    /** Null when the row has no rate to offer, such as a lump sum. */
    abstract protected function figure(Model $row): ?RateFigure;
}
