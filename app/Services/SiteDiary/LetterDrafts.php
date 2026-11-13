<?php

namespace App\Services\SiteDiary;

use App\Exceptions\ImmutableRecordException;
use App\Models\AiTextCall;
use App\Models\SiteLetter;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\TextCalls;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\ReviewedLanguages;
use App\Services\Wording\SlotText;
use App\Services\Wording\Violation;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingService;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Asking the AI for the three sentences of a draft letter that state no figure, and what the
 * letter's page says about that request.
 *
 * The letter stands complete without the AI. A request makes one text call (purpose "wording");
 * until it ends, and whenever its sentences are not used, the letter keeps the sentences it has.
 */
class LetterDrafts
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
        private LetterLayout $layout,
    ) {}

    /**
     * The document the AI writes for: the letter as it was last prepared, from the facts kept with it.
     * The same each time it is built, so the request and the answer are about one and the same letter.
     */
    public function document(SiteLetter $letter): WordingDocument
    {
        $sheet = FactSheet::fromArray($letter->facts ?? []);

        return new WordingDocument($this->layout->type($letter->type, $sheet), $sheet, (string) $letter->language, $this->notes($sheet));
    }

    /**
     * Ask the AI for the opening, the linking sentence and the close. A sentence the person rewrote is
     * kept as they wrote it, so a letter with all three rewritten has nothing to ask for.
     *
     * Call it outside any transaction: making the call takes the company's wallet.
     *
     * @throws ValidationException
     * @throws ImmutableRecordException The letter is finalised.
     */
    public function ask(SiteLetter $letter, User $by): AiTextCall
    {
        $letter->refresh();

        if ($letter->isFinalised()) {
            throw new ImmutableRecordException(SiteLetters::finalisedMessage());
        }

        if (! self::writable($letter)) {
            throw ValidationException::withMessages(['ai_reader' => __('The AI writes only in the languages a new document can be prepared in now, so it writes nothing in this one\'s language.')]);
        }

        $free = array_keys(LetterLayout::FREE[$letter->type]);
        $edited = array_filter($free, fn (string $id) => ($letter->slots[$id]['source'] ?? null) === SlotText::EDITED);

        if (count($edited) === count($free)) {
            throw ValidationException::withMessages(['ai_reader' => __('You have rewritten all three sentences yourself, so there is nothing for the AI to write. Empty a box and save the draft to have it written again.')]);
        }

        $document = $this->document($letter);

        return $this->wording->draft(WordingService::PURPOSE, $document->type, $document->sheet, $document->locale, $letter, $by);
    }

    /** Whether the AI may write in the letter's language: one offered for letters (ReviewedLanguages). */
    public static function writable(SiteLetter $letter): bool
    {
        return ReviewedLanguages::writable(ReviewedLanguages::SITE_LETTERS, (string) $letter->language, FactFormat::LOCALES);
    }

    /**
     * What the letter's page shows about the AI for this person.
     *
     * offered: the button may be shown (a draft, the person may use the AI, the AI is on for the company).
     * plan: the company's plan has no AI features while the AI is on for the platform, so the page may say so.
     * writing: a request is waiting or running; the page looks again shortly.
     * not_used: why the latest request's sentences were not used, when nothing was saved since.
     * credits: the most one request can cost, in whole credits.
     *
     * @return array{offered: bool, plan: bool, writing: bool, not_used: ?string, credits: ?string}
     */
    public function state(SiteLetter $letter, User $user): array
    {
        $organization = $user->organization;
        $draft = ! $letter->isFinalised();
        // The AI writes a letter to another party only in a language offered for letters (ReviewedLanguages).
        $offered = $draft && self::writable($letter) && $user->can('agents.use') && $this->spend->aiOn($organization);
        $call = $this->latest($letter);

        if ($call !== null && $call->isOpen()) {
            $call = $this->calls->watch($call);
        }

        $notUsed = null;

        // A request that ended without its sentences being used, and nothing saved on the letter since.
        if ($call !== null && in_array($call->status, [AiTextCall::FAILED, AiTextCall::REJECTED], true) && $draft
            && ($letter->updated_at === null || $call->updated_at === null || $call->updated_at->gte($letter->updated_at))) {
            $notUsed = self::notUsed((string) $call->fail_reason, $call->correction);
        }

        return [
            'offered' => $offered,
            'plan' => $draft && self::writable($letter) && ! $organization->allowsFeature('ai_agents') && $this->spend->aiOn(),
            // A finalised letter takes nothing more from the AI, so there is nothing to wait for on its page.
            'writing' => $draft && $call !== null && $call->isOpen(),
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
            'subject_changed', 'subject_gone' => __('The letter changed while the AI was writing, so its sentences were not used. You were not charged.'),
            'not_started' => __('The server did not get to it in time, so nothing was sent. Ask again later.'),
            default => __('The AI could not write the sentences this time, so the sentences of the app stand. You were not charged.'),
        };
    }

    /** The latest request about this letter. */
    public function latest(SiteLetter $letter): ?AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $letter->organization_id)
            ->where('purpose', WordingService::PURPOSE)
            ->where('subject_type', $letter->getMorphClass())
            ->where('subject_id', $letter->id)
            ->latest('id')
            ->first();
    }

    /** @return list<string> What kinds of hindrance the letter is about, by the register's own names. Never a figure or a typed word. */
    private function notes(FactSheet $sheet): array
    {
        $names = [];

        foreach ($sheet as $fact) {
            if (preg_match('/^h\d+_category$/', $fact->key) === 1) {
                $names[] = config('modules.site_diary.categories')[$fact->value] ?? null;
            }
        }

        $names = array_values(array_unique(array_filter($names)));

        return $names === [] ? [] : ['The hindrances in the letter are of these kinds: '.implode('; ', $names).'.'];
    }
}
