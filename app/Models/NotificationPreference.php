<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Which channels one person wants, and for push which kinds of update and which quiet hours.
 * In-app is always on, so it has no column.
 */
class NotificationPreference extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected $attributes = ['email' => true, 'push' => true, 'whatsapp' => false, 'quiet_hours' => true];

    protected function casts(): array
    {
        return [
            'email' => 'boolean',
            'push' => 'boolean',
            'whatsapp' => 'boolean',
            'whatsapp_opted_in_at' => 'datetime',
            'push_categories' => 'array',
            'quiet_hours' => 'boolean',
        ];
    }

    /** The saved choices, or the defaults for someone who has not chosen yet. */
    public static function for(User $user): self
    {
        return static::withoutGlobalScopes()->where('user_id', $user->id)->first()
            ?? new static(['organization_id' => $user->organization_id, 'user_id' => $user->id]);
    }

    public function allows(string $channel): bool
    {
        return (bool) $this->getAttribute($channel);
    }

    /** A kind of push stays on until the person switches it off. */
    public function allowsCategory(string $category): bool
    {
        return (bool) (($this->push_categories ?? [])[$category] ?? true);
    }

    /** @return array{0: string, 1: string} When quiet hours start and end, as "HH:MM" on the Asia/Kolkata clock. */
    public function quietWindow(): array
    {
        return [
            $this->quiet_from ?: config('modules.notify.push.quiet_hours.from'),
            $this->quiet_until ?: config('modules.notify.push.quiet_hours.until'),
        ];
    }
}
