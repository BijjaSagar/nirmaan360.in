<?php

namespace App\Services\OwnerPack;

use App\Models\AiTextCall;
use App\Models\ClientQuotation;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\WordingCall;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingDraft;
use App\Services\Wording\WordingException;
use InvalidArgumentException;

/**
 * The text call that has the AI write the opening and the closing of a draft quotation's cover note.
 * Every other sentence, and every figure, stays the app's own (PackDrafts).
 *
 * Nothing here runs with a signed-in person, so every query names the company ($call->organization_id).
 */
class QuotationWording extends WordingCall
{
    protected function document(AiTextCall $call): ?WordingDocument
    {
        $quotation = ClientQuotation::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);

        if ($quotation === null || ! $quotation->isDraft()) {
            return null;
        }

        try {
            return app(PackDrafts::class)->document($quotation);
        } catch (InvalidArgumentException|WordingException|GuardViolation) {
            return null;
        }
    }

    protected function drafted(AiTextCall $call, WordingDraft $draft): void
    {
        app(PackDrafts::class)->useSentences(ClientQuotation::class, $call, $draft);
    }
}
