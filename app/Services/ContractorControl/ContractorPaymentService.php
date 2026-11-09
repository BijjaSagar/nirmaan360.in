<?php

namespace App\Services\ContractorControl;

use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\ContractorPayment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\Procurement\DocumentNumbers;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Payment entries against a contract. Each one is held until it clears approval. */
class ContractorPaymentService
{
    public function __construct(private ApprovalService $approvals, private DocumentNumbers $numbers) {}

    /** One rule set for the web form and the mobile API. */
    public function rules(WorkOrder $workOrder): array
    {
        return [
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'mode' => ['required', Rule::in(array_keys(config('modules.contractor_control.payment_modes')))],
            'paid_to' => ['nullable', 'string', 'max:160'],
            'reference' => ['nullable', 'string', 'max:60'],
            'is_advance' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'boq_item_id' => ['bail', 'nullable', 'integer', Rule::exists('work_order_items', 'boq_item_id')->where('work_order_id', $workOrder->id)],
            'contractor_milestone_id' => ['bail', 'nullable', 'integer', 'prohibits:boq_item_id', Rule::exists('contractor_milestones', 'id')->where('work_order_id', $workOrder->id)],
            'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Save a payment and send it for approval. A repeated client_uuid returns the original row. */
    public function raise(WorkOrder $workOrder, array $data, ?UploadedFile $bill, User $by): ContractorPayment
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? $this->find($workOrder->organization_id, $uuid) : null;

        if ($existing = $find()) {
            return $existing;
        }

        if ($workOrder->isClosed()) {
            throw ValidationException::withMessages(['work_order' => __('This contract is closed.')]);
        }

        $path = $bill?->store($this->folder($workOrder));

        $payment = Idempotent::create(function () use ($workOrder, $data, $path, $by, $uuid) {
            $payment = ContractorPayment::create([
                ...$this->fields($workOrder, $data),
                'organization_id' => $workOrder->organization_id,
                'project_id' => $workOrder->project_id,
                'work_order_id' => $workOrder->id,
                'party_id' => $workOrder->party_id,
                'number' => $this->numbers->next($workOrder->organization_id, 'CP'),
                'bill_path' => $path,
                'status' => 'DRAFT',
                'entered_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            $this->submit($payment, $by);

            return $payment->refresh();
        }, $find);

        if ($path && ! $payment->wasRecentlyCreated) {
            Storage::delete($path);
        }

        return $payment;
    }

    /** Correct a payment that was sent back, and send it again. A closed contract takes no more, as in raise(). */
    public function resubmit(ContractorPayment $payment, array $data, ?UploadedFile $bill, User $by): ContractorPayment
    {
        abort_unless($this->canEdit($payment, $by), 403);

        if ($payment->workOrder->isClosed()) {
            throw ValidationException::withMessages(['work_order' => __('This contract is closed.')]);
        }

        $path = $bill?->store($this->folder($payment->workOrder));

        return DB::transaction(function () use ($payment, $data, $path, $by) {
            $payment = ContractorPayment::lockForUpdate()->findOrFail($payment->id);

            if (! $payment->isEditable()) {
                throw ValidationException::withMessages(['status' => __('Only a returned payment can be changed.')]);
            }

            $payment->update([
                ...$this->fields($payment->workOrder, $data),
                'bill_path' => $path ?? $payment->bill_path,
            ]);

            $this->submit($payment, $by);

            return $payment->refresh();
        });
    }

    public function canEdit(ContractorPayment $payment, User $user): bool
    {
        return $payment->isEditable() && ($payment->entered_by === $user->id || $user->can('contractor.approve'));
    }

    /** An approved payment is corrected by a negative entry, which counts at once. */
    public function reverse(ContractorPayment $payment, string $reason, User $by): ContractorPayment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $payment = ContractorPayment::lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== 'APPROVED' || $payment->isReversal() || $payment->reversal()->exists()) {
                throw ValidationException::withMessages(['reason' => __('This payment cannot be reversed.')]);
            }

            $reversal = ContractorPayment::create([
                ...collect($payment->getAttributes())->except(['id', 'number', 'amount', 'notes', 'paid_on', 'bill_path', 'status', 'entered_by', 'client_uuid', 'created_at', 'updated_at'])->all(),
                'number' => $this->numbers->next($payment->organization_id, 'CP'),
                'amount' => Dec::money(BigDecimal::zero()->minus(Dec::of($payment->amount))),
                'paid_on' => today()->toDateString(),
                'notes' => $reason,
                'status' => 'APPROVED',
                'reversal_of_id' => $payment->id,
                'entered_by' => $by->id,
            ]);

            AuditLog::record('contractor_payment.reversed', $payment, null, ['reversal_id' => $reversal->id, 'reason' => $reason]);

            return $reversal;
        });
    }

    private function fields(WorkOrder $workOrder, array $data): array
    {
        return [
            'boq_item_id' => $data['boq_item_id'] ?? null,
            'contractor_milestone_id' => $data['contractor_milestone_id'] ?? null,
            'paid_on' => $data['paid_on'],
            'amount' => Dec::money($data['amount']),
            'mode' => $data['mode'],
            'paid_to' => filled($data['paid_to'] ?? null) ? $data['paid_to'] : (string) $workOrder->party?->name,
            'reference' => $data['reference'] ?? null,
            'is_advance' => (bool) ($data['is_advance'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function submit(ContractorPayment $payment, User $by): void
    {
        $hasOwnRule = ApprovalRule::where('organization_id', $payment->organization_id)
            ->where('doc_type', $payment->approvalDocType())
            ->where('active', true)
            ->exists();

        $this->approvals->submit($payment, $by, $hasOwnRule ? [] : config('modules.contractor_control.payment_approval_levels', []));
    }

    private function find(int $organizationId, string $uuid): ?ContractorPayment
    {
        return ClientUuidRepeat::reachable(ContractorPayment::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('client_uuid', $uuid)
            ->first());
    }

    private function folder(WorkOrder $workOrder): string
    {
        return 'org-'.$workOrder->organization_id.'/contractor-payments/'.$workOrder->project_id;
    }
}
