<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing the agreement says, as a person typed it: a deduction, a notice period, a payment period,
 * a limit on extra quantity or a detail such as the agreement date. It carries the date it applies
 * from and the clause or page it was read on.
 *
 * A null work_order_id is the contract with the client. A term counts only once it is confirmed and
 * only on the dates it covers; App\Services\ContractTerms\ContractTerms::effective() answers that.
 */
class ContractTerm extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = ['deduction', 'notice', 'excess_quantity', 'payment_period', 'reference'];

    public const METHODS = ['percent', 'fixed_per_bill', 'typed_each_bill'];

    public const BASES = ['gross', 'running', 'gross_plus_gst'];

    public const NATURES = ['withheld_refundable', 'tax_credit', 'cost', 'recovery'];

    public const COMBINE = ['earliest', 'latest'];

    /**
     * The deductions an RA bill already works out, and the key each goes by in the bill's saved
     * terms (Project::billingTerms()). They are always a percentage, so a term for one can be
     * compared with the bill.
     */
    public const BILL_PERCENTAGES = [
        'retention' => 'retention_pct',
        'tds' => 'tds_pct',
        'gst' => 'gst_pct',
        'advance_recovery' => 'advance_recovery_pct',
    ];

    /** A contract has one of each of these at a time, so two confirmed ones may not cover the same day. */
    public const ONE_AT_A_TIME = [
        'retention', 'tds', 'gst', 'advance_recovery', 'excess_quantity', 'payment_due',
        'agreement_no', 'agreement_date', 'stipulated_completion',
    ];

    /** The columns that hold the term itself. Which of them are filled depends on the kind. */
    public const VALUE_COLUMNS = [
        'method', 'pct', 'amount', 'base', 'cap_amount', 'nature', 'days', 'counted_from',
        'combine', 'combine_group', 'value_text', 'value_date',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pct' => 'decimal:4',
            'amount' => 'decimal:2',
            'cap_amount' => 'decimal:2',
            'days' => 'integer',
            'value_date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The subcontract the term belongs to. A removed work order still names its terms. */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * The same for two terms that say the same thing, and different as soon as anything a person
     * would read in the agreement differs: the contract, the term itself, its dates or its source.
     * The confirm form carries it, so a term changed in the meantime is not confirmed unseen.
     */
    public function fingerprint(): string
    {
        $date = fn (?CarbonInterface $day) => $day?->toDateString();

        return hash('sha256', (string) json_encode([
            (int) $this->project_id,
            $this->work_order_id === null ? null : (int) $this->work_order_id,
            $this->kind, $this->code, $this->label,
            $this->method, $this->pct, $this->amount, $this->base, $this->cap_amount, $this->nature,
            $this->days, $this->counted_from, $this->combine, $this->combine_group,
            $this->value_text, $date($this->value_date),
            $date($this->effective_from), $date($this->effective_to), $this->source_ref,
        ]));
    }

    /** Whether the term's dates cover this day. Says nothing about confirmation. */
    public function covers(CarbonInterface $on): bool
    {
        $day = $on->toDateString();

        return $this->effective_from->toDateString() <= $day
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $day);
    }
}
