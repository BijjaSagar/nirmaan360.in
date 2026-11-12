<?php

namespace App\Services\OwnerPack;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\ClientQuotation;
use App\Models\DprPhoto;
use App\Models\OwnerUpdate;
use App\Models\Project;
use App\Models\User;
use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\Rendered;
use App\Services\Wording\TypedNumber;
use App\Services\Wording\WordingException;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use App\Support\Money;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The weekly update for a client: a draft made from the week's approved daily reports, the photos
 * a person picks, and, for someone who may see money, the payment position on an issued quotation.
 *
 * Every figure is read by WeekFacts each time the draft is saved. Approving reads the records once
 * more: an update is approved only on the figures the person ticked, and never changes afterwards.
 * Nothing is sent from here. A person shares the link himself (OwnerUpdateShareService).
 */
class OwnerUpdateService
{
    public function __construct(
        private WeekFacts $facts,
        private OwnerUpdateDocument $document,
        private QuotationService $quotations,
        private FinalCheck $check,
        private SharedPhoto $photos,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'week_start' => ['required', 'date', 'before_or_equal:today'],
            'language' => ['required', Rule::in(OwnerUpdate::LANGUAGES)],
            ...$this->optionalRules($project->organization_id, $project->id),
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function updateRules(OwnerUpdate $update): array
    {
        $text = ['nullable', 'string', 'max:'.config('wording.free_slot_max_chars'), $this->validText()];

        return [
            // Only what a form sends is changed, so each field is checked only when it is there.
            'week_start' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'language' => ['sometimes', 'required', Rule::in(OwnerUpdate::LANGUAGES)],
            ...$this->optionalRules($update->organization_id, $update->project_id),
            'opening' => $text,
            'closing' => $text,
        ];
    }

    public function approveRules(): array
    {
        return [
            'fact_fingerprint' => ['required', 'string', 'size:64'],
            'number_ticks' => ['nullable', 'array', 'max:2000'],
            'number_ticks.*' => ['string', 'max:300'],
        ];
    }

    /** A new draft for a week, with its figures read from the records as they stand today. */
    public function create(Project $project, array $data, User $by): OwnerUpdate
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? $this->findByUuid($project->organization_id, $uuid) : null;

        if ($existing = $find()) {
            return $existing;
        }

        $money = $this->wantsMoney($data, $by);
        [$from, $to] = $this->week($data['week_start']);
        $quotation = $money ? $this->standingQuotation($project->organization_id, $project->id, $data['quotation_id'] ?? null) : null;
        $photos = $this->pickedPhotos($project->organization_id, $project->id, $from, $to, $data['photo_ids'] ?? []);

        return Idempotent::create(function () use ($project, $data, $by, $uuid, $money, $from, $to, $quotation, $photos) {
            $update = new OwnerUpdate([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'quotation_id' => $quotation?->id,
                'week_start' => $from,
                'week_end' => $to,
                'language' => $data['language'],
                'status' => OwnerUpdate::DRAFT,
                'show_money' => $money,
                'received_amount' => $money ? $this->typedAmount($data['received_amount'] ?? null) : null,
                'photo_ids' => $photos,
                'next_week_text' => $this->typedText($data['next_week_text'] ?? null),
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            $update->save();
            $this->readRecords($update);

            AuditLog::record('owner_update.created', $update, null, $this->summary($update), $by);

            return $update;
        }, $find);
    }

    /**
     * Changes a draft. Only the fields that were sent change, and every save reads the records again,
     * so "Save draft" is also how a draft catches up with reports approved since.
     */
    public function update(OwnerUpdate $update, array $data, User $by): OwnerUpdate
    {
        // The payment position is money. Someone who may not see it neither opens nor changes such an update.
        $this->assertMayRead($update, $by);

        return DB::transaction(function () use ($update, $data, $by) {
            $update = $this->lockedDraft($update);
            $this->assertMayRead($update, $by);
            $before = $this->summary($update);
            $sent = fn (string $field) => array_key_exists($field, $data);

            if ($sent('week_start')) {
                [$update->week_start, $update->week_end] = $this->week($data['week_start']);
            }

            if ($sent('language')) {
                $update->language = $data['language'];
            }

            if ($sent('show_money')) {
                $update->show_money = $this->wantsMoney($data, $by);
            }

            if (! $update->show_money) {
                $update->quotation_id = null;
                $update->received_amount = null;
            } else {
                if ($sent('quotation_id') || $sent('show_money')) {
                    // A quotation sent empty is a quotation not chosen; only a form that did not send the field keeps the one chosen before.
                    $update->quotation_id = $this->standingQuotation($update->organization_id, $update->project_id, $sent('quotation_id') ? $data['quotation_id'] : $update->quotation_id)->id;
                }

                if ($sent('received_amount')) {
                    $update->received_amount = $this->typedAmount($data['received_amount']);
                }
            }

            if ($sent('next_week_text')) {
                $update->next_week_text = $this->typedText($data['next_week_text']);
            }

            [$from, $to] = [$update->week_start->toDateString(), $update->week_end->toDateString()];

            // Photos a form sent are checked one by one. Photos already kept are dropped quietly once
            // they no longer belong to an approved report of the week, for example after the week was changed.
            $update->photo_ids = $sent('photo_ids')
                ? $this->pickedPhotos($update->organization_id, $update->project_id, $from, $to, $data['photo_ids'] ?? [])
                : array_values(array_intersect($update->photoIds(), $this->weekPhotos($update->organization_id, $update->project_id, $from, $to)->keys()->all()));

            $sheet = $this->readRecords($update);

            try {
                $update->slots = $this->document->withTyped($update->language, $sheet, $update->slots, array_intersect_key($data, array_flip(OwnerUpdateDocument::FREE_SLOTS))) ?: null;
                // Rendered once here, so text that cannot be printed is refused when it is typed, not when it is approved.
                $this->document->render($update->language, $sheet, $update->slots, $update->next_week_text);
            } catch (GuardViolation) {
                throw ValidationException::withMessages(['opening' => __('This text cannot be read. Type it again.')]);
            }

            $update->save();

            AuditLog::record('owner_update.updated', $update, $before, $this->summary($update), $by);

            return $update;
        });
    }

    /**
     * Approves a draft. The person has ticked every figure on a page that showed the facts with the
     * fingerprint they send back. The records are read again; if anything differs from what was
     * shown, or a figure is not ticked, nothing is approved.
     *
     * @throws ValidationException
     */
    public function approve(OwnerUpdate $update, array $data, User $by): OwnerUpdate
    {
        // Nothing reaches a client that a named person who may see money has not approved.
        if (! Money::visible($by)) {
            throw new AuthorizationException(Money::refusal());
        }

        return DB::transaction(function () use ($update, $data, $by) {
            $update = $this->locked($update);

            // A second press of the button, or a retry after a lost answer.
            if ($update->isApproved()) {
                return $update;
            }

            [$sheet, $document] = $this->checked($update, __('The records behind this draft have changed since it was prepared. Save the draft to read them again, then check it.'));

            if (! $sheet->matches($data['fact_fingerprint'] ?? null)) {
                throw ValidationException::withMessages(['facts' => __('This draft changed after the page was opened. Check the figures again, then approve it.')]);
            }

            if ($sheet->get('report_count')->value === '0' && ! $sheet->has('pay_number')) {
                throw ValidationException::withMessages(['approve' => __('No daily report of this week is approved yet, so there is nothing to send. Approve the reports first, or choose another week.')]);
            }

            $this->document->assertTicked($document, $sheet, $data['number_ticks'] ?? []);

            $needed = $this->document->ticksNeeded($document, $sheet);

            $update->update([
                'status' => OwnerUpdate::APPROVED,
                'approved_by' => $by->id,
                'approved_at' => now(),
                'number_ticks' => array_values(array_intersect($needed, $data['number_ticks'] ?? [])),
                'typed_numbers' => array_map(fn (TypedNumber $number) => $number->toArray(), $document->typedNumbers()),
                'body_rendered' => $this->document->body($document),
            ]);

            $this->check->recordTypedNumbers($update, $document, $by);
            AuditLog::record('owner_update.approved', $update, null, [...$this->summary($update), 'ticks' => count($needed)], $by);

            return $update;
        });
    }

    /**
     * The facts and the text of an update, after reading the records again and checking that they
     * still give the facts kept with it. This is the check that runs at approval and at every share.
     *
     * @return array{0: FactSheet, 1: Rendered}
     *
     * @throws ValidationException With the key "facts".
     */
    public function checked(OwnerUpdate $update, string $message): array
    {
        $refused = ValidationException::withMessages(['facts' => $message]);

        try {
            $sheet = $this->facts->sheet($update);
        } catch (InvalidArgumentException $e) {
            report($e);

            throw $refused;
        }

        if (! $sheet->matches($update->fact_fingerprint)) {
            throw $refused;
        }

        if ($update->show_money) {
            // A payment position is stated only on a quotation that still stands.
            $quotation = $update->quotation_id === null ? null : $this->quotation($update->organization_id, $update->project_id, $update->quotation_id);
            $newer = $quotation === null ? null : $this->quotations->replacedBy($quotation);

            if ($quotation === null || ! $sheet->has('pay_number')) {
                throw ValidationException::withMessages(['facts' => __('The quotation this update refers to is no longer an issued quotation of this project. Prepare a new update.')]);
            }

            if ($newer !== null) {
                throw ValidationException::withMessages(['facts' => __('The quotation this update refers to was replaced by revision :n. Prepare a new update on that revision.', ['n' => $newer])]);
            }
        }

        try {
            return [$sheet, $this->document->render($update->language, $sheet, $update->slots, $update->next_week_text)];
        } catch (WordingException|GuardViolation $e) {
            report($e);

            throw $refused;
        }
    }

    /**
     * What the review screen shows for a draft: the facts kept with it and the text made from them.
     * The text is null when the kept facts no longer fit the document, which a save of the draft puts right.
     *
     * @return array{0: FactSheet, 1: ?Rendered}
     */
    public function review(OwnerUpdate $update): array
    {
        try {
            $sheet = FactSheet::fromArray($update->facts ?? []);

            return [$sheet, $this->document->render($update->language, $sheet, $update->slots, $update->next_week_text)];
        } catch (WordingException|GuardViolation|InvalidArgumentException $e) {
            report($e);

            return [new FactSheet, null];
        }
    }

    /** The facts kept with an update, or an empty sheet when they cannot be read back. */
    public function kept(OwnerUpdate $update): FactSheet
    {
        try {
            return FactSheet::fromArray($update->facts ?? []);
        } catch (InvalidArgumentException $e) {
            report($e);

            return new FactSheet;
        }
    }

    /** Whether the records still give the facts kept with the update. */
    public function isCurrent(OwnerUpdate $update): bool
    {
        try {
            return $this->facts->sheet($update)->matches($update->fact_fingerprint);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * An update that states a payment position is nothing a person without money.view may open or change.
     *
     * @throws AuthorizationException
     */
    public function assertMayRead(OwnerUpdate $update, ?User $by): void
    {
        if ($update->show_money && ! Money::visible($by)) {
            throw new AuthorizationException(Money::refusal());
        }
    }

    /**
     * The issued quotations of a project that no later issued revision has replaced: the ones a
     * payment position may be stated on.
     *
     * @return Collection<int, ClientQuotation>
     */
    public function standingQuotations(int $organizationId, int $projectId): Collection
    {
        return ClientQuotation::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', ClientQuotation::ISSUED)
            ->orderBy('number')->orderByDesc('revision')
            ->get()
            // The latest issued revision under each number.
            ->groupBy('number')
            ->map(fn (Collection $revisions) => $revisions->first())
            ->values();
    }

    /**
     * The photos an update for this week may carry: those of the week's approved daily reports.
     *
     * @return Collection<int, DprPhoto> Keyed by id, each with report_date set from its report.
     */
    public function weekPhotos(int $organizationId, int $projectId, string $from, string $to): Collection
    {
        $reports = $this->facts->approvedReports($organizationId, $projectId, $from, $to);

        return $this->facts->photosOf($organizationId, $reports)
            ->each(fn (DprPhoto $photo) => $photo->setAttribute('report_date', $reports[$photo->dpr_id]->report_date));
    }

    /**
     * Reads the records, stamps the day, and keeps the facts and their fingerprint with the update.
     * The caller saves anything else it changed on the row before or after; this saves the row.
     */
    private function readRecords(OwnerUpdate $update): FactSheet
    {
        $update->prepared_on = today();

        try {
            $sheet = $this->facts->sheet($update);
        } catch (InvalidArgumentException $e) {
            report($e);

            throw ValidationException::withMessages(['week_start' => __('The records of this week cannot be read into an update. Check the daily reports of the week.')]);
        }

        $update->facts = $sheet->toArray();
        $update->fact_fingerprint = $sheet->fingerprint();
        $update->save();

        return $sheet;
    }

    /**
     * Whether the request asks for the payment position. Only someone who may see money may ask.
     *
     * @throws AuthorizationException
     */
    private function wantsMoney(array $data, User $by): bool
    {
        $wanted = filter_var($data['show_money'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($wanted && ! Money::visible($by)) {
            throw new AuthorizationException(Money::refusal());
        }

        return $wanted;
    }

    /** @return array{0: string, 1: string} The first and the last day: seven days in all. */
    private function week(string $start): array
    {
        $from = Carbon::parse($start)->startOfDay();

        return [$from->toDateString(), $from->copy()->addDays(6)->toDateString()];
    }

    /**
     * The issued quotation a payment position is stated on. It must belong to the project and must
     * not have been replaced by a later issued revision.
     *
     * @throws ValidationException
     */
    private function standingQuotation(int $organizationId, int $projectId, mixed $id): ClientQuotation
    {
        if ($id === null || $id === '') {
            throw ValidationException::withMessages(['quotation_id' => __('Choose the issued quotation whose payment position this update states.')]);
        }

        $quotation = $this->quotation($organizationId, $projectId, (int) $id);

        if ($quotation === null) {
            throw ValidationException::withMessages(['quotation_id' => __('Choose the issued quotation whose payment position this update states.')]);
        }

        if ($newer = $this->quotations->replacedBy($quotation)) {
            throw ValidationException::withMessages(['quotation_id' => __('This revision was replaced by revision :n. Choose that revision.', ['n' => $newer])]);
        }

        return $quotation;
    }

    private function quotation(int $organizationId, int $projectId, int $id): ?ClientQuotation
    {
        return ClientQuotation::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', ClientQuotation::ISSUED)
            ->whereKey($id)
            ->first();
    }

    /**
     * The photo ids a form sent, checked against the week: each must be a photo of an approved daily
     * report of this project that falls inside the week. Kept in the order of the reports.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function pickedPhotos(int $organizationId, int $projectId, string $from, string $to, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $week = $this->weekPhotos($organizationId, $projectId, $from, $to);
        $allowed = $week->keys()->all();

        if (array_diff($ids, $allowed) !== []) {
            throw ValidationException::withMessages(['photo_ids' => __('Pick photos only from the approved daily reports of this week.')]);
        }

        $picked = array_values(array_intersect($allowed, $ids));

        // A client is sent a photo drawn again by the server, never the stored file. A file that cannot
        // be drawn again would leave a gap on the client's page, so it is refused here, where a person can see why.
        $unsendable = array_values(array_filter($picked, fn (int $id) => ! $this->photos->sendable((string) $week[$id]->path)));

        if ($unsendable !== []) {
            throw ValidationException::withMessages(['photo_ids' => __('These photos cannot be sent to a client, because the file is missing, is not a picture the app can open, or is too large: :photos. Pick other photos.', [
                'photos' => implode('; ', array_map(fn (int $id) => trim(FactFormat::display(Fact::DATE, $week[$id]->report_date->toDateString(), app()->getLocale()).' '.$week[$id]->caption), $unsendable)),
            ])]);
        }

        return $picked;
    }

    private function typedAmount(mixed $amount): ?string
    {
        return $amount === null || $amount === '' ? null : Dec::money($amount);
    }

    private function typedText(mixed $text): ?string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $text)) ?: null;
    }

    /** @return array<string, mixed> */
    private function optionalRules(int $organizationId, int $projectId): array
    {
        return [
            'show_money' => ['sometimes', 'nullable', 'boolean'],
            'quotation_id' => ['bail', 'sometimes', 'nullable', 'integer', Rule::exists('client_quotations', 'id')->where('organization_id', $organizationId)->where('project_id', $projectId)->where('status', ClientQuotation::ISSUED)],
            // Typed on the update by the contractor. Left empty, the update says nothing about what is due.
            'received_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'photo_ids' => ['sometimes', 'nullable', 'array', 'max:'.config('modules.owner_pack.max_photos')],
            'photo_ids.*' => ['bail', 'integer', 'distinct'],
            'next_week_text' => ['sometimes', 'nullable', 'string', 'max:'.config('wording.free_slot_max_chars'), $this->validText()],
        ];
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

    /** The row as it is now, held until the transaction ends. The caller reached it through the tenant scopes. */
    private function locked(OwnerUpdate $update): OwnerUpdate
    {
        return OwnerUpdate::withoutGlobalScopes()
            ->where('organization_id', $update->organization_id)
            ->whereKey($update->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedDraft(OwnerUpdate $update): OwnerUpdate
    {
        $update = $this->locked($update);

        if (! $update->isDraft()) {
            throw new ImmutableRecordException(OwnerUpdate::lockedMessage());
        }

        return $update;
    }

    private function findByUuid(int $organizationId, string $uuid): ?OwnerUpdate
    {
        return ClientUuidRepeat::reachable(OwnerUpdate::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    /** What the audit trail keeps about an update: which week and what kind, never the share token, the text or the fact sheet. */
    private function summary(OwnerUpdate $update): array
    {
        return [
            'week_start' => $update->week_start?->toDateString(),
            'week_end' => $update->week_end?->toDateString(),
            'language' => $update->language,
            'status' => $update->status,
            'show_money' => (bool) $update->show_money,
            'quotation_id' => $update->quotation_id,
            'photos' => count($update->photoIds()),
            'prepared_on' => $update->prepared_on?->toDateString(),
            'fact_fingerprint' => $update->fact_fingerprint,
        ];
    }
}
