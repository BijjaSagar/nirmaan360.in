<?php

namespace App\Services\SiteDiary;

use App\Exceptions\ImmutableRecordException;
use App\Models\AiTextCall;
use App\Models\SiteLetter;
use App\Models\User;
use App\Services\DocumentReading\NothingToSend;
use App\Services\Wording\SlotText;
use App\Services\Wording\WordingCall;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingDraft;
use Illuminate\Validation\ValidationException;

/**
 * The text call that has the AI write the opening, the linking sentence and the close of a draft
 * letter. Every other sentence of the letter, and every figure, stays the app's own: the AI is told
 * what each of the three sentences is for and the names of the figures around it, never a value.
 *
 * Its sentences go into the draft through SiteLetters::useSentences, which reads the facts from the
 * records again and puts the letter together once more, as saving the draft does; every tick starts
 * afresh. A sentence a person rewrote stays as they wrote it. When the AI's sentences are not used,
 * the letter is left exactly as it was and the call says why.
 *
 * Nothing here runs with a signed-in person, so every query names the company ($call->organization_id).
 */
class LetterWording extends WordingCall
{
    protected function document(AiTextCall $call): ?WordingDocument
    {
        $letter = $this->letter($call);

        return $letter === null || $letter->isFinalised() ? null : app(LetterDrafts::class)->document($letter);
    }

    protected function drafted(AiTextCall $call, WordingDraft $draft): void
    {
        // With no sentence from the AI there is nothing to change: the code text, or the person's own, stands.
        if (! $draft->fromModel()) {
            return;
        }

        $letter = $this->letter($call) ?? throw new NothingToSend;
        $by = $call->user_id === null ? null : User::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->user_id);

        if ($by === null) {
            throw new NothingToSend('starter_gone');
        }

        $kept = (array) $letter->slots;
        $texts = [];

        foreach ($draft->texts as $id => $text) {
            if (($kept[$id]['source'] ?? null) !== SlotText::EDITED) {
                $texts[$id] = $text;
            }
        }

        try {
            app(SiteLetters::class)->useSentences($letter, $texts, $by);
        } catch (ValidationException|ImmutableRecordException) {
            // The letter was finalised, or its records no longer make a letter, while the AI was writing.
            throw new NothingToSend('subject_changed');
        }
    }

    private function letter(AiTextCall $call): ?SiteLetter
    {
        return SiteLetter::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);
    }
}
