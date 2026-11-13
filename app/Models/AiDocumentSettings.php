<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per company: its consent to send documents to the AI provider, the limits its owner has
 * set, and what the platform admin has allowed or stopped for it.
 */
class AiDocumentSettings extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $table = 'ai_document_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'platform_block' => 'boolean',
            'budgets' => 'array',
            'max_credits_per_read' => 'decimal:4',
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'quota_lifted_at' => 'datetime',
        ];
    }

    /** The company's row, made the first time it is asked for. Safe on the queue, where no tenant scope applies. */
    public static function for(Organization|int $organization): self
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $find = fn () => static::withoutGlobalScopes()->where('organization_id', $id)->first();

        return $find() ?? Idempotent::create(fn () => static::create(['organization_id' => $id])->refresh(), $find);
    }
}
