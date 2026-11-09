<?php

namespace App\Services\PriceWatch\Sources;

use App\Models\MaterialPrice;
use App\Models\PriceWatchSharedPrice;
use App\Services\PriceWatch\Contracts\PriceSource;
use App\Services\PriceWatch\Quote;
use App\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The list the platform admin keeps in /admin/price-watch. It is only as current as the admin's
 * last edit, and each row carries the date and source the admin typed, which travel with the
 * price into every company that adopts it.
 */
class SharedListSource implements PriceSource
{
    public function key(): string
    {
        return MaterialPrice::SHARED;
    }

    public function label(): string
    {
        return 'Shared list';
    }

    public function isConnected(): bool
    {
        return PriceWatchSharedPrice::query()->exists();
    }

    public function updatedOn(): ?CarbonInterface
    {
        $latest = PriceWatchSharedPrice::query()->max('updated_at');

        return $latest === null ? null : Carbon::parse($latest);
    }

    public function quotes(): iterable
    {
        foreach (PriceWatchSharedPrice::query()->orderBy('name')->get() as $row) {
            yield new Quote($row->key, $row->name, $row->unit, Dec::money($row->rate), $row->priced_on->toDateString(), $row->source);
        }
    }
}
