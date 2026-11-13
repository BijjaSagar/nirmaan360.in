<?php

namespace App\Services\BillCheck\Explain;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Models\BillCheckExplanation;
use App\Models\Organization;
use App\Models\User;
use App\Services\BillCheck\Flag;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCalls;
use App\Services\Wording\FactFormat;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\RenderedSlot;
use App\Services\Wording\Renderer;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotText;
use App\Services\Wording\WordingException;
use App\Services\Wording\WordingService;
use App\Support\Idempotent;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The findings of a pre-check explained in plain words, in English, Hindi or Marathi.
 *
 * Every explanation is complete without a model: each topic has the app's own sentence, and each
 * finding is the catalogue's sentence with its figures placed by the code. Asking for it with AI
 * makes one text call that may replace the topic sentences with a model's, and only with sentences
 * that passed every guard. The model never sees a figure, and never adds, removes or reorders a
 * finding: the findings and their order are the check's.
 *
 * The explanation is for the people of the company. It follows the reader's own language unless
 * they pick another, and it is not one of the documents for a client whose Hindi and Marathi wait
 * for review (App\Services\Wording\ReviewedLanguages).
 */
class BillCheckExplanations
{
    public function __construct(
        private ExplanationDocument $documents,
        private Renderer $renderer,
        private TextCalls $calls,
        private AiSpend $spend,
    ) {}

    /** The language an explanation is shown in when none was picked: the reader's own, if the app writes it. */
    public static function locale(?string $asked): string
    {
        foreach ([$asked, app()->getLocale()] as $locale) {
            if (is_string($locale) && in_array($locale, FactFormat::LOCALES, true)) {
                return $locale;
            }
        }

        return 'en';
    }

    /**
     * Has a model write the topic sentences of the explanation in this language. Returns the
     * explanation, its call and whether a call was made now. When the explanation in this language
     * already stands in a model's words nothing is asked again, and nothing is charged.
     *
     * @return array{0: BillCheckExplanation, 1: ?AiTextCall, 2: bool}
     *
     * @throws ValidationException The check found nothing, so there is nothing to explain; or a client_uuid of another call.
     * @throws AuthorizationException
     * @throws ReadRefused
     * @throws SpendRefused
     */
    public function ask(BillCheck $check, string $locale, User $by, ?string $clientUuid = null): array
    {
        $document = $this->documents->forModel($check, FactFormat::locale($locale));

        if ($document === null || $document->type->freeSlots() === []) {
            throw ValidationException::withMessages(['check' => __('These checks found nothing on this bill, so there is nothing to explain.')]);
        }

        $explanation = $this->row($check, $locale, $by);

        if ($explanation->source === BillCheckExplanation::AI) {
            return [$explanation, $this->latestCall($explanation), false];
        }

        $call = app(WordingService::class)->draft(WordingService::PURPOSE, $document->type, $document->sheet, $locale, $explanation, $by, ['client_uuid' => $clientUuid]);

        if ($call->wasRecentlyCreated) {
            AuditLog::record('bill_check.explanation_asked', $explanation, null, [
                'bill_check_id' => $check->id, 'locale' => $locale, 'ai_text_call_id' => $call->id,
            ], $by);
        }

        return [$explanation->refresh(), $call, $call->wasRecentlyCreated];
    }

    /**
     * The explanation of a check as this reader may read it. A reader without money.view gets every
     * finding in the sentence that names no rupee figure, no contract percentage and no words of a
     * contract term; the topic sentences are the same for everyone.
     *
     * @param  bool  $tend  Look after a call that is still open, as a status request does. A page view does not.
     * @return array{check_id: int, locale: string, status: string, source: string, call_id: ?int, paragraphs: list<array<string, mixed>>, can: array{ask: bool}, credits: ?string}
     */
    public function present(BillCheck $check, string $locale, ?User $viewer, bool $tend = true): array
    {
        $locale = FactFormat::locale($locale);
        $explanation = $this->find($check, $locale);
        $call = $explanation === null ? null : $this->latestCall($explanation);

        if ($tend && $call?->isOpen()) {
            $call = $this->calls->watch($call);
            $explanation->refresh();
        }

        $drafting = (bool) $call?->isOpen();
        [$paragraphs, $source, $askable] = $this->paragraphs($check, $locale, $viewer, $explanation);
        $mayAsk = $askable && ! $drafting && $source !== BillCheckExplanation::AI && $this->mayAsk($viewer);

        return [
            'check_id' => $check->id,
            'locale' => $locale,
            'status' => $drafting ? 'drafting' : 'done',
            'source' => $source,
            'call_id' => $call?->id,
            'paragraphs' => $paragraphs,
            'can' => ['ask' => $mayAsk],
            'credits' => $mayAsk ? $this->calls->upTo(WordingService::PURPOSE) : null,
        ];
    }

    /**
     * The explanation for the card on the bill page, which the card has already found the check for.
     * Null when the check found nothing. The bill page never fails because of it: a fault is reported
     * and the card is shown without the explanation.
     *
     * @return array<string, mixed>|null
     */
    public function forCard(int $checkId, ?User $viewer, ?string $asked): ?array
    {
        try {
            $check = BillCheck::withoutGlobalScopes()->where('organization_id', $viewer?->organization_id)->find($checkId);

            if ($check === null || $this->documents->flags($check) === []) {
                return null;
            }

            return [...$this->present($check, self::locale($asked), $viewer, tend: false), 'plan_stops' => $this->planStops($viewer)];
        } catch (Throwable $fault) {
            report($fault);

            return null;
        }
    }

    /** Whether this person's company has the AI wording but its plan stops them, for the line that says which plans have it. */
    public function planStops(?User $viewer): bool
    {
        $organization = $viewer === null ? null : Organization::find($viewer->organization_id);

        return $organization !== null && $this->spend->aiOn() && ! $organization->allowsFeature('ai_agents');
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string, 2: bool} The paragraphs, where the topic sentences come from, and whether a model could write any.
     */
    private function paragraphs(BillCheck $check, string $locale, ?User $viewer, ?BillCheckExplanation $explanation): array
    {
        $document = $this->documents->build($check, $locale, Money::visible($viewer));

        if ($document === null) {
            return [[], BillCheckExplanation::TEMPLATE, false];
        }

        $flags = $this->documents->flags($check);

        try {
            $rendered = $this->renderer->render($document->type, $document->sheet, $locale, (array) ($explanation?->slots ?? []));
        } catch (WordingException|GuardViolation $broken) {
            // A sentence of the catalogue that does not fit the rules is a fault in the code; the reader gets the app's own words.
            report($broken);
            $rendered = $this->renderer->render($document->type, $document->sheet, $locale);
        }

        $paragraphs = [];
        $about = null;
        $fromModel = false;

        foreach ($rendered->slots as $slot) {
            $number = ExplanationDocument::findingNumber($slot->id);

            if ($number === null) {
                $about = $slot->text;
                $fromModel = $fromModel || ($slot->type === Slot::FREE && $slot->source === SlotText::AI);

                continue;
            }

            $paragraphs[] = $this->paragraph($flags[$number - 1] ?? null, $slot, $about);
            $about = null;
        }

        return [$paragraphs, $fromModel ? BillCheckExplanation::AI : BillCheckExplanation::TEMPLATE, $document->type->freeSlots() !== []];
    }

    /** @return array{flag_key: ?string, code: ?string, level: ?string, about: ?string, finding: string, text: string} */
    private function paragraph(?Flag $flag, RenderedSlot $slot, ?string $about): array
    {
        return [
            'flag_key' => $flag?->key,
            'code' => $flag?->code,
            'level' => $flag?->level,
            'about' => $about,
            'finding' => $slot->text,
            'text' => $about === null ? $slot->text : $about."\n".$slot->text,
        ];
    }

    private function mayAsk(?User $viewer): bool
    {
        if ($viewer === null || ! $viewer->can('agents.use') || ! $viewer->can('ra_bill.generate')) {
            return false;
        }

        $organization = Organization::find($viewer->organization_id);

        return $organization !== null && $this->spend->aiOn($organization);
    }

    private function find(BillCheck $check, string $locale): ?BillCheckExplanation
    {
        return BillCheckExplanation::withoutGlobalScopes()
            ->where('organization_id', $check->organization_id)
            ->where('bill_check_id', $check->id)
            ->where('locale', $locale)
            ->first();
    }

    private function row(BillCheck $check, string $locale, User $by): BillCheckExplanation
    {
        return $this->find($check, $locale) ?? Idempotent::create(
            fn () => BillCheckExplanation::create([
                'organization_id' => $check->organization_id,
                'project_id' => $check->project_id,
                'bill_check_id' => $check->id,
                'locale' => $locale,
                'source' => BillCheckExplanation::TEMPLATE,
                'requested_by' => $by->id,
            ]),
            fn () => $this->find($check, $locale),
        );
    }

    /** The newest text call about this explanation: the one open now, or the one that wrote it. */
    private function latestCall(BillCheckExplanation $explanation): ?AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()
            ->where('organization_id', $explanation->organization_id)
            ->where('purpose', WordingService::PURPOSE)
            ->where('subject_type', $explanation->getMorphClass())
            ->where('subject_id', $explanation->id)
            ->orderByDesc('id')
            ->first();
    }
}
