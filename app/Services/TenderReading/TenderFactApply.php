<?php

namespace App\Services\TenderReading;

use App\Models\AuditLog;
use App\Models\CrmTask;
use App\Models\CrmTender;
use App\Models\CrmTenderFact;
use App\Models\CrmTenderPayment;
use App\Models\User;
use App\Services\Crm\ActivityService;
use App\Services\Crm\TaskService;
use App\Services\Crm\TenderPaymentService;
use App\Services\Crm\TenderService;
use App\Services\DocumentReading\ReadRefused;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Puts one confirmed fact of the tender sheet to use, by a person, one fact at a time:
 *
 * - "field": the value goes into the tender's own field through TenderService::update.
 * - "task": a date becomes a follow-up on the tender through TaskService::create.
 * - "payment": an EMD or fee becomes a planned payment through TenderPaymentService::record, never a
 *   paid one; the person picks the form of payment.
 *
 * A fact that replaced a value from another document (a corrigendum) changes what the replaced value
 * made, instead of adding a second one: the planned payment it made, while still only planned, gets
 * the new amount, and the follow-up it made, while still open, moves to the new date. This follows
 * every replacement back, so a second corrigendum finds what the notice's value made even when the
 * first corrigendum's value was never put to use. What a person wrote on that payment or follow-up
 * is kept, and the new value's page is added below it.
 *
 * The CRM services do not validate by themselves (their controllers do), so the one value is checked
 * here with the rule the CRM's own form uses for it, and refused when it fails. Everything else a
 * tender says stays on the sheet.
 */
class TenderFactApply
{
    public const FIELD = 'field';

    public const TASK = 'task';

    public const PAYMENT = 'payment';

    public const TARGETS = [self::FIELD, self::TASK, self::PAYMENT];

    /** The tender column each field fills. The pre-bid meeting place goes into the tender's pre-bid note. */
    public const COLUMNS = [
        'reference' => 'reference',
        'title' => 'title',
        'authority' => 'authority',
        'location' => 'location',
        'state' => 'state',
        'estimated_value' => 'estimated_value',
        'published_on' => 'published_on',
        'pre_bid_at' => 'pre_bid_at',
        'pre_bid_venue' => 'pre_bid_note',
        'due_at' => 'due_at',
        'opens_at' => 'opens_at',
        'portal_url' => 'portal_url',
    ];

    /** The kind of tender payment each money field is recorded as. */
    public const PAYMENTS = ['emd_amount' => 'emd', 'tender_fee' => 'tender_fee', 'processing_fee' => 'processing_fee'];

    /** Tender columns that hold a date and a time: a date read without a time is not put there. */
    private const WITH_TIME = ['pre_bid_at', 'due_at', 'opens_at'];

    public const NOT_CONFIRMED = 'not_confirmed';

    public const IN_CONFLICT = 'in_conflict';

    public const UNCHECKED = 'unchecked';

    public const NOT_APPLICABLE = 'not_applicable';

    public const VALUE_REFUSED = 'value_refused';

    /** @var array<int, array<string, mixed>> The tender form's rules, by person: they read the company's custom fields. */
    private array $tenderRules = [];

    public function __construct(
        private TenderService $tenders,
        private TaskService $tasks,
        private TenderPaymentService $payments,
        private ActivityService $activities,
    ) {}

    /** @return list<string> The uses this fact's field can be put to. */
    public static function targets(CrmTenderFact $fact): array
    {
        $kind = TenderFields::kind($fact->field);

        return array_values(array_filter([
            isset(self::COLUMNS[$fact->field]) ? self::FIELD : null,
            in_array($kind, [TenderFields::DATE, TenderFields::DATETIME], true) ? self::TASK : null,
            isset(self::PAYMENTS[$fact->field]) ? self::PAYMENT : null,
        ]));
    }

    /**
     * Why this fact cannot be put to this use now, or null when it can. The same reasons the apply
     * route refuses with, so the sheet can say them before anyone presses a button. The form of
     * payment is not looked at here: the person picks it when applying.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public function blocker(CrmTenderFact $fact, string $target, User $by, ?CrmTender $tender = null): ?array
    {
        $refuse = fn (string $code, string $message, int $status) => ['code' => $code, 'message' => $message, 'status' => $status];

        if (! in_array($target, self::targets($fact), true)) {
            return $refuse(self::NOT_APPLICABLE, __('This fact cannot be used that way. It stays on the sheet.'), 422);
        }

        if ($fact->status !== CrmTenderFact::CONFIRMED) {
            return $refuse(self::NOT_CONFIRMED, __('Confirm this fact against its page before you use it.'), 409);
        }

        if ($fact->conflict_group !== null) {
            return $refuse(self::IN_CONFLICT, __('The sheet holds another value for this. Reject the one that does not stand, then use this one.'), 409);
        }

        // A line that was not found in its page's text is used only once a person corrected it or checked it with the page open.
        if ($fact->source === CrmTenderFact::FROM_TEXT && $fact->quote_found === false && ! $fact->edited && ! $fact->page_viewed) {
            return $refuse(self::UNCHECKED, __('This line was not matched to the text of its page. Check it against the page first.'), 409);
        }

        $problem = $this->valueProblem($fact, $target, $by, $tender);

        return $problem === null ? null : $refuse(self::VALUE_REFUSED, $problem, 422);
    }

    /**
     * Puts the fact to the use. Doing it again gives back what the first time made, and makes nothing new.
     *
     * @param  ?string  $mode  For a payment: one of CrmTenderPayment::MODES, picked by the person.
     * @param  ?string  $clientUuid  For a follow-up: kept on the task, so the same request twice makes one task.
     * @return array{fact: CrmTenderFact, record: ?Model, repeated: bool}
     *
     * @throws ReadRefused not_confirmed, in_conflict or unchecked (409)
     * @throws ValidationException target (not_applicable), value (value_refused) or mode
     */
    public function apply(CrmTenderFact $fact, string $target, User $by, ?string $mode = null, ?string $clientUuid = null): array
    {
        return DB::transaction(function () use ($fact, $target, $by, $mode, $clientUuid) {
            $locked = CrmTenderFact::withoutGlobalScopes()->where('organization_id', $fact->organization_id)->lockForUpdate()->findOrFail($fact->id);
            $tender = CrmTender::withoutGlobalScopes()->where('organization_id', $locked->organization_id)->whereNull('deleted_at')->lockForUpdate()->findOrFail($locked->tender_id);

            if (array_key_exists($target, $uses = $locked->uses())) {
                return ['fact' => $locked, 'record' => $this->record($target, $uses[$target], $tender), 'repeated' => true];
            }

            if (($blocked = $this->blocker($locked, $target, $by, $tender)) !== null) {
                match ($blocked['code']) {
                    self::NOT_APPLICABLE => throw ValidationException::withMessages(['target' => [$blocked['message']]]),
                    self::VALUE_REFUSED => throw ValidationException::withMessages(['value' => [$blocked['message']]]),
                    default => throw new ReadRefused($blocked['code'], $blocked['message'], ['status' => $locked->status], $blocked['status']),
                };
            }

            if ($target === self::PAYMENT && ! in_array($mode, CrmTenderPayment::MODES, true)) {
                throw ValidationException::withMessages(['mode' => [__('Choose how the payment will be made.')]]);
            }

            $earlier = $this->earlier($locked, $target, $tender, lock: true);

            $record = match (true) {
                $target === self::FIELD => $this->tenders->update($tender, $this->fieldData($locked)),
                $target === self::TASK && $earlier instanceof CrmTask => $this->tasks->update($earlier, [
                    ...$this->taskData($locked, $tender, null),
                    'title' => $earlier->title,
                    'notes' => self::addNote($earlier->notes, $this->taskData($locked, $tender, null)['notes'], 2000),
                    'assigned_to' => $earlier->assigned_to,
                ]),
                $target === self::TASK => $this->tasks->create($this->taskData($locked, $tender, $clientUuid), $by),
                $earlier instanceof CrmTenderPayment => $this->payments->update($earlier, [
                    ...$this->paymentData($locked, $mode),
                    'notes' => self::addNote($earlier->notes, $this->paymentData($locked, $mode)['notes'], 1000),
                    'reference' => $earlier->reference,
                    'valid_until' => $earlier->valid_until?->toDateString(),
                    'refund_expected_on' => $earlier->refund_expected_on?->toDateString(),
                    'refundable' => (bool) $earlier->refundable,
                ]),
                default => $this->payments->record($tender, $this->paymentData($locked, $mode), $by),
            };

            $locked->forceFill(['applied_to' => $locked->withUse($target, $target === self::FIELD ? null : (int) $record->getKey())])->save();

            // Who put which field from which page to which use. Never the value: the tender's own audit keeps what changed on it.
            AuditLog::record('tender_fact.applied', $locked, null, [
                'target' => $target,
                'field' => $locked->field,
                'page' => (int) $locked->page,
                'record_type' => $record->getMorphClass(),
                'record_id' => (int) $record->getKey(),
                ...($earlier === null ? [] : ['changed_earlier' => true]),
            ], $by, (int) $locked->organization_id);

            $this->activities->log($tender, 'system', $this->activityLine($locked, $target, $earlier !== null), $by);

            return ['fact' => $locked->refresh(), 'record' => $record, 'repeated' => false];
        });
    }

    /**
     * What putting the fact to this use will do, in one sentence for the sheet.
     *
     * @param  bool  $changesEarlier  The use changes the planned payment or follow-up of a value this fact replaced (earlier()).
     */
    public static function preview(CrmTenderFact $fact, string $target, bool $changesEarlier = false): string
    {
        $name = __(TenderFields::label($fact->field));
        $shown = TenderValue::display($fact->value_kind, $fact->value_norm) ?? trim($fact->value_raw);

        return match (true) {
            $target === self::FIELD => __(':field on the tender becomes :value.', ['field' => $name, 'value' => $shown]),
            $target === self::TASK && $changesEarlier => __('The follow-up made from the replaced value moves to :value.', ['value' => $shown]),
            $target === self::TASK => __('A follow-up on this tender for :value.', ['value' => $shown]),
            $changesEarlier => __('The planned payment made from the replaced value becomes :value. It is not marked paid.', ['value' => $shown]),
            default => __('A planned payment of :value on this tender. It is not marked paid.', ['value' => $shown]),
        };
    }

    /**
     * The planned payment or open follow-up that a value this fact replaced was put to, which this fact
     * changes when it is put to the same use. A payment no longer only planned, or a follow-up done or
     * removed, is left as it is, and the use makes a new one.
     */
    public function earlier(CrmTenderFact $fact, string $target, CrmTender $tender, bool $lock = false): ?Model
    {
        if (! in_array($target, [self::TASK, self::PAYMENT], true)) {
            return null;
        }

        foreach (TenderFactDiff::replacedChain($fact) as $old) {
            $id = $old->uses()[$target] ?? null;
            $record = $id === null ? null : $this->openRecord($target, $id, $tender, $lock);

            if ($record !== null && ($target === self::TASK || $record->kind === (self::PAYMENTS[$fact->field] ?? null))) {
                return $record;
            }
        }

        return null;
    }

    /**
     * What the sheet says under a value that was replaced after it was put to use: what the value that
     * stands now in its place does, or did, to each record this one made. A record is said to change
     * only when earlier() for the standing value finds that very record.
     *
     * @return list<string>
     */
    public function replacedUses(CrmTenderFact $old, CrmTender $tender): array
    {
        $standing = TenderFactDiff::standingAfter($old);
        $notes = [];

        foreach ($old->uses() as $target => $id) {
            $open = $id !== null && $this->openRecord($target, $id, $tender) !== null;
            $standingUses = $standing?->uses() ?? [];
            $into = $id !== null && $target !== self::FIELD && ($standingUses[$target] ?? null) === $id;
            $changes = $open && ! $into && $standing !== null && ! array_key_exists($target, $standingUses)
                && $this->earlier($standing, $target, $tender)?->getKey() === $id;

            $notes[] = match (true) {
                $target === self::FIELD => __('This value was put on the tender. Put the new value on the tender to change it there.'),
                $target === self::PAYMENT && $into => __('The new value was put into the payment made from this value.'),
                $target === self::PAYMENT && $changes => __('A planned payment was made from this value. Adding the new value as a planned payment changes that payment.'),
                $target === self::PAYMENT && $open => __('A planned payment made from this value stays on the tender. The new value does not change it, so check it on the tender.'),
                $target === self::PAYMENT => __('A payment made from this value is no longer only planned. The new value is added as another planned payment, so check both on the tender.'),
                $into => __('The new value was put into the follow-up made from this value.'),
                $changes => __('A follow-up was made from this value. Adding the new value as a follow-up moves it.'),
                $open => __('A follow-up made from this value stays open. The new value does not move it, so check it on the tender.'),
                default => __('A follow-up made from this value is done or removed. The new value makes a new follow-up.'),
            };
        }

        return $notes;
    }

    /** A note a person wrote, kept whole, with the line of the new value's page added below it. */
    private static function addNote(?string $kept, string $added, int $max): string
    {
        return Str::limit(trim(trim((string) $kept)."\n".$added), $max, '');
    }

    /** A planned payment of this tender, or an open follow-up on it: a record the new value may still change. */
    private function openRecord(string $target, int $id, CrmTender $tender, bool $lock = false): ?Model
    {
        if ($target === self::PAYMENT) {
            return CrmTenderPayment::withoutGlobalScopes()->where('organization_id', $tender->organization_id)
                ->where('tender_id', $tender->id)->where('status', 'planned')->whereNull('deleted_at')
                ->when($lock, fn ($query) => $query->lockForUpdate())->find($id);
        }

        if ($target === self::TASK) {
            return CrmTask::withoutGlobalScopes()->where('organization_id', $tender->organization_id)
                ->where('subject_type', 'tender')->where('subject_id', $tender->id)->where('status', 'open')->whereNull('deleted_at')
                ->when($lock, fn ($query) => $query->lockForUpdate())->find($id);
        }

        return null;
    }

    /** The message the value would be refused with for this use, or null when it passes. */
    private function valueProblem(CrmTenderFact $fact, string $target, User $by, ?CrmTender $tender): ?string
    {
        $kind = TenderFields::kind($fact->field);
        $unread = match ($kind) {
            TenderFields::AMOUNT => __('The app could not read this as one amount, so it is not used. Type it on the tender yourself.'),
            TenderFields::DATE, TenderFields::DATETIME => __('The app could not read this as one date, so it is not used. Type it on the tender yourself.'),
            default => null,
        };

        if ($unread !== null && $fact->value_norm === null) {
            return $unread;
        }

        if ($target === self::FIELD) {
            $column = self::COLUMNS[$fact->field];

            if (in_array($column, self::WITH_TIME, true) && strlen((string) $fact->value_norm) <= 10) {
                return __('No time was read with this date, so it is not put on the tender. Add it as a follow-up, or type it on the tender yourself.');
            }

            $this->tenderRules[$by->id] ??= $this->tenders->rules($by);

            return $this->failure($this->fieldData($fact), [$column => $this->tenderRules[$by->id][$column]]);
        }

        if ($target === self::TASK) {
            $rules = array_intersect_key($this->tasks->rules(), array_flip(['title', 'notes', 'due_on', 'due_time']));

            return $this->failure($this->taskData($fact, $tender, null), $rules);
        }

        $rules = array_intersect_key($this->payments->rules(), array_flip(['kind', 'amount', 'status', 'notes']));

        return $this->failure($this->paymentData($fact, null), $rules);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function failure(array $data, array $rules): ?string
    {
        $validator = Validator::make($data, $rules);

        return $validator->fails() ? (string) $validator->errors()->first() : null;
    }

    /** @return array<string, mixed> The one tender field, as the tender form would send it. */
    private function fieldData(CrmTenderFact $fact): array
    {
        $column = self::COLUMNS[$fact->field];
        $value = match (TenderFields::kind($fact->field)) {
            TenderFields::AMOUNT, TenderFields::DATE, TenderFields::DATETIME => $fact->value_norm,
            // A link is checked as printed, so anything that is not a plain http or https address is refused.
            default => trim($fact->value_raw),
        };

        return [$column => $value];
    }

    /** @return array<string, mixed> */
    private function taskData(CrmTenderFact $fact, ?CrmTender $tender, ?string $clientUuid): array
    {
        $title = __(TenderFields::label($fact->field));

        if (TenderFields::repeatable($fact->field) && filled($fact->label)) {
            $title .= ': '.trim((string) $fact->label);
        }

        $norm = (string) $fact->value_norm;

        return [
            'title' => Str::limit($title, 200, ''),
            'notes' => Str::limit(__('From the tender sheet, page :page:', ['page' => (int) $fact->page])."\n".trim((string) ($fact->quote_line ?? $fact->quote)), 2000, ''),
            'subject_type' => 'tender',
            'subject_id' => (int) ($tender?->id ?? $fact->tender_id),
            'due_on' => TenderValue::day($fact->value_kind, $fact->value_norm),
            'due_time' => strlen($norm) > 10 ? substr($norm, 11, 5) : null,
            'client_uuid' => $clientUuid,
        ];
    }

    /** @return array<string, mixed> Always planned: a payment read from a tender is one still to be made. */
    private function paymentData(CrmTenderFact $fact, ?string $mode): array
    {
        return [
            'kind' => self::PAYMENTS[$fact->field] ?? null,
            'amount' => $fact->value_norm,
            'mode' => $mode,
            'status' => 'planned',
            'notes' => __('From the tender sheet, page :page.', ['page' => (int) $fact->page]),
        ];
    }

    /** What the first use of a fact made, for a request made twice. */
    private function record(string $target, ?int $id, CrmTender $tender): ?Model
    {
        return match ($target) {
            self::FIELD => $tender,
            self::TASK => $id === null ? null : CrmTask::withoutGlobalScopes()->where('organization_id', $tender->organization_id)->find($id),
            default => $id === null ? null : CrmTenderPayment::withoutGlobalScopes()->where('organization_id', $tender->organization_id)->find($id),
        };
    }

    private function activityLine(CrmTenderFact $fact, string $target, bool $changedEarlier): string
    {
        $values = ['field' => __(TenderFields::label($fact->field)), 'page' => (int) $fact->page];

        return match (true) {
            $target === self::FIELD => __(':field taken from the tender sheet (page :page).', $values),
            $target === self::TASK && $changedEarlier => __('Follow-up moved from the tender sheet: :field (page :page).', $values),
            $target === self::TASK => __('Follow-up added from the tender sheet: :field (page :page).', $values),
            $changedEarlier => __('Planned payment changed from the tender sheet: :field (page :page).', $values),
            default => __('Planned payment added from the tender sheet: :field (page :page).', $values),
        };
    }
}
