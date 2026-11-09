<?php

namespace App\Services\PriceWatch\Contracts;

use App\Services\PriceWatch\Quote;
use Carbon\CarbonInterface;

/**
 * Somewhere prices can be taken from: today the shared list the platform admin keeps, later a
 * real data provider. List the class under "sources" in config/modules/price_watch.php. The
 * recorder asks each connected source for its quotes and appends the ones a company does not
 * already have, so a source never writes to a company's list itself.
 *
 * Report honestly: a source with nothing behind it says it is not connected, and the screens
 * then say so. Nothing is ever shown as live or as market data unless a source really is.
 */
interface PriceSource
{
    /** A short stable key, kept on every price row taken from it as source_kind. For example shared. */
    public function key(): string;

    /** English label, passed through __() where shown. Add it to lang/modules/price_watch/{hi,mr}.json. */
    public function label(): string;

    /** False when nothing stands behind the source: no rows, no keys, no reachable service. */
    public function isConnected(): bool;

    /** When the source last changed, for the screens. Null when unknown or not connected. */
    public function updatedOn(): ?CarbonInterface;

    /**
     * The newest price it has for each material key, matched to a company's materials by key.
     *
     * @return iterable<Quote>
     */
    public function quotes(): iterable;
}
