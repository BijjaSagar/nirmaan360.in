<?php

namespace App\Services\BillCheck\Explain;

use App\Models\AiTextCall;
use App\Models\BillCheck;
use App\Models\BillCheckExplanation;
use App\Services\Wording\WordingCall;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingDraft;

/**
 * The text call that has a model write, for one explanation, what each topic of the findings means.
 * Registered in config/modules/bill_check.php under text_calls.wording for BillCheckExplanation.
 *
 * Runs on the queue with nobody signed in, so every query names the company.
 */
class ExplanationWording extends WordingCall
{
    public function __construct(private ExplanationDocument $documents) {}

    protected function document(AiTextCall $call): ?WordingDocument
    {
        $explanation = BillCheckExplanation::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);

        if ($explanation === null) {
            return null;
        }

        $check = BillCheck::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($explanation->bill_check_id);

        return $check === null ? null : $this->documents->forModel($check, (string) $explanation->locale);
    }

    protected function drafted(AiTextCall $call, WordingDraft $draft): void
    {
        $explanation = BillCheckExplanation::withoutGlobalScopes()->where('organization_id', $call->organization_id)->whereKey($call->subject_id);

        // A model's explanation that stands is never put back to the app's own wording by a later call that failed.
        if (! $draft->fromModel()) {
            $explanation->where('source', '!=', BillCheckExplanation::AI);
        }

        $explanation->update([
            'source' => $draft->fromModel() ? BillCheckExplanation::AI : BillCheckExplanation::TEMPLATE,
            'slots' => json_encode($draft->slots(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'fingerprint' => $draft->fingerprint(),
            'reason' => $draft->reason === null ? null : mb_substr($draft->reason, 0, 24),
            'ai_text_call_id' => $call->id,
            'drafted_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
