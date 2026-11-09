<?php

namespace App\Services\Access;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/** A signed-in device is a Sanctum token. Only its name and dates are ever shown. */
class DeviceService
{
    /** @return Collection<int, PersonalAccessToken> */
    public function list(User $user): Collection
    {
        return $user->tokens()
            ->orderByRaw('last_used_at is null')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);
    }

    /** Looked up through the owner, so a token of anyone else is a 404. */
    public function revoke(User $owner, int $tokenId): void
    {
        $token = $owner->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();

        AuditLog::record('device.revoked', $owner, null, ['device' => $token->name]);
    }

    public function revokeAll(User $owner): int
    {
        $count = $owner->tokens()->delete();

        if ($count > 0) {
            AuditLog::record('device.revoked_all', $owner, null, ['count' => $count]);
        }

        return $count;
    }
}
