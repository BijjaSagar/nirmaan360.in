<?php

namespace App\Services\ContractorControl;

use App\Models\AuditLog;
use App\Models\ContractorPayment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApprovalGuard;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No contractor payment beyond the work done unless the owner says so in writing.
 *
 * Approving a payment is refused when, with it counted, the contract would be over the project's limit:
 * exactly the "over_limit" that raises the payment-ahead alert (PaymentAheadSource), from the same
 * WorkPaymentRatio figures (value of work done, approved payments to date, payment_gap_alert_pct). So a
 * payment the approver may pass never raises that alert by itself, and one that would is stopped first.
 * A contract with no value has no work done to pay against, so any payment on it is over the limit.
 * The limit itself is the owner's to change (ProjectLimits), or the approver could lift it first.
 *
 * Only the owner may approve it anyway, with a reason of at least a sentence. The reason, who gave it and
 * when are stored on the payment and in the audit log with the figures as they stood. Raising a payment
 * is not affected: the request is saved and the warning shows, as before.
 */
class PaymentAheadGuard implements ApprovalGuard
{
    public const MIN_REASON_CHARACTERS = 20;

    public const MIN_REASON_WORDS = 4;

    public function __construct(private WorkPaymentRatio $ratio) {}

    /**
     * The contract now, and as it would stand with this payment counted.
     *
     * @return array{contract_value: string, work_done: string, work_pct: string, paid: string, paid_pct: string, paid_after: string, paid_pct_after: string, gap_after: string, limit_pct: string, blocked: bool}
     */
    public function assess(ContractorPayment $payment): array
    {
        $now = $this->ratio->forContract($payment->workOrder);
        $after = $this->ratio->compare($now['contract_value'], Dec::of($now['paid'])->plus(Dec::of($payment->amount)), $now['work_pct'], $now['limit_pct'], $now['earned']);

        return [
            'contract_value' => $now['contract_value'],
            'work_done' => $now['earned'],
            'work_pct' => $now['work_pct'],
            'paid' => $now['paid'],
            'paid_pct' => $now['paid_pct'],
            'paid_after' => $after['paid'],
            'paid_pct_after' => $after['paid_pct'],
            'gap_after' => $after['gap_pct'],
            'limit_pct' => $now['limit_pct'],
            // A reversal lowers what is paid and never goes through approval; only a request still waiting can be stopped.
            'blocked' => ! $payment->isReversal() && Dec::of($payment->amount)->isPositive() && $after['over_limit'],
        ];
    }

    public function blocks(Model $document): bool
    {
        return $document instanceof ContractorPayment && $this->assess($document)['blocked'];
    }

    public function check(Model $document, ?User $by, array $context): void
    {
        if (! $document instanceof ContractorPayment) {
            return;
        }

        // Two final approvals on one contract wait for each other, so both cannot pass on the same "paid so far".
        WorkOrder::withoutGlobalScopes()->whereKey($document->work_order_id)->lockForUpdate()->first();

        $figures = $this->assess($document);

        if (! $figures['blocked']) {
            return;
        }

        if (! $by?->isOwner()) {
            throw ValidationException::withMessages(['override_reason' => self::blockedMessage($figures, (bool) $by?->can('money.view'))]);
        }

        $reason = trim((string) ($context['override_reason'] ?? ''));

        if (! self::isSentence($reason)) {
            throw ValidationException::withMessages(['override_reason' => __('Write why this payment may go ahead of the work done, in at least one full sentence.')]);
        }

        $document->update(['override_reason' => $reason, 'override_by' => $by->id, 'override_at' => now()]);

        AuditLog::record('contractor_payment.owner_override', $document, null, [
            ...collect($figures)->except('blocked')->all(),
            'amount' => $document->amount,
            'reason' => $reason,
        ], $by);
    }

    /**
     * Why the approval is stopped. Paid % and the gap are money (ContractorCard, ContractorControlPresenter::workOnly),
     * so an approver without money.view, such as a custom role allowed to pass payments, is told why with no figure.
     */
    public static function blockedMessage(array $figures, bool $money): string
    {
        if (! $money) {
            return __('Approving this payment would put payment ahead of the work done on this contract, past what this project allows. Only the owner can approve it, with a written reason.');
        }

        // Both percentages are 0 on a contract with no value, so they would explain nothing.
        if (Dec::of($figures['contract_value'])->isZero()) {
            return __('This contract has no value, as its lines have no quantity, so no payment on it is covered by work done. Only the owner can approve it, with a written reason.');
        }

        return __('Approving this payment would make it :paid% paid against :work% work done, :gap points ahead, beyond this project\'s limit of :limit points. Only the owner can approve it, with a written reason.', [
            'paid' => Figures::pct($figures['paid_pct_after']),
            'work' => Figures::pct($figures['work_pct']),
            'gap' => Figures::pct($figures['gap_after']),
            'limit' => Figures::pct($figures['limit_pct']),
        ]);
    }

    /** At least a sentence: enough characters and words, in any script. */
    public static function isSentence(string $reason): bool
    {
        $words = preg_split('/\s+/u', trim($reason), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strlen(trim($reason)) >= self::MIN_REASON_CHARACTERS && count($words) >= self::MIN_REASON_WORDS;
    }
}
