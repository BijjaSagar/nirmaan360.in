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

    /** Where the company's own row keeps a copy of whether it has agreed, for agreed(). */
    private const AGREED = 'ai_readers_agreed';

    protected static function booted(): void
    {
        static::saved(function (self $settings) {
            if ($settings->wasRecentlyCreated || $settings->wasChanged('enabled')) {
                self::copyToCompany((int) $settings->organization_id, (bool) $settings->enabled);
            }
        });

        static::deleted(fn (self $settings) => self::copyToCompany((int) $settings->organization_id, false));
    }

    /**
     * Whether the company has switched the readers on and not off since, as its menu shows it (ReaderSettings::shown).
     * The menu asks on every page, so this reads a copy kept on the company's own row, which every page has loaded,
     * and asks no query. Anything that sends a document reads this row itself (RequireAiReader).
     */
    public static function agreed(Organization $organization): bool
    {
        return (bool) ($organization->settings[self::AGREED] ?? false);
    }

    private static function copyToCompany(int $organizationId, bool $enabled): void
    {
        $organization = Organization::find($organizationId);

        $organization?->forceFill(['settings' => [...($organization->settings ?? []), self::AGREED => $enabled]])->saveQuietly();
    }

    /** The company's row, made the first time it is asked for. Safe on the queue, where no tenant scope applies. */
    public static function for(Organization|int $organization): self
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $find = fn () => static::withoutGlobalScopes()->where('organization_id', $id)->first();

        return $find() ?? Idempotent::create(fn () => static::create(['organization_id' => $id])->refresh(), $find);
    }
}
