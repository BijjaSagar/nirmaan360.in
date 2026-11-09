<?php

use App\Models\Organization;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

if (! function_exists('inr')) {
    /** Format a money value as ₹12,34,567.00 */
    function inr(mixed $value): string
    {
        return '₹'.Dec::indian($value, 2);
    }
}

if (! function_exists('fmt_qty')) {
    /** Format a quantity to three decimals. */
    function fmt_qty(mixed $value): string
    {
        return Dec::indian($value, 3);
    }
}

if (! function_exists('org')) {
    /** The signed-in user's organisation. */
    function org(): ?Organization
    {
        return Auth::user()?->organization;
    }
}

if (! function_exists('site_setting')) {
    /** A value the platform admin has saved. */
    function site_setting(string $key, mixed $default = null): mixed
    {
        return app(PlatformSettings::class)->get($key, $default);
    }
}

if (! function_exists('site_on')) {
    /** Whether a website section is switched on. */
    function site_on(string $section): bool
    {
        return app(PlatformSettings::class)->isOn('section_'.$section);
    }
}

if (! function_exists('plan_gst_note')) {
    /** How GST applies to plan prices, for showing next to a price. */
    function plan_gst_note(): string
    {
        $pct = config('buildos.subscription_gst_pct');

        return match ((int) $pct === 0 ? 'none' : config('buildos.subscription_gst_mode')) {
            'none' => __('No GST charged'),
            'included' => __('Including :gst% GST', ['gst' => $pct]),
            default => __('Plus :gst% GST', ['gst' => $pct]),
        };
    }
}

if (! function_exists('org_exists')) {
    /** An exists rule limited to the signed-in user's organisation. */
    function org_exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('organization_id', Auth::user()->organization_id);
    }
}
