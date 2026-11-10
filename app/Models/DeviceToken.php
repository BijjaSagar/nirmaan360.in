<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A phone that can receive push notifications. Not audited, so the token stays out of audit_logs.
 * It belongs to one person and to the sign-in that registered it: when that sign-in ends, by
 * signing out or by an admin taking access away, the phone is forgotten before anything is sent.
 */
class DeviceToken extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_seen_at' => 'datetime'];
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** False once the sign-in that registered this phone has been signed out, revoked or has expired. */
    public function isSignedIn(): bool
    {
        if ($this->access_token_id === null) {
            return true;
        }

        $signIn = PersonalAccessToken::query()->whereKey($this->access_token_id)->where('tokenable_id', $this->user_id)->first(['id', 'expires_at']);

        return $signIn !== null && ($signIn->expires_at === null || $signIn->expires_at->isFuture());
    }
}
