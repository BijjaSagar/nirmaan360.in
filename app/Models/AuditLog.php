<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLog extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(string $action, Model $model, ?array $old = null, ?array $new = null): self
    {
        $hidden = ['password', 'remember_token'];

        return static::withoutGlobalScopes()->create([
            'organization_id' => $model->getAttribute('organization_id') ?? Auth::user()?->organization_id,
            'user_id' => Request::routeIs('client.*') ? null : Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old === null ? null : array_diff_key($old, array_flip($hidden)),
            'new_values' => $new === null ? null : array_diff_key($new, array_flip($hidden)),
            'ip_address' => Request::ip(),
        ]);
    }

    public function shortType(): string
    {
        return class_basename((string) $this->auditable_type);
    }
}
