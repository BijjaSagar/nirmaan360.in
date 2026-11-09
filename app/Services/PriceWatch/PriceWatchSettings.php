<?php

namespace App\Services\PriceWatch;

use App\Support\Dec;
use App\Support\PlatformSettings;

/**
 * The three numbers behind the module, as the platform admin last set them in /admin, with the
 * module config as the fallback: how old a price may be before it is out of date, the rise that
 * opens a price alert, and the budget share at which the total impact becomes an alert.
 */
class PriceWatchSettings
{
    public const STALE_DAYS = 'price_watch_stale_days';

    public const RISE_PCT = 'price_watch_rise_pct';

    public const IMPACT_PCT = 'price_watch_impact_pct';

    public function __construct(private PlatformSettings $settings) {}

    public function staleDays(): int
    {
        return max(1, (int) $this->settings->get(self::STALE_DAYS, config('modules.price_watch.stale_after_days', 30)));
    }

    /** Percent, two decimals. */
    public function risePct(): string
    {
        return Dec::money($this->settings->get(self::RISE_PCT, config('modules.price_watch.rise_alert_pct', 5)));
    }

    /** Percent of the budget, two decimals. */
    public function impactPct(): string
    {
        return Dec::money($this->settings->get(self::IMPACT_PCT, config('modules.price_watch.impact_alert_pct', 1)));
    }

    public function rules(): array
    {
        return [
            'stale_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'rise_pct' => ['required', 'numeric', 'min:0.01', 'max:1000', 'decimal:0,2'],
            'impact_pct' => ['required', 'numeric', 'min:0.01', 'max:100', 'decimal:0,2'],
        ];
    }

    public function save(array $data): void
    {
        $this->settings->set([
            self::STALE_DAYS => (string) (int) $data['stale_days'],
            self::RISE_PCT => Dec::money($data['rise_pct']),
            self::IMPACT_PCT => Dec::money($data['impact_pct']),
        ]);
    }
}
