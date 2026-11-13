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

    public function __construct(
        private TextCalls $calls,
        private AiSpend $spend,
        private QuotationService $quotations,
    ) {}

    /**
     * The lines one request sends, by ref: those with no accepted translation whose description can be
     * sent whole. Lines never translated come first, then lines whose translation waits for a person,
     * each in the order of the quotation, up to the limit of one request.
     *
     * @return array<string, array{id: int, text: string}> L1, L2 ... => the line's id and its description.
     */
    public function toSend(ClientQuotation $quotation): array
    {
        $waiting = $this->quotations->items($quotation)
            ->filter(fn (ClientQuotationItem $item) => $item->translation_accepted_at === null && self::sendable((string) $item->description))
            ->values();

        $ordered = $waiting->filter(fn (ClientQuotationItem $item) => $item->description_translated === null)
            ->concat($waiting->filter(fn (ClientQuotationItem $item) => $item->description_translated !== null))
            ->take(max(1, (int) config('modules.owner_pack.translation.most_lines')))
            ->values();

        $lines = [];

        foreach ($ordered as $index => $item) {
            $lines['L'.($index + 1)] = ['id' => (int) $item->id, 'text' => (string) $item->description];
        }

        return $lines;
    }

    /**
     * Whether a description can be sent for translation as it is: it has words, it is not longer than
     * one request takes for a line, and nothing in it is a personal detail that would be taken out
     * before sending. A translation of a description with a part missing could not be checked against it.
     */
    public static function sendable(string $description): bool
    {
        $text = trim($description);

        return $text !== ''
            && mb_check_encoding($text, 'UTF-8')
            && preg_match('/\p{L}/u', $text) === 1
            && mb_strlen($text) <= (int) config('modules.owner_pack.translation.description_chars')
            && TextRequest::withoutPersonalDetails($text) === $text;
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
        $translatable = $draft && in_array($quotation->language, self::LANGUAGES, true);
        $lines = $translatable ? $this->toSend($quotation) : [];
        $offered = $lines !== [] && $user->can('agents.use') && $this->spend->aiOn($organization);
        $call = $draft ? $this->latest($quotation) : null;

        if ($call !== null && $call->isOpen()) {
            $call = $this->calls->watch($call);
        }

        $notUsed = null;

        if ($call !== null && in_array($call->status, [AiTextCall::FAILED, AiTextCall::REJECTED], true)
            && ($quotation->updated_at === null || $call->updated_at === null || $call->updated_at->gte($quotation->updated_at))) {
            $notUsed = self::notUsed((string) $call->fail_reason);
        }

        $chars = array_sum(array_map(fn (array $line) => mb_strlen($line['text']) + 40, $lines));

        return [
            'offered' => $offered,
            'plan' => $lines !== [] && ! $organization->allowsFeature('ai_agents') && $this->spend->aiOn(),
            'language' => $draft && ! $translatable,
            'writing' => $call !== null && $call->isOpen(),
            'not_used' => $notUsed,
            'lines' => count($lines),
            'credits' => $offered ? (string) Dec::of($this->calls->upTo(DescriptionTranslation::PURPOSE, $chars))->toScale(0, RoundingMode::Ceiling) : null,
        ];
    }

    /** Why the latest request brought no translation, by the call's fail reason, in the reader's language. Nothing was charged for it. */
    public static function notUsed(string $reason): string
    {
        return match ($reason) {
            'rejected' => __('No translation the AI wrote kept the numbers, grades and ratios of its line, so none was kept. You were not charged.'),
            'subject_changed', 'subject_gone' => __('The quotation changed while the AI was translating, so its translations were not used. You were not charged.'),
            'nothing_to_write' => __('No line was waiting for a translation when the request was sent. You were not charged.'),
            'not_started' => __('The server did not get to it in time, so nothing was sent. Ask again later.'),
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
