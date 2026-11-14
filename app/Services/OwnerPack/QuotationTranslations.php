<?php

namespace App\Services\OwnerPack;

use App\Exceptions\ImmutableRecordException;
use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\TextCalls;
use App\Services\DocumentReading\TextOutcome;
use App\Services\DocumentReading\TextRequest;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Translations of the descriptions of a draft quotation's lines into its language, Hindi or Marathi.
 *
 * The AI proposes them (DescriptionTranslation). Each waits on its line until a person who manages
 * the pack reads it and accepts it; only then does the quotation print it, always beside the
 * description itself, and only then is it one of the facts the quotation is issued on. A line that
 * nobody accepted prints its description alone. Once the quotation is issued its lines never change,
 * so nothing here works on an issued quotation.
 */
class QuotationTranslations
{
    /** The languages a description is translated into: those of the quotation that are not English. */
    public const LANGUAGES = ['hi', 'mr'];

    /** Characters of the answer around its lines: {"lines":[ and ]}. */
    private const ANSWER_FRAME_CHARS = 16;

    /** Characters of one line's place in the answer besides its text: {"ref":"L12","text":""}, and a little more. */
    private const LINE_FRAME_CHARS = 32;

    /** Characters of a request besides its lines' material: the tag around it, the request sentence and a corrective note. */
    private const REQUEST_EXTRA_CHARS = 1200;

    /** The most times one request's share is halved after requests that were cut off. */
    private const MOST_HALVINGS = 3;

    public function __construct(
        private TextCalls $calls,
        private AiSpend $spend,
        private QuotationService $quotations,
    ) {}

    /**
     * The lines one request sends, by ref: those with no accepted translation whose description can be
     * sent whole. Lines never translated come first, then lines whose translation waits for a person,
     * each in the order of the quotation, for as long as their translations fit in one answer and the
     * request stays within what one text call may send. The rest go with the next request.
     *
     * An answer that runs out of room is lost whole, so a request after one that was cut off sends half
     * as many lines' worth, and again after each further one in a row.
     *
     * @param  ?AiTextCall  $for  The request these lines are for, when it exists: only the requests before it count.
     * @return array<string, array{id: int, text: string}> L1, L2 ... => the line's id and its description.
     */
    public function toSend(ClientQuotation $quotation, ?AiTextCall $for = null): array
    {
        $waiting = $this->quotations->items($quotation)
            ->filter(fn (ClientQuotationItem $item) => $item->translation_accepted_at === null && self::sendable((string) $item->description))
            ->values();

        $ordered = $waiting->filter(fn (ClientQuotationItem $item) => $item->description_translated === null)
            ->concat($waiting->filter(fn (ClientQuotationItem $item) => $item->description_translated !== null))
            ->take(max(1, (int) config('modules.owner_pack.translation.most_lines')))
            ->values();

        $room = intdiv(self::answerTokens(), 2 ** $this->cutOffsBefore($quotation, $for));
        $longest = TextRequest::longest() - self::requestChars();
        $answer = TextRequest::tokens(self::ANSWER_FRAME_CHARS);
        $sent = 0;
        $lines = [];

        foreach ($ordered as $item) {
            $ref = 'L'.(count($lines) + 1);
            $text = (string) $item->description;
            $answer += self::lineTokens($text);
            $sent += self::sentChars($ref, $text);

            // One line always goes: each that can be sent fits in an answer by itself.
            if ($lines !== [] && ($answer > $room || $sent > $longest)) {
                break;
            }

            $lines[$ref] = ['id' => (int) $item->id, 'text' => $text];
        }

        return $lines;
    }

    /**
     * Whether a description can be sent for translation as it is: it has words, it is not longer than
     * one request takes for a line, its translation fits in one answer by itself, and nothing in it is a
     * personal detail that would be taken out before sending. A translation of a description with a
     * part missing could not be checked against it.
     */
    public static function sendable(string $description): bool
    {
        $text = trim($description);

        return $text !== ''
            && mb_check_encoding($text, 'UTF-8')
            && preg_match('/\p{L}/u', $text) === 1
            && mb_strlen($text) <= (int) config('modules.owner_pack.translation.description_chars')
            && TextRequest::tokens(self::ANSWER_FRAME_CHARS) + self::lineTokens($text) <= self::answerTokens()
            && TextRequest::withoutPersonalDetails($text) === $text;
    }

    /**
     * The tokens one request's answer is planned to fill: a share of the most the purpose may write. The
     * rest is room for the model's own thinking, which counts against the same limit, and for a
     * translation that turns out longer than planned.
     */
    public static function answerTokens(): int
    {
        $most = (int) TextRequest::figures(DescriptionTranslation::PURPOSE)['max_tokens'];

        return Dec::of($most)->multipliedBy(Dec::of(config('modules.owner_pack.translation.answer_share')))->toScale(0, RoundingMode::Down)->toInt();
    }

    /**
     * The tokens the translation of one description is planned to take in the answer, with its place in
     * the JSON. A translation is planned as longer than its description: Devanagari text takes more
     * tokens than English text of the same length.
     */
    public static function lineTokens(string $description): int
    {
        $chars = Dec::of(mb_strlen($description))->multipliedBy(Dec::of(config('modules.owner_pack.translation.growth')))->toScale(0, RoundingMode::Up)->toInt();

        return TextRequest::tokens($chars + self::LINE_FRAME_CHARS);
    }

    /** What every request sends besides its lines: the rules, the instructions, the form and the rest. */
    private static function requestChars(): int
    {
        return mb_strlen(TextRequest::RULES."\n\n".trim(DescriptionTranslation::INSTRUCTIONS))
            + mb_strlen((string) json_encode(DescriptionTranslation::schema(), JSON_UNESCAPED_UNICODE))
            + self::REQUEST_EXTRA_CHARS;
    }

    /** What one line adds to a request: its entry in the material and its ref in the request sentence. */
    private static function sentChars(string $ref, string $text): int
    {
        return mb_strlen((string) json_encode(['ref' => $ref, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + mb_strlen($ref) + 4;
    }

    /** How many requests in a row, just before this one, were cut off because their answer ran out of room. */
    private function cutOffsBefore(ClientQuotation $quotation, ?AiTextCall $for): int
    {
        $earlier = AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->where('purpose', DescriptionTranslation::PURPOSE)
            ->where('subject_type', $quotation->getMorphClass())
            ->where('subject_id', $quotation->id)
            ->when($for?->getKey() !== null, fn ($query) => $query->where('id', '<', $for->getKey()))
            ->latest('id')
            ->limit(self::MOST_HALVINGS)
            ->get(['status', 'fail_reason']);

        $count = 0;

        foreach ($earlier as $call) {
            if ($call->status !== AiTextCall::FAILED || $call->fail_reason !== TextOutcome::CUT_OFF) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Ask the AI to translate the lines that wait for a translation. Pressing twice while a request is
     * open returns the same request.
     *
     * Call it outside any transaction: making the call takes the company's wallet.
     *
     * @throws ValidationException
     * @throws ImmutableRecordException The quotation is issued.
     */
    public function ask(ClientQuotation $quotation, User $by): AiTextCall
    {
        $quotation->refresh();

        if (! $quotation->isDraft()) {
            throw new ImmutableRecordException(ClientQuotation::lockedMessage());
        }

        if (! in_array($quotation->language, self::LANGUAGES, true)) {
            throw ValidationException::withMessages(['ai_reader' => __('Descriptions are translated for a quotation in Hindi or Marathi. Choose the language of the quotation first.')]);
        }

        if (! PackDrafts::writable($quotation)) {
            throw ValidationException::withMessages(['ai_reader' => __('The AI writes only in the languages a new document can be prepared in now, so it writes nothing in this one\'s language.')]);
        }

        if ($this->toSend($quotation) === []) {
            throw ValidationException::withMessages(['ai_reader' => __('No line is waiting for a translation: each has an accepted translation, or its description cannot be sent as it is.')]);
        }

        return $this->calls->start(DescriptionTranslation::PURPOSE, $quotation, $by);
    }

    /**
     * A person accepts the translation of one line, as the page showed it. From now on the quotation
     * prints it beside the description, and it is one of the facts the quotation is issued on.
     *
     * @param  string  $seen  self::seal() of the translation the page showed.
     *
     * @throws ValidationException
     * @throws ImmutableRecordException
     */
    public function accept(ClientQuotation $quotation, ClientQuotationItem $item, string $seen, User $by): ClientQuotationItem
    {
        return $this->onLine($quotation, $item, function (ClientQuotation $quotation, ClientQuotationItem $line) use ($seen, $by) {
            if ($line->description_translated === null) {
                throw ValidationException::withMessages(['translation' => __('This line has no translation to accept.')]);
            }

            if (! hash_equals(self::seal($line), $seen)) {
                throw ValidationException::withMessages(['translation' => __('The translation of this line changed after the page was opened. Read it again before you accept it.')]);
            }

            // A second press, or a retry after a lost answer.
            if ($line->translation_accepted_at !== null) {
                return $line;
            }

            $line->update(['translation_accepted_by' => $by->id, 'translation_accepted_at' => now()]);
            $this->quotations->recalculate($quotation);

            AuditLog::record('quotation.translation_accepted', $quotation, null, $this->summary($line), $by);

            return $line;
        });
    }

    /**
     * A person takes back an accepted translation. The line prints its description alone again; the
     * translation stays on the line, waiting, so it can be accepted again or translated afresh.
     *
     * @throws ImmutableRecordException
     */
    public function withdraw(ClientQuotation $quotation, ClientQuotationItem $item, User $by): ClientQuotationItem
    {
        return $this->onLine($quotation, $item, function (ClientQuotation $quotation, ClientQuotationItem $line) use ($by) {
            if ($line->translation_accepted_at === null) {
                return $line;
            }

            $line->update(['translation_accepted_by' => null, 'translation_accepted_at' => null]);
            $this->quotations->recalculate($quotation);

            AuditLog::record('quotation.translation_withdrawn', $quotation, null, $this->summary($line), $by);

            return $line;
        });
    }

    /**
     * What the quotation's page shows about translating its lines for this person.
     *
     * offered: the button may be shown (a draft in Hindi or Marathi, a line to send, the person may use the AI, the AI is on for the company).
     * plan: the company's plan has no AI features while the AI is on for the platform, so the page may say so.
     * language: the quotation is in English, so there is nothing to translate into.
     * writing: a request is waiting or running; the page looks again shortly.
     * not_used: why the latest request brought nothing, when nothing was changed on the lines since.
     * lines: how many lines one request sends now. credits: the most one request can cost, in whole credits.
     *
     * @return array{offered: bool, plan: bool, language: bool, writing: bool, not_used: ?string, lines: int, credits: ?string}
     */
    public function state(ClientQuotation $quotation, User $user): array
    {
        $organization = $user->organization;
        $draft = $quotation->isDraft();
        $inLanguage = in_array($quotation->language, self::LANGUAGES, true);
        // A translation goes into a document for the home owner, so only into a language offered for it (ReviewedLanguages).
        $translatable = $draft && $inLanguage && PackDrafts::writable($quotation);
        $call = $draft ? $this->latest($quotation) : null;

        if ($call !== null && $call->isOpen()) {
            $call = $this->calls->watch($call);
        }

        // After the latest request is looked after: one that was just cut off makes the next one smaller.
        $lines = $translatable ? $this->toSend($quotation) : [];
        $offered = $lines !== [] && $user->can('agents.use') && $this->spend->aiOn($organization);
        $notUsed = null;

        if ($call !== null && in_array($call->status, [AiTextCall::FAILED, AiTextCall::REJECTED], true)
            && ($quotation->updated_at === null || $call->updated_at === null || $call->updated_at->gte($quotation->updated_at))) {
            $notUsed = self::notUsed((string) $call->fail_reason, $call->correction);
        }

        $chars = array_sum(array_map(fn (array $line) => mb_strlen($line['text']) + 40, $lines));

        return [
            'offered' => $offered,
            'plan' => $lines !== [] && ! $organization->allowsFeature('ai_agents') && $this->spend->aiOn(),
            'language' => $draft && ! $inLanguage,
            'writing' => $call !== null && $call->isOpen(),
            'not_used' => $notUsed,
            'lines' => count($lines),
            'credits' => $offered ? (string) Dec::of($this->calls->upTo(DescriptionTranslation::PURPOSE, $chars))->toScale(0, RoundingMode::Ceiling) : null,
        ];
    }

    /**
     * Why the latest request brought no translation, by the call's fail reason, in the reader's language. Nothing was charged for it.
     *
     * @param  ?array<string, list<string>>  $refused  What the first refused answer broke, as kept on the call. The numbers are named
     *                                                 only when they were what broke a rule; an answer refused for anything else gets the plain sentence.
     */
    public static function notUsed(string $reason, ?array $refused = null): string
    {
        $numbers = array_intersect([TranslationCheck::NUMBERS, TranslationCheck::GRADES], array_merge(...array_values(array_map(fn ($rules) => (array) $rules, $refused ?? [])))) !== [];

        return match (true) {
            $reason === 'rejected' && $numbers => __('No translation the AI wrote kept the numbers, grades and ratios of its line, so none was kept. You were not charged.'),
            $reason === TextOutcome::CUT_OFF => __('The translations did not fit in one answer, so none was kept. The next request sends fewer lines. You were not charged.'),
            in_array($reason, ['subject_changed', 'subject_gone'], true) => __('The quotation changed while the AI was translating, so its translations were not used. You were not charged.'),
            $reason === 'nothing_to_write' => __('No line was waiting for a translation when the request was sent. You were not charged.'),
            $reason === 'not_started' => __('The server did not get to it in time, so nothing was sent. Ask again later.'),
            default => __('The AI could not translate the lines this time. You were not charged.'),
        };
    }

    /** The latest request about this quotation. */
    public function latest(ClientQuotation $quotation): ?AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->where('purpose', DescriptionTranslation::PURPOSE)
            ->where('subject_type', $quotation->getMorphClass())
            ->where('subject_id', $quotation->id)
            ->latest('id')
            ->first();
    }

    /** What the page sends back with "Accept": the translation it showed, so a newer one is never accepted unread. */
    public static function seal(ClientQuotationItem $item): string
    {
        return hash('sha256', (string) $item->description_translated);
    }

    /** Whether a line prints its translation: one is there and a person accepted it. */
    public static function printed(ClientQuotationItem $item): bool
    {
        return $item->translation_accepted_at !== null && trim((string) $item->description_translated) !== '';
    }

    /**
     * Runs a change to one line of a draft with the quotation and the line held until it is done.
     *
     * @template T
     *
     * @param  callable(ClientQuotation, ClientQuotationItem): T  $change
     * @return T
     */
    private function onLine(ClientQuotation $quotation, ClientQuotationItem $item, callable $change): mixed
    {
        return DB::transaction(function () use ($quotation, $item, $change) {
            $quotation = ClientQuotation::withoutGlobalScopes()
                ->where('organization_id', $quotation->organization_id)
                ->whereKey($quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $quotation->isDraft()) {
                throw new ImmutableRecordException(ClientQuotation::lockedMessage());
            }

            $line = ClientQuotationItem::withoutGlobalScopes()
                ->where('organization_id', $quotation->organization_id)
                ->where('quotation_id', $quotation->id)
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $change($quotation, $line);
        });
    }

    /** @return array<string, mixed> What the audit trail keeps about a line's translation. */
    private function summary(ClientQuotationItem $line): array
    {
        return [
            'line_id' => $line->id,
            'code' => $line->code,
            'translation' => $line->description_translated,
        ];
    }
}
