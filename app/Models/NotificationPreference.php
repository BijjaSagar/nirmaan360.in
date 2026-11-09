<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Which channels one person wants. In-app is always on, so it has no column. */
class NotificationPreference extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected $attributes = ['email' => true, 'push' => true, 'whatsapp' => false];

    protected function casts(): array
    {
        return [
            'email' => 'boolean',
            'push' => 'boolean',
            'whatsapp' => 'boolean',
            'whatsapp_opted_in_at' => 'datetime',
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
}
