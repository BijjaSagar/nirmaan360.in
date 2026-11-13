<?php

namespace App\Services\OwnerPack;

use App\Models\AiTextCall;
use App\Models\OwnerUpdate;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\WordingCall;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingDraft;
use App\Services\Wording\WordingException;
use InvalidArgumentException;

/**
 * The text call that has the AI write the opening and the closing of a draft weekly update.
 * Every other sentence, and every figure, stays the app's own (PackDrafts).
 *
 * Nothing here runs with a signed-in person, so every query names the company ($call->organization_id).
 */
class OwnerUpdateWording extends WordingCall
{
    protected function document(AiTextCall $call): ?WordingDocument
    {
        $update = OwnerUpdate::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);

        if ($update === null || ! $update->isDraft()) {
            return null;
        }

        try {
            return app(PackDrafts::class)->document($update);
        } catch (InvalidArgumentException|WordingException|GuardViolation) {
            return null;
        }
    }

    protected function drafted(AiTextCall $call, WordingDraft $draft): void
    {
        app(PackDrafts::class)->useSentences(OwnerUpdate::class, $call, $draft);
    }
}
