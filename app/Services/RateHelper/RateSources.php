<?php

namespace App\Services\RateHelper;

use App\Services\RateHelper\Contracts\RateSource;
use LogicException;

/** The sources listed in config/modules/rate_helper.php. */
class RateSources
{
    /**
     * A new instance of every source, in the configured order. A source keeps what it has read, so
     * each piece of work (building a shortlist, showing a run, accepting a rate) opens its own and
     * lets them go afterwards. Nothing read for one request can be used by a later one.
     *
     * @return array<string, RateSource> Key => source.
     */
    public function open(): array
    {
        $sources = [];

        foreach (config('modules.rate_helper.sources', []) as $class) {
            $source = app()->make($class);

            if (! $source instanceof RateSource) {
                throw new LogicException($class.' is listed as a rate source but does not implement '.RateSource::class.'.');
            }

            $sources[$source->key()] = $source;
        }

        return $sources;
    }
}
