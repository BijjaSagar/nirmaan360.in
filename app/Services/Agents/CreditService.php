<?php

namespace App\Services\Agents;

use App\Models\AgentCreditLedger;
use App\Models\AgentCreditTopup;
use App\Models\AgentCreditWallet;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\RazorpayGateway;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Each company's prepaid credits: a monthly allowance from the plan, then anything it has bought. */
class CreditService
{
    public function __construct(private RazorpayGateway $gateway) {}

    public function wallet(Organization $organization): AgentCreditWallet
    {
        return DB::transaction(fn () => $this->lockedWallet($organization));
    }

    public function hasCredits(Organization $organization): bool
    {
        return Dec::of($this->wallet($organization)->available())->isPositive();
    }

    /**
     * Takes up to the metered amount, never more than the run's budget or the wallet holds.
     * Included credits go first because they lapse.
     *
     * @return string What was actually charged.
     */
    public function charge(AgentRun $run, string $credits): string
    {
        return DB::transaction(function () use ($run, $credits) {
            $wallet = $this->lockedWallet(Organization::findOrFail($run->organization_id));

            $included = Dec::of($wallet->included_balance);
            $purchased = Dec::of($wallet->purchased_balance);
            $budgetLeft = Dec::of($run->max_credits)->minus(Dec::of($run->credits_used));

            $charge = $this->smallest(Dec::of($credits), $budgetLeft, $included->plus($purchased));

            if (! $charge->isPositive()) {
                return '0.0000';
            }

            $fromIncluded = $this->smallest($charge, $included);

            $wallet->included_balance = (string) $included->minus($fromIncluded);
            $wallet->purchased_balance = (string) $purchased->minus($charge->minus($fromIncluded));
            $wallet->save();

            $this->post($wallet, 'usage', $charge->negated(), ['agent_run_id' => $run->id]);

            return (string) $charge->toScale(4);
        });
    }

    /** @return array{base:string,gst:string,total:string,credits:int} */
    public function quote(string $pack): array
    {
        $option = config("modules.agents.packs.{$pack}");

        if (! $option) {
            throw ValidationException::withMessages(['pack' => __('Choose a credit pack.')]);
        }

        $price = Dec::money($option['price']);
        $pct = config('buildos.subscription_gst_pct');

        [$base, $gst, $total] = match (config('buildos.subscription_gst_mode')) {
            'none' => [$price, '0.00', $price],
            'included' => $this->splitIncludedGst($price, $pct),
            default => $this->addGst($price, $pct),
        };

        return ['base' => $base, 'gst' => $gst, 'total' => $total, 'credits' => (int) $option['credits']];
    }

    /** @return array{0:string,1:string,2:string} */
    private function addGst(string $price, mixed $pct): array
    {
        $gst = Dec::percentOf($price, $pct);

        return [$price, $gst, Dec::money(Dec::of($price)->plus(Dec::of($gst)))];
    }

    /** @return array{0:string,1:string,2:string} GST is the remainder, so the parts add back to the price. */
    private function splitIncludedGst(string $price, mixed $pct): array
    {
        $base = Dec::beforePercent($price, $pct);

        return [$base, Dec::money(Dec::of($price)->minus(Dec::of($base))), $price];
    }

    public function startTopup(Organization $organization, User $user, string $pack): AgentCreditTopup
    {
        $quote = $this->quote($pack);

        $topup = AgentCreditTopup::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'pack' => $pack,
            'credits' => $quote['credits'],
            'base_amount' => $quote['base'],
            'gst_amount' => $quote['gst'],
            'total_amount' => $quote['total'],
        ]);

        $order = $this->gateway->createOrder($topup->paise(), 'n360-cr-'.$topup->id, [
            'organization_id' => (string) $organization->id,
            'kind' => 'ai_credits',
        ]);

        $topup->update(['gateway_order_id' => $order['id']]);

        return $topup;
    }

    public function confirmCheckout(AgentCreditTopup $topup, string $paymentId, string $signature): AgentCreditTopup
    {
        if (! $this->gateway->checkoutSignatureIsValid((string) $topup->gateway_order_id, $paymentId, $signature)) {
            throw ValidationException::withMessages(['payment' => __('We could not verify this payment.')]);
        }

        return $this->markPaid($topup, $paymentId);
    }

    /** Safe to call twice: the browser and the webhook can both report one payment. */
    public function markPaid(AgentCreditTopup $topup, string $paymentId): AgentCreditTopup
    {
        return DB::transaction(function () use ($topup, $paymentId) {
            $topup = AgentCreditTopup::withoutGlobalScopes()->lockForUpdate()->findOrFail($topup->id);

            if ($topup->status === 'paid') {
                return $topup;
            }

            $wallet = $this->lockedWallet(Organization::findOrFail($topup->organization_id));
            $wallet->purchased_balance = (string) Dec::of($wallet->purchased_balance)->plus(Dec::of($topup->credits));
            $wallet->save();

            $this->post($wallet, 'topup', Dec::of($topup->credits), ['credit_topup_id' => $topup->id]);

            $topup->update(['status' => 'paid', 'gateway_payment_id' => $paymentId, 'paid_at' => now()]);
            AuditLog::record('credits.topup_paid', $topup, null, ['credits' => $topup->credits, 'total' => $topup->total_amount]);

            return $topup;
        });
    }

    /** @param array<string,mixed> $entity Razorpay's payment entity. */
    public function settleWebhook(array $entity): string
    {
        $topup = AgentCreditTopup::withoutGlobalScopes()->where('gateway_order_id', $entity['order_id'] ?? '')->first();

        if (! $topup) {
            return 'ignored';
        }

        if ((string) ($entity['amount'] ?? '') !== $topup->paise()) {
            Log::warning('Razorpay webhook amount mismatch on a credit top-up', ['topup' => $topup->id, 'amount' => $entity['amount'] ?? null]);

            return 'amount_mismatch';
        }

        $this->markPaid($topup, (string) $entity['id']);

        return 'ok';
    }

    /** Must run inside a transaction. Applies the new month's allowance if it has not been yet. */
    private function lockedWallet(Organization $organization): AgentCreditWallet
    {
        $find = fn () => AgentCreditWallet::withoutGlobalScopes()->where('organization_id', $organization->id);

        if (! $find()->exists()) {
            Idempotent::create(
                fn () => AgentCreditWallet::create(['organization_id' => $organization->id]),
                fn () => $find()->first(),
            );
        }

        $wallet = $find()->lockForUpdate()->firstOrFail();
        $period = now('Asia/Kolkata')->format('Y-m');

        // "Y-m" strings sort by date. Never grant again if a clock steps backwards.
        if ($wallet->included_period !== null && $wallet->included_period >= $period) {
            return $wallet;
        }

        $lapsed = Dec::of($wallet->included_balance);

        if ($lapsed->isPositive()) {
            $wallet->included_balance = '0';
            $wallet->included_period = $period;
            $wallet->save();
            $this->post($wallet, 'included_lapsed', $lapsed->negated());
        }

        $allowance = Dec::of(config('modules.agents.plan_credits.'.$organization->effectivePlan(), 0));
        $wallet->included_balance = (string) $allowance;
        $wallet->included_period = $period;
        $wallet->save();

        if ($allowance->isPositive()) {
            $this->post($wallet, 'included_grant', $allowance);
        }

        return $wallet;
    }

    private function post(AgentCreditWallet $wallet, string $type, BigDecimal $credits, array $links = []): void
    {
        AgentCreditLedger::create([
            'organization_id' => $wallet->organization_id,
            'credit_wallet_id' => $wallet->id,
            'type' => $type,
            'credits' => (string) $credits,
            'balance_after' => $wallet->available(),
            ...$links,
        ]);
    }

    private function smallest(BigDecimal ...$values): BigDecimal
    {
        $min = array_shift($values);

        foreach ($values as $value) {
            $min = $value->isLessThan($min) ? $value : $min;
        }

        return $min;
    }
}
