<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['organization_id', 'name', 'email', 'phone', 'role', 'locale', 'is_active', 'password', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasPermission(string $permission): bool
    {
        foreach (config("buildos.roles.{$this->role}.permissions", []) as $granted) {
            if ($granted === '*' || Str::is($granted, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function roleLabel(): string
    {
        return config("buildos.roles.{$this->role}.label", $this->role);
    }

    /** Staff who run the platform. Separate from company roles, which all grant '*' to owners. */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin || in_array(strtolower($this->email), config('buildos.platform_admins'), true);
    }

    public function isApprovalOverride(): bool
    {
        return in_array($this->role, config('buildos.approver_override_roles'), true);
    }
}
