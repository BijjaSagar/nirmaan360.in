<?php

namespace App\Services\DocumentReading;

/**
 * The company's wallet cannot cover what was asked, or the person asking may not hold that much of it.
 * The field app shows the sentence as it is, so it never says buy (App Store guideline 3.1.1; see PlanWording).
 */
final class InsufficientCredits extends SpendRefused
{
    /** The free balance is too small. */
    public const WALLET = 'wallet';

    /** Enough is there, but only an owner or admin may put more than the set share of the wallet on hold. */
    public const HOLD_SHARE = 'hold_share';

    public function __construct(public readonly string $limit)
    {
        parent::__construct(self::NO_CREDITS, 402, match ($limit) {
            self::HOLD_SHARE => __('Part of your company\'s AI credits is kept free for other work, so this needs an owner or admin to start it.'),
            default => __('Your company does not have enough AI credits for this. Choose fewer pages, or ask your company\'s owner to check the credits.'),
        }, ['limit' => $limit]);
    }
}
