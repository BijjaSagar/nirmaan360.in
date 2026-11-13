<?php

namespace App\Services\OwnerPack;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\Procurement\DocumentNumbers;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\Rendered;
use App\Services\Wording\WordingException;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use App\Support\Money;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A quotation for a client: BOQ lines copied as they stand, GST on a percentage typed for this
 * quotation, payment stages, and the step that issues it once a person has ticked every figure.
 *
 * Every figure is worked out here and in QuotationMath. An issued quotation is never changed;
 * revise() starts the next revision under the same number.
 */
class QuotationService
{
    /** The series quotation numbers are drawn from: CQ-2026-27-0001. */
    private const SERIES = 'CQ';

    public function __construct(
        private DocumentNumbers $numbers,
        private QuotationDocument $document,
        private FinalCheck $check,
    ) {}

    public function rules(Project $project): array
    {
        return [
            ...$this->headerRules($project->organization_id),
            'boq_item_ids' => ['required', ...$this->lineRules()],
            'boq_item_ids.*' => $this->lineRule($project->organization_id, $project->id),
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function updateRules(ClientQuotation $quotation): array
    {
        $text = ['nullable', 'string', 'max:'.config('wording.free_slot_max_chars'), $this->validText()];

        return [
            // Only what a form sends is changed, so each field is checked only when it is there.
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], $this->headerRules($quotation->organization_id)),
            'boq_item_ids' => ['sometimes', ...$this->lineRules()],
            'boq_item_ids.*' => $this->lineRule($quotation->organization_id, $quotation->project_id),
            'opening' => $text,
            'closing' => $text,
        ];
    }

    public function stageRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160', $this->validText()],
            'basis' => ['required', Rule::in(ClientPaymentStage::BASES)],
            'percent' => ['nullable', 'required_if:basis,'.ClientPaymentStage::PERCENT, 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'amount' => ['nullable', 'required_if:basis,'.ClientPaymentStage::AMOUNT, 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'condition_text' => ['nullable', 'string', 'max:500', $this->validText()],
            'position' => ['nullable', 'integer', 'min:1', 'max:'.config('modules.owner_pack.max_stages')],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function issueRules(): array
    {
        return [
            'fact_fingerprint' => ['required', 'string', 'size:64'],
            'text_fingerprint' => ['required', 'string', 'size:64'],
            'number_ticks' => ['nullable', 'array', 'max:2000'],
            'number_ticks.*' => ['string', 'max:300'],
        ];
    }

    public function reachedRules(): array
    {
        return [
            'reached' => ['required', 'boolean'],
            'reached_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /** A new draft with a copy of the chosen BOQ lines as they stand now. */
    public function create(Project $project, array $data, User $by): ClientQuotation
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? $this->findByUuid($project->organization_id, $uuid) : null;

        if ($existing = $find()) {
            return $existing;
        }

        $lines = $this->boqLines($project->organization_id, $project->id, $data['boq_item_ids']);

        return Idempotent::create(function () use ($project, $data, $lines, $by, $uuid) {
            $quotation = ClientQuotation::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'number' => $this->numbers->next($project->organization_id, self::SERIES),
                'revision' => 1,
                'status' => ClientQuotation::DRAFT,
                ...$this->header($project->organization_id, $data),
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            $this->copyLines($quotation, $lines);
            $this->recalculate($quotation);

            AuditLog::record('quotation.created', $quotation, null, $this->summary($quotation), $by);

            return $quotation;
        }, $find);
    }

    /** Changes a draft: who it is for, its language, GST percentage and validity, its lines and its two free sentences. */
    public function update(ClientQuotation $quotation, array $data, User $by): ClientQuotation
    {
        return DB::transaction(function () use ($quotation, $data, $by) {
            $quotation = $this->lockedDraft($quotation);
            $before = $this->summary($quotation);

            $quotation->fill($this->header($quotation->organization_id, $data, partial: true));

            if (isset($data['boq_item_ids'])) {
                $lines = $this->boqLines($quotation->organization_id, $quotation->project_id, $data['boq_item_ids']);
                ClientQuotationItem::withoutGlobalScopes()->where('organization_id', $quotation->organization_id)->where('quotation_id', $quotation->id)->delete();
                $this->copyLines($quotation, $lines);
            }

            $sheet = $this->recalculate($quotation);

            try {
                $quotation->slots = $this->document->withTyped($quotation->language, $sheet, $quotation->slots, array_intersect_key($data, array_flip(QuotationDocument::FREE_SLOTS))) ?: null;
            } catch (GuardViolation) {
                throw ValidationException::withMessages(['opening' => __('This text cannot be read. Type it again.')]);
            }

            $quotation->save();

            AuditLog::record('quotation.updated', $quotation, $before, $this->summary($quotation), $by);

            return $quotation;
        });
    }

    /**
     * Issues a draft. The person has ticked every figure on a page that showed the facts and the text
     * with the two fingerprints they send back. The figures are worked out again from the rows and the
     * note is written again; if anything differs from what was shown, or a figure is not ticked,
     * nothing is issued. A later revision takes the reached stages of the one it replaces (carryReached).
     *
     * @throws ValidationException
     */
    public function issue(ClientQuotation $quotation, array $data, User $by): ClientQuotation
    {
        // Issuing is the approval of a document that is nothing but money, by a named person who may see money.
        if (! Money::visible($by)) {
            throw new AuthorizationException(Money::refusal());
        }

        return DB::transaction(function () use ($quotation, $data, $by) {
            $quotation = $this->locked($quotation);

            // A second press of the button, or a retry after a lost answer.
            if ($quotation->isIssued()) {
                return $quotation;
            }

            [$sheet, $document] = $this->checked($quotation, __('The figures of this draft no longer match its lines and stages. Save the draft again and check it.'));

            // The page showed these figures and this text. The same figures in another language, or
            // beside other sentences, are a change too.
            if (! $sheet->matches($data['fact_fingerprint'] ?? null) || ! ShownText::matches($document, $data['text_fingerprint'] ?? null)) {
                throw ValidationException::withMessages(['facts' => __('This draft changed after the page was opened. Check the figures again, then issue it.')]);
            }

            $this->assertIssuable($quotation);
            $this->document->assertTicked($document, $sheet, $data['number_ticks'] ?? []);

            $needed = $this->document->ticksNeeded($document, $sheet);

            $this->carryReached($quotation, $by);

            $quotation->update([
                'status' => ClientQuotation::ISSUED,
                'issued_by' => $by->id,
                'issued_at' => now(),
                'number_ticks' => array_values(array_intersect($needed, $data['number_ticks'] ?? [])),
            ]);

            $this->check->recordTypedNumbers($quotation, $document, $by);
            AuditLog::record('quotation.issued', $quotation, null, [...$this->summary($quotation), 'ticks' => count($needed)], $by);

            return $quotation;
        });
    }

    /** Starts the next revision of an issued quotation as a draft: same number, same lines and stages, free to change. */
    public function revise(ClientQuotation $quotation, User $by, ?string $uuid = null): ClientQuotation
    {
        $organizationId = $quotation->organization_id;
        $find = fn () => ($uuid ? $this->findByUuid($organizationId, $uuid) : null) ?? $this->openRevision($quotation);

        if ($uuid && ($existing = $this->findByUuid($organizationId, $uuid))) {
            return $existing;
        }

        return Idempotent::create(function () use ($quotation, $by, $uuid) {
            $source = $this->locked($quotation);

            if (! $source->isIssued()) {
                throw ValidationException::withMessages(['quotation' => __('This quotation is still a draft. Change it directly.')]);
            }

            $latest = $this->revisions($source)->orderByDesc('revision')->first();

            if ($latest->revision > $source->revision) {
                // The next revision was already started: the person is taken to it.
                if ($latest->isDraft()) {
                    return $latest;
                }

                throw ValidationException::withMessages(['quotation' => __('Revision :n of this quotation is the latest. Revise that one.', ['n' => $latest->revision])]);
            }

            $copy = ClientQuotation::create([
                'organization_id' => $source->organization_id,
                'project_id' => $source->project_id,
                'number' => $source->number,
                'revision' => $source->revision + 1,
                'status' => ClientQuotation::DRAFT,
                'party_id' => $source->party_id,
                'client_name' => $source->client_name,
                'language' => $source->language,
                'gst_pct' => $source->gst_pct,
                // A date that has passed is not carried over: the person types the new one.
                'valid_until' => $source->valid_until?->isPast() && ! $source->valid_until->isToday() ? null : $source->valid_until,
                'slots' => $source->slots,
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            foreach ($this->items($source) as $item) {
                ClientQuotationItem::create([
                    ...$item->only(['organization_id', 'project_id', 'boq_item_id', 'code', 'description', 'unit', 'qty', 'rate', 'amount', 'sort']),
                    'quotation_id' => $copy->id,
                ]);
            }

            // Which stages were reached is not copied. The issued revision stands, and its stages are
            // marked there, until this one is issued; issue() then carries each mark across (carryReached).
            foreach ($this->stages($source) as $stage) {
                ClientPaymentStage::create([
                    ...$stage->only(['organization_id', 'project_id', 'title', 'basis', 'percent', 'amount', 'condition_text', 'sort']),
                    'quotation_id' => $copy->id,
                    'created_by' => $by->id,
                ]);
            }

            $this->recalculate($copy);

            AuditLog::record('quotation.revised', $copy, null, [...$this->summary($copy), 'from_revision' => $source->revision], $by);

            return $copy;
        }, $find);
    }

    public function addStage(ClientQuotation $quotation, array $data, User $by): ClientPaymentStage
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? ClientUuidRepeat::reachable(ClientPaymentStage::withoutGlobalScopes()->where('organization_id', $quotation->organization_id)->where('client_uuid', $uuid)->first()) : null;

        if ($existing = $find()) {
            return $existing;
        }

        return Idempotent::create(function () use ($quotation, $data, $by, $uuid) {
            $quotation = $this->lockedDraft($quotation);
            $stages = $this->stages($quotation);

            if ($stages->count() >= config('modules.owner_pack.max_stages')) {
                throw ValidationException::withMessages(['title' => __('A quotation takes at most :max payment stages.', ['max' => config('modules.owner_pack.max_stages')])]);
            }

            $stage = ClientPaymentStage::create([
                'organization_id' => $quotation->organization_id,
                'project_id' => $quotation->project_id,
                'quotation_id' => $quotation->id,
                ...$this->stageValues($data),
                'sort' => (int) $stages->max('sort') + 1,
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            $this->place($quotation, $stage, $data['position'] ?? null);
            $this->recalculate($quotation);

            return $stage->refresh();
        }, $find);
    }

    public function updateStage(ClientPaymentStage $stage, array $data, User $by): ClientPaymentStage
    {
        return DB::transaction(function () use ($stage, $data) {
            $quotation = $this->lockedDraft($this->quotationOf($stage));

            $stage->update($this->stageValues($data));
            $this->place($quotation, $stage, $data['position'] ?? null);
            $this->recalculate($quotation);

            return $stage->refresh();
        });
    }

    public function removeStage(ClientPaymentStage $stage, User $by): void
    {
        DB::transaction(function () use ($stage) {
            $quotation = $this->lockedDraft($this->quotationOf($stage));

            $stage->delete();
            $this->recalculate($quotation);
        });
    }

    /**
     * Records that a stage of an issued quotation has been reached, or takes that back. While a later
     * revision is a draft this one still stands, so its stages are marked here and carried across when
     * that revision is issued.
     */
    public function markReached(ClientPaymentStage $stage, array $data, User $by): ClientPaymentStage
    {
        return DB::transaction(function () use ($stage, $data, $by) {
            $quotation = $this->locked($this->quotationOf($stage));

            if (! $quotation->isIssued()) {
                throw ValidationException::withMessages(['reached' => __('A stage can be marked as reached once the quotation is issued.')]);
            }

            if ($newer = $this->replacedBy($quotation)) {
                throw ValidationException::withMessages(['reached' => __('This revision was replaced by revision :n. Mark the stage there.', ['n' => $newer])]);
            }

            $stage->update(filter_var($data['reached'], FILTER_VALIDATE_BOOLEAN)
                ? ['reached_on' => $data['reached_on'] ?? today()->toDateString(), 'reached_by' => $by->id]
                : ['reached_on' => null, 'reached_by' => null]);

            return $stage;
        });
    }

    /** The revision number of a later issued revision of the same quotation, or null when this one stands. */
    public function replacedBy(ClientQuotation $quotation): ?int
    {
        $newer = $this->revisions($quotation)
            ->where('status', ClientQuotation::ISSUED)
            ->where('revision', '>', $quotation->revision)
            ->max('revision');

        return $newer === null ? null : (int) $newer;
    }

    /** A draft revision later than this one, when someone has already started it. */
    public function openRevision(ClientQuotation $quotation): ?ClientQuotation
    {
        return $this->revisions($quotation)
            ->where('status', ClientQuotation::DRAFT)
            ->where('revision', '>', $quotation->revision)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * For a draft revision: the issued revision it will replace, and where the stages marked as reached
     * there go when this draft is issued. A mark goes to the stage of this draft that is the same stage:
     * the same name, the same share or typed amount, and the same condition. A stage changed in the
     * draft is issued as not reached, so a mark a person made never stands beside a stage they did not mark.
     *
     * "kept" maps the id of a stage of this draft to the reached stage it takes the mark from; "dropped"
     * lists the reached stages that no stage of this draft is the same as. Null when no earlier revision was issued.
     *
     * @return array{from: ClientQuotation, kept: array<int, ClientPaymentStage>, dropped: list<ClientPaymentStage>}|null
     */
    public function reachedOnEarlier(ClientQuotation $draft): ?array
    {
        $from = $this->earlierIssued($draft);

        if ($from === null) {
            return null;
        }

        [$kept, $dropped] = $this->matchReached($this->stages($from)->load('reachedBy'), $this->stages($draft));

        return ['from' => $from, 'kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * Sets on each stage of a revision being issued whether it was reached, as the revision it replaces
     * says now. Marks made there while this one was a draft come across too, and so do marks taken back.
     */
    private function carryReached(ClientQuotation $quotation, User $by): void
    {
        $from = $this->earlierIssued($quotation);

        if ($from === null) {
            return;
        }

        // Held until this revision is issued: a stage being marked there now is either seen here, or
        // waits and is then refused because this revision replaced it.
        $from = $this->locked($from);
        $stages = $this->stages($quotation);
        [$kept, $dropped] = $this->matchReached($this->stages($from), $stages);

        foreach ($stages as $stage) {
            $mark = $kept[$stage->id] ?? null;
            $stage->fill(['reached_on' => $mark?->reached_on, 'reached_by' => $mark?->reached_by]);

            if ($stage->isDirty()) {
                $stage->save();
            }
        }

        if ($kept !== [] || $dropped !== []) {
            AuditLog::record('quotation.reached_carried', $quotation, null, [
                'from_revision' => $from->revision,
                'carried' => array_values(array_map(fn (ClientPaymentStage $stage) => $stage->title, $kept)),
                'not_carried' => array_map(fn (ClientPaymentStage $stage) => $stage->title, $dropped),
            ], $by);
        }
    }

    /**
     * Pairs the stages of a revision with those of the revision before it, in order, each earlier stage
     * at most once, and keeps the pairs whose earlier stage was reached.
     *
     * @param  Collection<int, ClientPaymentStage>  $earlier
     * @param  Collection<int, ClientPaymentStage>  $stages
     * @return array{0: array<int, ClientPaymentStage>, 1: list<ClientPaymentStage>}
     */
    private function matchReached(Collection $earlier, Collection $stages): array
    {
        $open = $earlier->values()->all();
        $kept = [];

        foreach ($stages as $stage) {
            foreach ($open as $index => $old) {
                if ($this->sameStage($old, $stage)) {
                    unset($open[$index]);

                    if ($old->isReached()) {
                        $kept[$stage->id] = $old;
                    }

                    break;
                }
            }
        }

        return [$kept, array_values(array_filter($open, fn (ClientPaymentStage $old) => $old->isReached()))];
    }

    /**
     * Whether two stages are the same stage of the work. A percentage stage keeps its share when the
     * total changes, so its amount is not compared; a typed amount is.
     */
    private function sameStage(ClientPaymentStage $a, ClientPaymentStage $b): bool
    {
        $text = fn (?string $value) => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)));

        return $text($a->title) === $text($b->title)
            && $a->basis === $b->basis
            && Dec::of($a->isPercent() ? $a->percent : $a->amount)->isEqualTo(Dec::of($b->isPercent() ? $b->percent : $b->amount))
            && $text($a->condition_text) === $text($b->condition_text);
    }

    /** The latest issued revision before this one under the same number, or null. */
    private function earlierIssued(ClientQuotation $quotation): ?ClientQuotation
    {
        return $this->revisions($quotation)
            ->where('status', ClientQuotation::ISSUED)
            ->where('revision', '<', $quotation->revision)
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * The facts and the cover note of a quotation, after checking that its stored figures are the
     * ones its lines, percentage and stages give, and that they are the facts kept with it.
     *
     * @return array{0: FactSheet, 1: Rendered}
     *
     * @throws ValidationException With the key "facts".
     */
    public function checked(ClientQuotation $quotation, string $message): array
    {
        $quotation->setRelation('items', $this->items($quotation));
        $quotation->setRelation('stages', $this->stages($quotation));

        try {
            $drifted = $this->applyFigures($quotation);
        } catch (ValidationException) {
            $drifted = true;
        }

        $sheet = $this->document->sheet($quotation, $quotation->items, $quotation->stages);

        if ($drifted || ! $sheet->matches($quotation->fact_fingerprint)) {
            throw ValidationException::withMessages(['facts' => $message]);
        }

        return [$sheet, $this->document->render($quotation->language, $sheet, $quotation->slots)];
    }

    /**
     * What the review screen shows for a quotation: the facts kept with it and the cover note made from
     * them. The note is null when the kept facts no longer fit the document, which a save of the draft puts right.
     *
     * @return array{0: FactSheet, 1: ?Rendered}
     */
    public function review(ClientQuotation $quotation): array
    {
        try {
            $sheet = FactSheet::fromArray($quotation->facts ?? []);

            return [$sheet, $this->document->render($quotation->language, $sheet, $quotation->slots)];
        } catch (WordingException|InvalidArgumentException $e) {
            report($e);

            return [new FactSheet, null];
        }
    }

    /** @return Collection<int, ClientQuotationItem> */
    public function items(ClientQuotation $quotation): Collection
    {
        return ClientQuotationItem::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->where('project_id', $quotation->project_id)
            ->where('quotation_id', $quotation->id)
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /** @return Collection<int, ClientPaymentStage> */
    public function stages(ClientQuotation $quotation): Collection
    {
        return ClientPaymentStage::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->where('project_id', $quotation->project_id)
            ->where('quotation_id', $quotation->id)
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /**
     * Works every figure out again from the lines, the percentage and the stages, saves what changed,
     * and keeps the facts and their fingerprint with the quotation.
     *
     * @throws ValidationException When the stages no longer fit the total.
     */
    private function recalculate(ClientQuotation $quotation): FactSheet
    {
        $quotation->setRelation('items', $this->items($quotation));
        $quotation->setRelation('stages', $this->stages($quotation));

        $this->applyFigures($quotation);

        foreach ([...$quotation->items, ...$quotation->stages] as $row) {
            if ($row->isDirty()) {
                $row->save();
            }
        }

        $sheet = $this->document->sheet($quotation, $quotation->items, $quotation->stages);

        $quotation->facts = $sheet->toArray();
        $quotation->fact_fingerprint = $sheet->fingerprint();
        $quotation->save();

        return $sheet;
    }

    /**
     * Sets every worked-out figure on the quotation, its lines and its stages, without saving.
     *
     * @return bool Whether any stored figure differed from the one worked out.
     */
    private function applyFigures(ClientQuotation $quotation): bool
    {
        $drifted = false;

        foreach ($quotation->items as $item) {
            $item->amount = QuotationMath::lineAmount($item->qty, $item->rate);
            $drifted = $drifted || $item->isDirty('amount');
        }

        $totals = QuotationMath::totals($quotation->items->pluck('amount'), $quotation->gst_pct);
        $quotation->fill($totals);
        $drifted = $drifted || $quotation->isDirty(array_keys($totals));

        $amounts = QuotationMath::stageAmounts($quotation->total, $quotation->stages->map(fn (ClientPaymentStage $stage) => [
            'basis' => $stage->basis, 'percent' => $stage->percent, 'amount' => $stage->amount,
        ])->all());

        foreach ($quotation->stages->values() as $index => $stage) {
            $stage->amount = $amounts[$index];
            $drifted = $drifted || $stage->isDirty('amount');
        }

        return $drifted;
    }

    /** @throws ValidationException */
    private function assertIssuable(ClientQuotation $quotation): void
    {
        if ($quotation->items->isEmpty() || ! Dec::of($quotation->total)->isPositive()) {
            throw ValidationException::withMessages(['boq_item_ids' => __('This quotation has no amount yet. Choose BOQ lines that have a quantity and a rate.')]);
        }

        if ($quotation->valid_until !== null && $quotation->valid_until->isPast() && ! $quotation->valid_until->isToday()) {
            throw ValidationException::withMessages(['valid_until' => __('The validity date has passed. Change it before you issue the quotation.')]);
        }

        $stages = $quotation->stages->map(fn (ClientPaymentStage $stage) => ['basis' => $stage->basis, 'percent' => $stage->percent])->all();

        if (QuotationMath::allPercent($stages) && ! QuotationMath::percentTotal($stages)->isEqualTo(100)) {
            throw ValidationException::withMessages(['percent' => __('When every stage is a percentage, the percentages must add up to the whole of the total. They now add up to :sum.', ['sum' => QuotationMath::plain(QuotationMath::percentTotal($stages))])]);
        }
    }

    /** Moves a stage to a place in the order, when one was asked for, and numbers the stages again. */
    private function place(ClientQuotation $quotation, ClientPaymentStage $stage, mixed $position): void
    {
        if ($position === null || $position === '') {
            return;
        }

        $others = $this->stages($quotation)->reject(fn (ClientPaymentStage $other) => $other->is($stage))->values();
        $others->splice(max(0, min((int) $position - 1, $others->count())), 0, [$stage]);

        foreach ($others->values() as $index => $row) {
            if ((int) $row->sort !== $index + 1) {
                $row->update(['sort' => $index + 1]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function stageValues(array $data): array
    {
        $percent = $data['basis'] === ClientPaymentStage::PERCENT;

        return [
            'title' => trim($data['title']),
            'basis' => $data['basis'],
            'percent' => $percent ? $data['percent'] : null,
            // A percentage stage's amount is worked out from the total straight after this, so it is not set here.
            ...($percent ? [] : ['amount' => Dec::money($data['amount'])]),
            'condition_text' => trim((string) ($data['condition_text'] ?? '')) ?: null,
        ];
    }

    /**
     * The header values a request carries. A new quotation takes every field; a change to a draft
     * takes only the fields that were sent, so one form can change the lines and leave the rest alone.
     *
     * @return array<string, mixed>
     */
    private function header(int $organizationId, array $data, bool $partial = false): array
    {
        $sent = fn (string $field) => ! $partial || array_key_exists($field, $data);
        $values = [];
        $party = null;

        if ($sent('party_id')) {
            $party = empty($data['party_id']) ? null : Party::withoutGlobalScopes()->where('organization_id', $organizationId)->find($data['party_id']);
            $values['party_id'] = $party?->id;
        }

        if ($sent('client_name')) {
            $name = trim((string) ($data['client_name'] ?? '')) ?: trim((string) $party?->name);

            if ($name === '') {
                throw ValidationException::withMessages(['client_name' => __('Type the name of the client this quotation is for.')]);
            }

            $values['client_name'] = $name;
        }

        if ($sent('language')) {
            $values['language'] = $data['language'];
        }

        if ($sent('gst_pct')) {
            $values['gst_pct'] = ($data['gst_pct'] ?? null) === null || $data['gst_pct'] === '' ? null : $data['gst_pct'];
        }

        if ($sent('valid_until')) {
            $values['valid_until'] = $data['valid_until'] ?? null;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function headerRules(int $organizationId): array
    {
        return [
            'party_id' => ['bail', 'nullable', 'integer', Rule::exists('parties', 'id')->where('organization_id', $organizationId)->where('type', 'client')->whereNull('deleted_at')],
            'client_name' => ['nullable', 'required_without:party_id', 'string', 'max:160', $this->validText()],
            'language' => ['required', Rule::in(ClientQuotation::LANGUAGES)],
            // Typed for this quotation. Left empty, the amounts are quoted without GST.
            'gst_pct' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /** @return list<mixed> */
    private function lineRules(): array
    {
        return ['array', 'min:1', 'max:'.config('modules.owner_pack.max_lines')];
    }

    /** @return list<mixed> */
    private function lineRule(int $organizationId, int $projectId): array
    {
        return ['bail', 'integer', 'distinct', Rule::exists('boq_items', 'id')->where('organization_id', $organizationId)->where('project_id', $projectId)->whereNull('deleted_at')];
    }

    /** Text that would break the document or the json column it is kept in is refused at the door. */
    private function validText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail(__('This text cannot be read. Type it again.'));
            }
        };
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, BoqItem> In BOQ order.
     */
    private function boqLines(int $organizationId, int $projectId, array $ids): Collection
    {
        return BoqItem::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /** @param  Collection<int, BoqItem>  $lines */
    private function copyLines(ClientQuotation $quotation, Collection $lines): void
    {
        foreach ($lines->values() as $index => $line) {
            ClientQuotationItem::create([
                'organization_id' => $quotation->organization_id,
                'project_id' => $quotation->project_id,
                'quotation_id' => $quotation->id,
                'boq_item_id' => $line->id,
                'code' => $line->code,
                'description' => $line->description,
                'unit' => $line->unit,
                'qty' => $line->qty,
                'rate' => $line->rate,
                'amount' => $line->amount(),
                'sort' => $index + 1,
            ]);
        }
    }

    /** The row as it is now, held until the transaction ends. The caller reached it through the tenant scopes. */
    private function locked(ClientQuotation $quotation): ClientQuotation
    {
        return ClientQuotation::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->whereKey($quotation->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedDraft(ClientQuotation $quotation): ClientQuotation
    {
        $quotation = $this->locked($quotation);

        if (! $quotation->isDraft()) {
            throw new ImmutableRecordException(ClientQuotation::lockedMessage());
        }

        return $quotation;
    }

    private function quotationOf(ClientPaymentStage $stage): ClientQuotation
    {
        return ClientQuotation::withoutGlobalScopes()
            ->where('organization_id', $stage->organization_id)
            ->whereKey($stage->quotation_id)
            ->firstOrFail();
    }

    private function revisions(ClientQuotation $quotation)
    {
        return ClientQuotation::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->where('project_id', $quotation->project_id)
            ->where('number', $quotation->number);
    }

    private function findByUuid(int $organizationId, string $uuid): ?ClientQuotation
    {
        return ClientUuidRepeat::reachable(ClientQuotation::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    /** What the audit trail keeps about a quotation: who and how much, never the share token or the whole fact sheet. */
    private function summary(ClientQuotation $quotation): array
    {
        return [
            'number' => $quotation->number,
            'revision' => $quotation->revision,
            'status' => $quotation->status,
            'client_name' => $quotation->client_name,
            'language' => $quotation->language,
            'gst_pct' => $quotation->gst_pct,
            'valid_until' => $quotation->valid_until?->toDateString(),
            'subtotal' => $quotation->subtotal,
            'gst_amount' => $quotation->gst_amount,
            'total' => $quotation->total,
            'fact_fingerprint' => $quotation->fact_fingerprint,
        ];
    }
}
