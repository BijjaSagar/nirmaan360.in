<?php

namespace App\Services\Wording;

use App\Models\AiTextCall;
use App\Services\DocumentReading\Contracts\ChecksTextAnswer;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use App\Services\DocumentReading\NothingToSend;
use LogicException;

/**
 * The text call that has a model write the free sentences of a document. A feature extends this
 * class for each kind of record it drafts (a site letter, an owner update, a bill explanation) and
 * lists it under "text_calls" in its own config/modules file, by the record's morph name:
 *
 *     'text_calls' => ['wording' => [SiteLetter::class => LetterWording::class]],
 *
 * The feature says two things: which document the record is (document()), and where a draft is
 * kept (drafted()). Everything else is the same for every document and is done here: the request,
 * Guards A and B on the answer, the one corrective request, and the fall back to the code text.
 *
 * Nothing runs with a signed-in person, so every query names the company ($call->organization_id).
 */
abstract class WordingCall implements ChecksTextAnswer, TextCallHandler
{
    /** The rule code for an answer that had no usable text for any slot. */
    public const EMPTY = 'empty';

    /** Stands for the whole answer where a refusal is not about one slot. */
    public const ALL_SLOTS = 'slots';

    /** @var array{0: string, 1: WordingDocument}|null The document the last request was built from, and the attempt it was built for. */
    private ?array $asked = null;

    /**
     * The document the call is about, built from the records as they are now: the slots, the fact
     * sheet and the language. Find the record through $call->subject_id and $call->organization_id.
     * Return null when the record is gone or is no longer a draft.
     *
     * It is asked for when the call is made and again for every attempt, so build it the same way
     * each time. The call may be one that was never saved.
     */
    abstract protected function document(AiTextCall $call): ?WordingDocument;

    /**
     * Keep the outcome on the record: $draft->slots() for its slots column, $draft->source and
     * $draft->fingerprint(). With source "template" there is no model text; leave any sentence a
     * person has typed as it is.
     *
     * Runs inside the transaction that ends the call: database work only.
     */
    abstract protected function drafted(AiTextCall $call, WordingDraft $draft): void;

    final public function request(AiTextCall $call): array
    {
        $document = $this->document($call) ?? throw new NothingToSend;

        if ($document->type->freeSlots() === []) {
            throw new NothingToSend('nothing_to_write');
        }

        // The code text of every slot must be complete before a model is asked: it is what stands when the model's text is not used.
        app(TemplateLoader::class)->load($document->type, $document->locale);

        $this->asked = [self::attempt($call), $document];

        return WordingRequest::body($call, $document);
    }

    final public function refused(AiTextCall $call, array $output): array
    {
        // The answer is judged against the slots the model was asked for in this attempt.
        $document = $this->asked !== null && $this->asked[0] === self::attempt($call) ? $this->asked[1] : $this->document($call);

        if ($document === null) {
            // The record is gone. done() finds the same and keeps nothing, and the call ends uncharged.
            return [];
        }

        $draft = $this->intake($document, $output);

        if ($draft->hasRejections()) {
            return $draft->rejectedRules();
        }

        return $draft->accepted === [] ? [self::ALL_SLOTS => [self::EMPTY]] : [];
    }

    final public function done(AiTextCall $call, array $output): void
    {
        // Built again from the records as they are now, so the draft is kept with the fingerprint of today's facts.
        $document = $this->document($call) ?? throw new NothingToSend;

        // Sentences written for other slots or in another language are of no use to the document as it now is.
        if ($this->asked !== null && $this->asked[0] === self::attempt($call) && ! $document->sameSlotsAs($this->asked[1])) {
            throw new NothingToSend('subject_changed');
        }

        $draft = $this->intake($document, $output);

        if ($draft->hasRejections() || $draft->accepted === []) {
            throw new LogicException('A wording answer the guards refuse is never kept.');
        }

        $this->drafted($call, WordingDraft::ai($document, $draft));
    }

    final public function failed(AiTextCall $call, string $reason): void
    {
        $document = $this->document($call);

        if ($document !== null) {
            $this->drafted($call, WordingDraft::template($document, $reason));
        }
    }

    /**
     * Whether this handler builds, for a record, the very document a caller holds: the same slots to
     * write, the same language, the same facts. WordingService asks before it makes a call, so a
     * draft is never made for another document than the one the person is looking at.
     */
    final public function builds(AiTextCall $call, WordingDocument $expected): bool
    {
        return (bool) $this->document($call)?->sameAs($expected);
    }

    private function intake(WordingDocument $document, array $output): SlotDraft
    {
        return app(SlotIntake::class)->accept($document->type, $output, $document->locale);
    }

    private static function attempt(AiTextCall $call): string
    {
        return implode(':', [$call->getKey() ?? 'new', (int) $call->attempts, (string) $call->subject_type, (string) $call->subject_id]);
    }
}
