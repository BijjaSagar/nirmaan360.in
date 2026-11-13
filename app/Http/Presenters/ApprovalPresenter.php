<?php

namespace App\Http\Presenters;

use App\Models\Approval;
use App\Models\ApprovalStep;
use App\Models\BillNote;
use App\Models\BoqItem;
use App\Models\ContractorPayment;
use App\Models\Dpr;
use App\Models\Indent;
use App\Models\IndentItem;
use App\Models\MeasurementEntry;
use App\Models\RaBill;
use App\Models\Rfi;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\ApprovalService;
use App\Services\ContractorControl\WorkPaymentRatio;
use App\Support\Dec;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Bill amounts keep their keys but go out as null to anyone who may not see money. */
final class ApprovalPresenter
{
    /**
     * @param  Collection<int, Approval>  $approvals
     * @return list<array<string, mixed>>
     */
    public static function collection(Collection $approvals, User $user): array
    {
        $approvals->load('steps.user', 'submitter');
        $approvals->loadMorph('approvable', [
            MeasurementEntry::class => ['boqItem', 'project'],
            Dpr::class => ['project', 'creator', 'manpower', 'workItems.boqItem', 'photos'],
            RaBill::class => ['project', 'party'],
            BillNote::class => ['project', 'party', 'bill'],
            ContractorPayment::class => ['project', 'workOrder', 'party', 'boqItem', 'milestone', 'enteredBy'],
            VendorBill::class => ['project', 'party'],
            Indent::class => ['project', 'store', 'creator', 'items.material', 'items.unit', 'items.boqItem'],
            Rfi::class => ['project', 'raiser'],
        ]);

        $boqIds = $approvals->pluck('approvable')->filter(fn ($document) => $document instanceof MeasurementEntry)->pluck('boq_item_id')->unique();
        $ledger = BoqItem::withTrashed()->withLedger()->whereIn('id', $boqIds)->get()->keyBy('id');

        $service = app(ApprovalService::class);

        return $approvals
            ->filter(fn (Approval $approval) => $approval->approvable?->project !== null)
            ->map(fn (Approval $approval) => [
                'id' => $approval->id,
                'doc_type' => $approval->doc_type,
                'status' => $approval->status,
                'title' => $approval->approvable->approvalTitleFor($user),
                'project' => [
                    'id' => $approval->approvable->project->id,
                    'code' => $approval->approvable->project->code,
                    'name' => $approval->approvable->project->name,
                ],
                'submitted_by' => $approval->submitter ? ['id' => $approval->submitter->id, 'name' => $approval->submitter->name] : null,
                'submitted_at' => $approval->submitted_at?->toIso8601String(),
                'current_level' => $approval->current_level,
                'levels' => $approval->levels,
                'current_role' => $approval->currentRole(),
                'current_role_label' => $approval->currentRoleLabel(),
                'can_act' => $service->canAct($approval, $user),
                'document' => self::document($approval, $ledger, $user),
                'steps' => $approval->steps->map(self::step(...))->all(),
            ])
            ->values()
            ->all();
    }

    public static function step(ApprovalStep $step): array
    {
        return [
            'level' => $step->level,
            'role' => $step->role,
            'action' => $step->action,
            'remark' => $step->remark,
            'actor' => $step->actorName(),
            'acted_at' => $step->acted_at?->toIso8601String(),
        ];
    }

    /** The remark on the latest return in the document's current approval round. */
    public static function returnRemark(Model $document): ?string
    {
        return $document->currentApproval?->steps->where('action', 'return')->last()?->remark;
    }

    private static function document(Approval $approval, Collection $ledger, User $user): array
    {
        $document = $approval->approvable;

        return match (true) {
            $document instanceof MeasurementEntry => self::measurement($document, $ledger),
            $document instanceof Dpr => self::dpr($document),
            $document instanceof RaBill => self::raBill($document),
            $document instanceof BillNote => self::billNote($document),
            $document instanceof ContractorPayment => self::contractorPayment($document, $user),
            $document instanceof VendorBill => self::vendorBill($document, $user),
            $document instanceof Indent => self::indent($document),
            $document instanceof Rfi => self::rfi($document),
            default => [],
        };
    }

    private static function billNote(BillNote $note): array
    {
        return [
            'id' => $note->id,
            'number' => $note->number,
            'kind' => $note->kind,
            'ra_bill_number' => $note->bill?->number,
            'party_name' => $note->party?->name,
            'reason' => $note->reason,
            'taxable_amount' => Money::orNull($note->taxable_amount),
            'gst_amount' => Money::orNull($note->gst_amount),
            'total_amount' => Money::orNull($note->total_amount),
            'net_effect' => Money::orNull($note->net_effect),
        ];
    }

    private static function measurement(MeasurementEntry $entry, Collection $ledger): array
    {
        return [
            'id' => $entry->id,
            'boq_item' => [
                'code' => $entry->boqItem->code,
                'description' => $entry->boqItem->description,
                'unit' => $entry->boqItem->unit,
            ],
            'measured_on' => $entry->measured_on?->toDateString(),
            'location' => $entry->location,
            'description' => $entry->description,
            'nos' => $entry->nos,
            'length' => $entry->length,
            'breadth' => $entry->breadth,
            'depth' => $entry->depth,
            'is_deduction' => (bool) $entry->is_deduction,
            'qty' => $entry->qty,
            'deviation' => (bool) $entry->deviation,
            'remarks' => $entry->remarks,
            'balance_qty' => $ledger->get($entry->boq_item_id)?->balanceQty(),
        ];
    }

    private static function dpr(Dpr $dpr): array
    {
        return [
            'id' => $dpr->id,
            'report_date' => $dpr->report_date?->toDateString(),
            'weather' => $dpr->weather,
            'summary' => $dpr->summary,
            'hindrances' => $dpr->hindrances,
            'manpower_total' => (int) $dpr->manpower->sum('count'),
            'manpower' => $dpr->manpower->map(fn ($row) => ['trade' => $row->trade, 'count' => (int) $row->count])->all(),
            'work_items' => $dpr->workItems->map(fn ($row) => [
                'code' => $row->boqItem?->code,
                'description' => $row->description ?? $row->boqItem?->description,
                'unit' => $row->boqItem?->unit,
                'qty' => $row->qty,
            ])->all(),
            'photos' => $dpr->photos->map(fn ($photo) => ['id' => $photo->id])->all(),
            'created_by_name' => $dpr->creator?->name,
        ];
    }

    private static function raBill(RaBill $bill): array
    {
        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'type' => $bill->type,
            'period_from' => $bill->period_from?->toDateString(),
            'period_to' => $bill->period_to?->toDateString(),
            'party_name' => $bill->party?->name,
            'gross_amount' => Money::orNull($bill->gross_amount),
            'deduction_total' => Money::orNull($bill->deduction_total),
            'gst_amount' => Money::orNull($bill->gst_amount),
            'net_payable' => Money::orNull($bill->net_payable),
        ];
    }

    /**
     * A contractor payment request, with what approving it would do to the contract: the same work % against
     * paid % the contractor board shows (WorkPaymentRatio). Work % goes to every approver; the amount, the
     * payment's details and every paid figure only to someone with money.view, as null otherwise.
     */
    private static function contractorPayment(ContractorPayment $payment, User $user): array
    {
        $money = Money::visible($user);
        $impact = app(WorkPaymentRatio::class)->impact($payment);
        $order = $payment->workOrder;

        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status,
            'work_order' => ['id' => $order?->id, 'number' => $order?->number, 'title' => $order?->title],
            'contractor' => $payment->party?->name,
            'against' => self::paymentAgainst($payment, $impact['item']),
            'paid_on' => $payment->paid_on?->toDateString(),
            'entered_by' => $payment->enteredBy?->name,
            'work_pct' => $impact['now']['work_pct'],
            'balance_work_pct' => $impact['now']['balance_work_pct'],
            'amount' => $money ? $payment->amount : null,
            'is_advance' => $money ? $payment->is_advance : null,
            'mode' => $money ? $payment->mode : null,
            'paid_to' => $money ? $payment->paid_to : null,
            // Free text, which may quote a price.
            'notes' => $money ? $payment->notes : null,
            'has_bill' => $money ? $payment->bill_path !== null : null,
            'paid_pct' => $money ? $impact['now']['paid_pct'] : null,
            'impact' => $money ? ContractorControlPresenter::impact($impact) : null,
            // Filled by the owner-override rule for payments ahead of the work done (branch fix/web-money-rules):
            // "needed" when this approval would be refused unless the owner approves with a written reason,
            // "allowed" when this person is that owner, "message" says why in percentages only.
            'owner_override' => ['needed' => false, 'allowed' => false, 'message' => null],
        ];
    }

    /** What the payment is for: one BOQ line of the contract, one part of it, or the contract in general. */
    private static function paymentAgainst(ContractorPayment $payment, ?array $line): array
    {
        if ($payment->boqItem) {
            return [
                'type' => 'boq_item',
                'code' => $payment->boqItem->code,
                'description' => $payment->boqItem->description,
                'unit' => $payment->boqItem->unit,
                'work_pct' => $line['work_pct'] ?? null,
            ];
        }

        if ($payment->milestone) {
            return ['type' => 'part', 'title' => $payment->milestone->title, 'done' => $payment->milestone->isDone()];
        }

        return ['type' => 'contract'];
    }

    /**
     * A supplier's bill against the project. Every amount, and the free text that may quote one, is null
     * without money.view; the rates of GST and TDS are not money and always go.
     */
    private static function vendorBill(VendorBill $bill, User $user): array
    {
        $money = Money::visible($user);
        $gst = Dec::of($bill->cgst_amount)->plus(Dec::of($bill->sgst_amount))->plus(Dec::of($bill->igst_amount));

        return [
            'id' => $bill->id,
            'number' => $bill->bill_no,
            'party_name' => $bill->party?->name,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'cost_head' => $bill->cost_head,
            'supply_type' => $bill->supply_type,
            'gst_pct' => $bill->gst_pct,
            'tds_section' => $bill->tds_section,
            'tds_pct' => $bill->tds_pct,
            'description' => $money ? $bill->description : null,
            'remarks' => $money ? $bill->remarks : null,
            'taxable_amount' => $money ? $bill->taxable_amount : null,
            'gst_amount' => $money ? Dec::money($gst) : null,
            'total_amount' => $money ? $bill->total_amount : null,
            'tds_amount' => $money ? $bill->tds_amount : null,
            'net_payable' => $money ? $bill->net_payable : null,
        ];
    }

    /** A request for material from site. Quantities only: an indent carries no price. */
    private static function indent(Indent $indent): array
    {
        return [
            'id' => $indent->id,
            'number' => $indent->number,
            'store' => $indent->store?->name,
            'required_by' => $indent->required_by?->toDateString(),
            'remarks' => $indent->remarks,
            'created_by_name' => $indent->creator?->name,
            'items' => $indent->items->map(fn (IndentItem $line) => [
                'material' => $line->material?->name,
                'material_code' => $line->material?->code,
                'qty' => $line->qty,
                'unit' => $line->unit?->code,
                'boq_item' => $line->boqItem ? ['code' => $line->boqItem->code, 'description' => $line->boqItem->description] : null,
                'remarks' => $line->remarks,
            ])->all(),
        ];
    }

    /** A request for information, approved before it goes out. The answer comes after approval. */
    private static function rfi(Rfi $rfi): array
    {
        return [
            'id' => $rfi->id,
            'number' => $rfi->number,
            'subject' => $rfi->subject,
            'question' => $rfi->question,
            'drawing_ref' => $rfi->drawing_ref,
            'location' => $rfi->location,
            'addressed_to' => $rfi->addressed_to,
            'priority' => $rfi->priority,
            'required_by' => $rfi->required_by?->toDateString(),
            'raised_by_name' => $rfi->raiser?->name,
        ];
    }
}
