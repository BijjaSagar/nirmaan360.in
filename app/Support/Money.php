<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who may see money. Site staff enter quantities and never receive rates, amounts,
 * contract values or payments (money.view); budget and total spend need money.overview.
 *
 * Views check the same permissions with @can. Controllers, presenters, exports and agent
 * tools use this class, so a figure is left out on the server and the JSON keeps its key
 * with null in place of the number.
 */
final class Money
{
    public const VIEW = 'money.view';

    public const OVERVIEW = 'money.overview';

    /** Rates, amounts, contract values and payments. */
    public static function visible(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user !== null && $user->hasPermission(self::VIEW);
    }

    /** Budget and total spend. */
    public static function overview(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user !== null && $user->hasPermission(self::OVERVIEW);
    }

    /** The figure for someone who may see money, otherwise null. */
    public static function orNull(mixed $value, ?User $user = null): mixed
    {
        return self::visible($user) ? $value : null;
    }

    /** The message behind a 403 on a document that is nothing but money, such as a bill PDF. */
    public static function refusal(): string
    {
        return __('This document carries money figures, which your role does not see.');
    }
}
