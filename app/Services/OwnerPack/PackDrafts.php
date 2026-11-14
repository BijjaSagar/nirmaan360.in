<?php

namespace App\Services\OwnerPack;

use App\Exceptions\ImmutableRecordException;
use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\TextCalls;
use App\Services\Wording\FactSheet;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\ReviewedLanguages;
use App\Services\Wording\SlotText;
use App\Services\Wording\Violation;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingDraft;
use App\Services\Wording\WordingException;
use App\Services\Wording\WordingService;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Asking the AI for the two sentences of a quotation's cover note or of a weekly update that state
 * no figure, the opening and the closing, and what the page says about that request.
 *
 * Every other sentence, and every figure, stays the app's own. The AI is told what each of the two
 * sentences is for and the names of the figures around it, never a value, a name or anything a
 * person typed: not the client's name, not the photo captions, not the contractor's words about
 * next week. The document stands complete without the AI; until a request ends, and whenever its
 * sentences are not used, the draft keeps the sentences it has.
 */
class PackDrafts
{
    /** The rules of Guard A that are about a figure: an answer refused for one of them stated a figure, or put a token in its place. */
    private const FIGURE_RULES = [
        Violation::TOKEN, Violation::DIGIT, Violation::CURRENCY, Violation::PERCENT, Violation::MONTH,
        Violation::WEEKDAY, Violation::NUMBER_WORD, Violation::FIGURE_WORD, Violation::ROMAN,
    ];

    public function __construct(
        private WordingService $wording,
        private TextCalls $calls,
        private AiSpend $spend,
        private QuotationDocument $quotations,
        private OwnerUpdateDocument $updates,
    ) {}

    /**
     * The document the AI writes for: the draft as it was last saved, from the facts kept with it.
     * An update is described without the contractor's own words about next week, which no model writes or reads.
     *
     * @throws InvalidArgumentException|WordingException The kept facts no longer fit the document. A save of the draft puts it right.
     */
    public function document(ClientQuotation|OwnerUpdate $record): WordingDocument
    {
        $sheet = FactSheet::fromArray($record->facts ?? []);
        $language = (string) $record->language;

        if ($record instanceof ClientQuotation) {
            return new WordingDocument($this->quotations->type($language, $sheet), $sheet, $language, [
                'The document is the cover note of a quotation for building work, written for a home owner.',
            ]);
        }

        return new WordingDocument($this->updates->type($language, $sheet, withNextWeek: false), $sheet, $language, [
            'The document is a weekly update on the building work at a home owner\'s site, written for the home owner.',
        ]);
    }

    /**
     * Ask the AI for the opening and the closing. A sentence a person rewrote is kept as they wrote
     * it, so a draft with both rewritten has nothing to ask for. Pressing twice while a request is
     * open returns the same request.
     *
     * Call it outside any transaction: making the call takes the company's wallet.
     *
     * @throws ValidationException
     * @throws ImmutableRecordException The quotation is issued, or the update approved.
     */
    public function ask(ClientQuotation|OwnerUpdate $record, User $by): AiTextCall
    {
        $record->refresh();

        if (! $record->isDraft()) {
            throw new ImmutableRecordException($record::lockedMessage());
        }

        if (! self::writable($record)) {
            throw ValidationException::withMessages(['ai_reader' => __('The AI writes only in the languages a new document can be prepared in now, so it writes nothing in this one\'s language.')]);
        }

        $edited = array_filter(self::freeSlots($record), fn (string $id) => ($record->slots[$id]['source'] ?? null) === SlotText::EDITED);

        if (count($edited) === count(self::freeSlots($record))) {
            throw ValidationException::withMessages(['ai_reader' => __('You have rewritten both sentences yourself, so there is nothing for the AI to write. Empty a box and save the draft to have it written again.')]);
        }

        try {
            $document = $this->document($record);
        } catch (InvalidArgumentException|WordingException|GuardViolation $e) {
            report($e);

            throw ValidationException::withMessages(['ai_reader' => __('The draft cannot be shown from the saved figures. Save the draft again.')]);
        }

        return $this->wording->draft(WordingService::PURPOSE, $document->type, $document->sheet, $document->locale, $record, $by);
    }

    /**
     * Puts the AI's sentences into the draft, for the record's WordingCall. A sentence a person
     * rewrote stays as they wrote it. The facts kept with the draft do not change, only its two
     * sentences: a page opened before them sends back another text fingerprint, so nothing is issued
     * or approved on text the person did not read.
     *
     * Runs inside the transaction that ends the call: database work only.
     *
     * @param  class-string<ClientQuotation|OwnerUpdate>  $model
     *
     * @throws NothingToSend The draft is gone, no longer a draft, or has nothing left for the AI's sentences.
     */
    public function useSentences(string $model, AiTextCall $call, WordingDraft $draft): void
    {
        // With no sentence from the AI there is nothing to change: the code text, or the person's own, stands.
        if (! $draft->fromModel()) {
            return;
        }

        /** @var ClientQuotation|OwnerUpdate|null $record */
        $record = $model::withoutGlobalScopes()
            ->where('organization_id', $call->organization_id)
            ->whereKey($call->subject_id)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            throw new NothingToSend;
        }

        if (! $record->isDraft() || $record->language !== $draft->document->locale) {
            throw new NothingToSend('subject_changed');
        }

        $slots = (array) $record->slots;
        $written = [];

        foreach ($draft->texts as $id => $text) {
            if (in_array($id, self::freeSlots($record), true) && ($slots[$id]['source'] ?? null) !== SlotText::EDITED) {
                $slots[$id] = $text->toArray();
                $written[] = $id;
            }
        }

        // A person rewrote both sentences while the AI was writing.
        if ($written === []) {
            throw new NothingToSend('subject_changed');
        }

        $record->slots = $slots;
        $record->save();

        $starter = $call->user_id === null ? null : User::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->user_id);
        $action = $record instanceof ClientQuotation ? 'quotation.worded_by_ai' : 'owner_update.worded_by_ai';

        AuditLog::record($action, $record, null, ['call_id' => $call->id, 'language' => $record->language, 'slots' => $written], $starter);
    }

    /**
     * What the draft's page shows about the AI for this person.
     *
     * offered: the button may be shown (a draft, the person may use the AI, the AI is on for the company).
     * plan: the company's plan has no AI features while the AI is on for the platform, so the page may say so.
     * writing: a request is waiting or running; the page looks again shortly.
     * not_used: why the latest request's sentences were not used, when nothing was saved since.
     * credits: the most one request can cost, in whole credits.
     *
     * @return array{offered: bool, plan: bool, writing: bool, not_used: ?string, credits: ?string}
     */
    public function state(ClientQuotation|OwnerUpdate $record, User $user): array
    {
        $organization = $user->organization;
        $draft = $record->isDraft();
        // The AI writes for a document going to the home owner only in a language offered for it (ReviewedLanguages).
        $writable = self::writable($record);
        $offered = $draft && $writable && $user->can('agents.use') && $this->spend->aiOn($organization);
        $call = $draft ? $this->latest($record) : null;

        if ($call !== null && $call->isOpen()) {
            $call = $this->calls->watch($call);
        }

        $notUsed = null;

        // A request that ended without its sentences being used, and nothing saved on the draft since.
        if ($call !== null && in_array($call->status, [AiTextCall::FAILED, AiTextCall::REJECTED], true)
            && ($record->updated_at === null || $call->updated_at === null || $call->updated_at->gte($record->updated_at))) {
            $notUsed = self::notUsed((string) $call->fail_reason, $call->correction);
        }

        return [
            'offered' => $offered,
            'plan' => $draft && $writable && ! $organization->allowsFeature('ai_agents') && $this->spend->aiOn(),
            'writing' => $call !== null && $call->isOpen(),
            'not_used' => $notUsed,
            'credits' => $offered ? (string) Dec::of($this->calls->upTo(WordingService::PURPOSE))->toScale(0, RoundingMode::Ceiling) : null,
        ];
    }

    /**
     * Why the AI's sentences were not used, by the call's fail reason, in the reader's language. Nothing was charged for them.
     *
     * @param  ?array<string, list<string>>  $refused  What the first refused answer broke, as kept on the call. A figure is named
     *                                                 only when one was what broke a rule; an empty or unreadable answer gets the plain sentence.
     */
    public static function notUsed(string $reason, ?array $refused = null): string
    {
        $figure = array_intersect(self::FIGURE_RULES, array_merge(...array_values(array_map(fn ($rules) => (array) $rules, $refused ?? [])))) !== [];

        // An answer refused for something else, with no sentence in it or text that could not be read, stated no figure.
        if ($reason === 'rejected' && ! $figure) {
            $reason = 'unusable';
        }

        return match ($reason) {
            'rejected' => __('The sentences the AI wrote stated a figure, so the app kept its own sentences. You were not charged.'),
            'subject_changed', 'subject_gone' => __('The draft changed while the AI was writing, so its sentences were not used. You were not charged.'),
            'not_started' => __('The server did not get to it in time, so nothing was sent. Ask again later.'),
            default => __('The AI could not write the sentences this time, so the sentences of the app stand. You were not charged.'),
        };
    }

    /** The latest request for this draft's sentences. */
    public function latest(ClientQuotation|OwnerUpdate $record): ?AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $record->organization_id)
            ->where('purpose', WordingService::PURPOSE)
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->id)
            ->latest('id')
            ->first();
    }

    /** Whether the AI may write in the record's language: one offered for the home-owner pack (ReviewedLanguages). */
    public static function writable(ClientQuotation|OwnerUpdate $record): bool
    {
        return ReviewedLanguages::writable(ReviewedLanguages::OWNER_PACK, (string) $record->language, ClientQuotation::LANGUAGES);
    }

    /** @return list<string> The sentences of the record the AI may write. */
    public static function freeSlots(ClientQuotation|OwnerUpdate $record): array
    {
        return $record instanceof ClientQuotation ? QuotationDocument::FREE_SLOTS : OwnerUpdateDocument::FREE_SLOTS;
    }
}
