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

    /**
     * $actor names who did it when nobody is signed in, such as a sign-up through the API or a console run.
     * Otherwise it is the signed-in person, and nobody on a client's signed link.
     *
     * $organizationId files the row under that company. Without it the row goes to the model's company, or
     * the signed-in person's; a model with no organization_id of its own, written from the console, needs it.
     */
    public static function record(string $action, Model $model, ?array $old = null, ?array $new = null, ?User $actor = null, ?int $organizationId = null): self
    {
        $hidden = ['password', 'remember_token'];

        return static::withoutGlobalScopes()->create([
            'organization_id' => $organizationId ?? $model->getAttribute('organization_id') ?? Auth::user()?->organization_id,
            'user_id' => $actor?->id ?? (Request::routeIs('client.*') ? null : Auth::id()),
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
