<?php

use App\Models\Organization;
use App\Support\Dec;
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

if (! function_exists('org_exists')) {
    /** An exists rule limited to the signed-in user's organisation. */
    function org_exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('organization_id', Auth::user()->organization_id);
    }
}
