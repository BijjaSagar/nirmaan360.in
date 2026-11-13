<?php

namespace App\Services\Procurement;

use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How much of a material a piece of BOQ work may use, from its norm. The one calculation behind the
 * reconciliation page, the wastage alert raised on an issue, and material control's "over used" flag,
 * so the three can never draw the line in different places.
 *
 *   Theoretical = work done x norm per unit
 *   Allowed     = theoretical x (1 + allowed wastage % / 100)
 *
 * Worked example: 50 cum of RCC at 8.4 bags/cum with 2% wastage allowed. Theoretical 420 bags,
 * allowed 428.4. Using 428 bags is within the allowance; 450 is wastage.
 */
final class WastageAllowance
{
    /** The BOQ quantity for this much work, to three places. Work below zero counts as none. */
    public static function theoretical(mixed $work, mixed $perUnit): BigDecimal
    {
        $work = Dec::of($work);
        $work = $work->isPositive() ? $work : BigDecimal::zero();

        return Dec::of(Dec::qty($work->multipliedBy(Dec::of($perUnit))));
    }

    /** What may be used before it counts as wastage, to three places. A norm with no percentage allows none. */
    public static function allowed(mixed $theoretical, mixed $wastagePct): BigDecimal
    {
        return Dec::of(Dec::qty(
            Dec::of($theoretical)->multipliedBy(Dec::of($wastagePct ?? 0)->plus(100))->dividedBy(100, 6, RoundingMode::HalfUp)
        ));
    }

    /** The extra quantity the wastage % allows on top of the theoretical quantity. */
    public static function allowance(mixed $theoretical, mixed $wastagePct): BigDecimal
    {
        return self::allowed($theoretical, $wastagePct)->minus(Dec::of($theoretical));
    }

    /** Used beyond what is allowed: the line both the flag and the alert use. */
    public static function exceeds(mixed $used, mixed $allowed): bool
    {
        return Dec::of($used)->isGreaterThan(Dec::of($allowed));
    }

    /** ok, wastage (beyond the allowance), under (below the theoretical quantity) or no_work (used with no work done). */
    public static function status(mixed $theoretical, mixed $allowed, mixed $used): string
    {
        $theoretical = Dec::of($theoretical);
        $used = Dec::of($used);

        return match (true) {
            $theoretical->isZero() => $used->isPositive() ? 'no_work' : 'ok',
            self::exceeds($used, $allowed) => 'wastage',
            $used->isLessThan($theoretical) => 'under',
            default => 'ok',
        };
    }
}
