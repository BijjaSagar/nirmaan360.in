<?php

namespace App\Services\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\Organization;

/**
 * A company's agreement to the notice about sending its pages, photos and text to the AI provider.
 * The notice has a version. A new wording is a new version, and nothing more is sent for a company
 * until an owner or admin has agreed to it.
 */
final class AiDocumentConsent
{
    public const VERSION = 'ai-docs-v1';

    /** Whether the company has the readers switched on under the notice as it reads today. */
    public static function current(Organization|int $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $settings = AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $id)->first();

        return $settings !== null && $settings->enabled && $settings->consent_version === self::VERSION;
    }
}
