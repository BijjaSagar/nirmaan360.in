<?php

namespace App\Services\BillingExtras;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\BillNote;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Credit and debit notes against an approved RA bill.
 *
 * "Debit" and "credit" name the entry made in the party's account in our books.
 * A client is our debtor, so a debit raises what they owe us and a credit lowers it.
 * A subcontractor is our creditor, so a debit lowers what we owe them (a back-charge)
 * and a credit raises it. The note's GST follows the bill: tax never charged is never adjusted.
 */
class BillNoteService
{
    /** Notes that count against the bill. A reduction reserves its room from the moment it is raised. */
    private const PENDING_STATUSES = ['DRAFT', 'IN_REVIEW', 'RETURNED'];

    public function __construct(private ApprovalService $approvals) {}

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(BillNote::KINDS)],
            'note_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:1000'],
            'taxable_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'decimal:0,2'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** The signed change a note of this kind makes to the bill's net payable. */
    public function netEffect(string $billType, string $kind, mixed $total): string
    {
        $raises = ($kind === 'debit') === ($billType === 'client');

        return Dec::money($raises ? Dec::of($total) : Dec::of($total)->negated());
    }

    /** How much more the bill can be reduced, leaving approved increases available and pending reductions reserved. */
    public function reducibleAmount(RaBill $bill): string
    {
        $room = Dec::of($bill->net_payable);

        foreach ($bill->notes()->where('status', 'APPROVED')->pluck('net_effect') as $effect) {
            $room = $room->plus(Dec::of($effect));
        }

        foreach ($bill->notes()->whereIn('status', self::PENDING_STATUSES)->pluck('net_effect') as $effect) {
            if (Dec::of($effect)->isNegative()) {
                $room = $room->plus(Dec::of($effect));
            }
        }

        return Dec::money($room->isNegative() ? BigDecimal::zero() : $room);
    }

    /** Raise a draft note. A repeated client_uuid returns the original row. */
    public function create(RaBill $bill, array $data, User $user): BillNote
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($bill->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            function () use ($bill, $data, $user, $uuid) {
                // The project lock keeps numbers unique, the bill lock keeps its room honest.
                Project::lockForUpdate()->findOrFail($bill->project_id);
                $bill = RaBill::lockForUpdate()->findOrFail($bill->id);

                if (! $bill->acceptsNotes()) {
                    throw ValidationException::withMessages(['kind' => __('Notes can only be raised against approved or paid bills.')]);
                }

                $taxable = Dec::money($data['taxable_amount']);
                $gstPct = $bill->hasGst() ? Dec::money($bill->terms['gst_pct'] ?? 0) : '0.00';
                $gst = Dec::percentOf($taxable, $gstPct);
                $total = Dec::money(Dec::of($taxable)->plus(Dec::of($gst)));
                $effect = $this->netEffect($bill->type, $data['kind'], $total);

                $this->assertWithinBill($bill, $effect);

                return BillNote::create([
                    'organization_id' => $bill->organization_id,
                    'project_id' => $bill->project_id,
                    'ra_bill_id' => $bill->id,
                    'party_id' => $bill->party_id,
                    'kind' => $data['kind'],
                    'number' => $this->nextNumber($bill, $data['kind']),
                    'note_date' => $data['note_date'],
                    'reason' => $data['reason'],
                    'taxable_amount' => $taxable,
                    'gst_pct' => $gstPct,
                    'gst_amount' => $gst,
                    'total_amount' => $total,
                    'net_effect' => $effect,
                    'status' => 'DRAFT',
                    'created_by' => $user->id,
                    'client_uuid' => $uuid,
                ]);
            },
            fn () => $this->findByClientUuid($bill->organization_id, (string) $uuid),
        );
    }

    /**
     * Send a note through the company's RA bill approvers. The signed client link
     * only works for bills, so a client level on that chain is left out here.
     */
    public function submit(BillNote $note, User $user): ?Approval
    {
        $levels = array_values(array_diff($this->approvals->levelsFor('ra_bill', $note->organization_id), ['client']));

        return $this->approvals->submit($note, $user, $levels);
    }

    public function cancel(BillNote $note): BillNote
    {
        if (! $note->isCancellable()) {
            throw ValidationException::withMessages(['status' => __('Only draft, returned or rejected notes can be cancelled.')]);
        }

        $note->update(['status' => 'CANCELLED']);
        AuditLog::record('bill_note.cancelled', $note);

        return $note;
    }

    /** A note can never take the bill below zero: the party cannot have been over-billed by more than was billed. */
    private function assertWithinBill(RaBill $bill, string $effect): void
    {
        $effect = Dec::of($effect);

        if (! $effect->isNegative()) {
            return;
        }

        $room = $this->reducibleAmount($bill);

        if ($effect->negated()->isGreaterThan(Dec::of($room))) {
            throw ValidationException::withMessages(['taxable_amount' => __('This note would take the bill below zero. The most it can be reduced by, with GST, is :amount.', ['amount' => inr($room)])]);
        }
    }

    private function nextNumber(RaBill $bill, string $kind): string
    {
        $sequence = BillNote::withoutGlobalScopes()->where('project_id', $bill->project_id)->where('kind', $kind)->count() + 1;

        return sprintf('%s-%s-%03d', $kind === 'credit' ? 'CN' : 'DN', $bill->project->code, $sequence);
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?BillNote
    {
        return BillNote::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
