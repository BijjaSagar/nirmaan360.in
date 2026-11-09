<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cause that needs attention on a project, raised by an alert source and resolved when the
 * cause clears. Its wording is stored in English and translated for whoever reads it. Open and
 * resolve are written to audit_logs by the engine, not by the Auditable trait, so a daily scan
 * refreshing figures leaves no noise.
 */
class Alert extends Model
{
    use BelongsToOrganization;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'data' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function titleIn(?string $locale = null): string
    {
        return __($this->title, $this->params ?? [], $locale);
    }

    public function messageIn(?string $locale = null): string
    {
        return __($this->message, $this->params ?? [], $locale);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /** Leaves out alerts whose money permission this person does not hold. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $needed = static::query()->whereNotNull('needs_permission')->distinct()->pluck('needs_permission');
        $allowed = $needed->filter(fn (string $permission) => $user->hasPermission($permission))->values()->all();

        return $query->where(fn (Builder $where) => $where->whereNull('needs_permission')->orWhereIn('needs_permission', $allowed));
    }
}
