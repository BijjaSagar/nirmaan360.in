<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A company's own role, made from the permission list. */
class CustomRole extends Model
{
    use Auditable, BelongsToOrganization;

    public const KEY_PREFIX = 'custom:';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public static function keyFor(int $id): string
    {
        return self::KEY_PREFIX.$id;
    }

    public function key(): string
    {
        return self::keyFor($this->id);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the role column holds for someone on this role. Never owner or admin. */
    public function baseRole(): string
    {
        return $this->approval_role ?? 'viewer';
    }
}
