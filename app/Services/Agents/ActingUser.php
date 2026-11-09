<?php

namespace App\Services\Agents;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Queue jobs have no signed-in user, so tenant scopes would not apply and records would
 * have no author. This runs the work as the person who started the agent.
 */
final class ActingUser
{
    public static function run(User $user, callable $callback): mixed
    {
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }
}
