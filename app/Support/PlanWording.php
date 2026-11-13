<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What a person is told when their company's plan stops something.
 *
 * A website page may say upgrade and point to Billing. A reply the field app can show may not: Apple does not let
 * an app send people to pay outside it (App Store guideline 3.1.1), and on the India storefront the app may show no
 * prompt to pay elsewhere at all. So an API reply only says what the plan stops and that the company's owner can
 * check the plan. A request counts as the API's on the rule bootstrap/app.php uses to answer in JSON: an api/* path,
 * or a request that expects JSON. Console commands and queued jobs get the website wording.
 */
final class PlanWording
{
    public static function forApi(?Request $request = null): bool
    {
        $request ??= request();

        return $request->is('api/*') || $request->expectsJson();
    }

    /** A module the company's plan does not include. */
    public static function moduleNotIncluded(): string
    {
        return self::forApi()
            ? __('Your company\'s plan does not include this module. Your company\'s owner can check the plan.')
            : __('Your plan does not include this module. Upgrade to continue.');
    }

    /** No room for another project on the company's plan. */
    public static function projectLimitReached(): string
    {
        return self::forApi()
            ? __('Your company\'s plan has reached its project limit. Your company\'s owner can check the plan.')
            : __('Your plan project limit has been reached. Upgrade to add more.');
    }
}
