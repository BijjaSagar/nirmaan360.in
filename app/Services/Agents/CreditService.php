<?php

namespace App\Services\Agents;

use App\Models\AgentCreditLedger;
use App\Models\AgentCreditTopup;
use App\Models\AgentCreditWallet;
use App\Models\AgentRun;
use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\InsufficientCredits;
use App\Services\DocumentReading\SpendRefused;
use App\Services\RazorpayGateway;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Each company's prepaid credits: a monthly allowance from the plan, then anything it has bought.
 *
 * A document read puts its limit on hold before it runs, and a text call holds its maximum while it
 * waits. Held credits are still in the wallet, but nothing else may spend them: "free" is the
 * wallet less everything on hold.
 *
 * Locks are always taken in the same order: the wallet first, then the read. releaseRead and
 * burnRead move no money, so they lock the read alone and may be called with it already locked.
 */
class CreditService
{
    public function __construct(private RazorpayGateway $gateway, private AiSpend $spend) {}

    public function wallet(Organization $organization): AgentCreditWallet
    {
        return DB::transaction(fn () => $this->lockedWallet($organization));
    }

    public function hasCredits(Organization $organization): bool
    {
        return Dec::of($this->free($organization))->isPositive();
    }

    /**
     * Credits on hold: what the company's running reads hold, plus the maximum of each text call
     * that is waiting or running. Call it inside the wallet lock.
     */
    public function held(Organization $organization): string
    {
        return (string) $this->heldExcept($organization, null)->toScale(4, RoundingMode::Up);
    }

    /** What may be spent or put on hold now: the wallet less everything on hold, never below zero. */
    public function free(Organization $organization): string
    {
        return DB::transaction(fn () => (string) $this->freeIn($this->lockedWallet($organization), $organization)->toScale(4, RoundingMode::Down));
    }

    /**
     * Takes up to the metered amount, never more than the run's budget or the wallet has free.
     * Included credits go first because they lapse.
     *
     * @return string What was actually charged.
     */
    public function charge(AgentRun $run, string $credits): string
    {
        return DB::transaction(function () use ($run, $credits) {
            $organization = Organization::findOrFail($run->organization_id);
            $wallet = $this->lockedWallet($organization);

            $budgetLeft = Dec::of($run->max_credits)->minus(Dec::of($run->credits_used));

            // Credits a document read or a text call has on hold are not an agent's to spend.
            $charge = $this->smallest(Dec::of($credits), $budgetLeft, $this->freeIn($wallet, $organization));

            if (! $charge->isPositive()) {
                return '0.0000';
            }

            $this->debit($wallet, $charge);
            $this->post($wallet, 'usage', $charge->negated(), ['agent_run_id' => $run->id]);

            return (string) $charge->toScale(4);
        });
    }

    /**
     * Puts a read's limit on hold and marks the read as running, in one step, because only a running
     * read holds credits. An earlier hold of the same read is replaced, not added to.
     *
     * The caller sets everything else about the start (the cap, the page range, the dates) in the
     * same transaction, and must not lock the read's row before calling this.
     *
     * @param  string  $credits  At a start, the read's cap. At a resume, no more than $read->capLeft().
     * @param  User  $by  The person acting now. Their share of the wallet limits the hold.
     * @param  ?User  $bookedTo  The person the read is booked to from now on, when that is not $by: the
     *                           hold and the pages count against this person's daily budget, so this
     *                           is the budget that is checked.
     *
     * @throws SpendRefused When a platform or company limit or a daily budget stops it.
     * @throws InsufficientCredits When the wallet's free balance is smaller, or this person may not hold that much of it.
     */
    public function hold(DocumentRead $read, string $credits, User $by, ?User $bookedTo = null): void
    {
        $asked = Dec::of($credits)->toScale(4, RoundingMode::Up);
        $bookedTo ??= $by;

        if (! $asked->isPositive()) {
            throw new InvalidArgumentException('A hold is for more than nothing.');
        }

        if ($by->organization_id !== $read->organization_id || $bookedTo->organization_id !== $read->organization_id) {
            throw new LogicException('A read is started by someone of its own company.');
        }

        DB::transaction(function () use ($read, $asked, $by, $bookedTo) {
            $organization = Organization::findOrFail($read->organization_id);
            $wallet = $this->lockedWallet($organization);
            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);

            if ($locked->isClosed()) {
                throw new LogicException("A {$locked->status} read cannot hold credits.");
            }

            $this->spend->assertRoom($organization, $bookedTo, $locked->kind, (string) $asked, $locked, $by);

            [$free, $own] = $this->reach($wallet, $organization, $by, $locked->id);

            if ($asked->isGreaterThan($free)) {
                throw new InsufficientCredits(InsufficientCredits::WALLET);
            }

            if ($asked->isGreaterThan($own)) {
                throw new InsufficientCredits(InsufficientCredits::HOLD_SHARE);
            }

            $before = ['status' => $locked->status, 'credits_held' => (string) $locked->credits_held];

            $this->change($locked, $read, ['credits_held' => (string) $asked, 'status' => DocumentRead::RUNNING, 'pause_reason' => null]);

            AuditLog::record('ai.read_hold', $locked, $before, ['status' => DocumentRead::RUNNING, 'credits_held' => (string) $asked], $by, $locked->organization_id);

            $this->spend->afterSpend($organization, $locked->kind, (string) $free->minus($asked));
        });
    }

    /**
     * The most this person could put on hold for a read right now. For an owner or admin that is the
     * free balance. Anyone else may not push the company's open holds above the set share of the
     * wallet, so the rest stays within reach of the agents and the morning brief.
     *
     * @param  ?DocumentRead  $for  A read whose present hold would be replaced, so it is not counted twice.
     */
    public function room(Organization $organization, User $by, ?DocumentRead $for = null): string
    {
        return DB::transaction(fn () => (string) $this->reach($this->lockedWallet($organization), $organization, $by, $for?->id)[1]->toScale(4, RoundingMode::Down));
    }

    /**
     * Charges one page that was read: the metered amount, but never more than the read still holds
     * or the wallet has. Whatever could not be charged is the platform's loss, is counted against
     * the read's limit, and pauses the read: with 'wallet' when the wallet ran out, otherwise with
     * 'credit_cap'. A read whose hold is used up exactly is paused with 'credit_cap' as well.
     *
     * The page row must already carry this attempt's number and its cost_usd. A second call for the
     * same attempt changes nothing and returns what the first one charged.
     *
     * Call it before locking the read's row. After it, the read may be paused even if that was its
     * last page; whoever finishes reads decides what becomes of it.
     *
     * @param  string  $metered  CostMeter::credits() of the call.
     * @return string What was actually charged.
     */
    public function chargeRead(DocumentRead $read, DocumentReadPage $page, string $metered): string
    {
        $this->assertPageOf($read, $page);

        return DB::transaction(function () use ($read, $page, $metered) {
            $organization = Organization::findOrFail($read->organization_id);
            $wallet = $this->lockedWallet($organization);

            // The row as it is saved now: the caller's copy may be older than the attempt it reports.
            $row = DocumentReadPage::withoutGlobalScopes()->lockForUpdate()->findOrFail($page->id);
            $reference = "dr:{$read->id}:{$row->id}:{$row->attempts}";

            if ($earlier = $this->posted($wallet, $reference)) {
                return $this->amountOf($earlier);
            }

            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);
            $metered = $this->metered($metered);
            $held = $locked->status === DocumentRead::RUNNING ? Dec::of($locked->credits_held) : BigDecimal::zero();
            $inWallet = Dec::of($wallet->available());
            $charge = $this->smallest($metered, $held, $inWallet);

            if ($charge->isPositive()) {
                $this->debit($wallet, $charge, save: false);
                $entry = $this->postOnce($wallet, $charge, $reference, ['document_read_id' => $read->id]);

                // Another worker wrote this charge between the look above and now. Its figures stand.
                if (! $entry->wasRecentlyCreated) {
                    return $this->amountOf($entry);
                }

                $wallet->save();
            } else {
                $charge = BigDecimal::zero();
            }

            $shortfall = $metered->minus($charge);
            $left = $held->minus($charge);

            $changes = [
                'credits_held' => (string) $left,
                'credits_used' => (string) Dec::of($locked->credits_used)->plus($charge),
                'last_progress_at' => now(),
            ];

            if ($shortfall->isPositive()) {
                $changes['credits_burned'] = (string) Dec::of($locked->credits_burned)->plus($shortfall);
                $changes['absorbed_usd'] = (string) Dec::of($locked->absorbed_usd)->plus(self::uncharged((string) $row->cost_usd, (string) $metered, (string) $charge));
            }

            $this->change($locked, $read, $changes);

            // Everything this row was ever charged, so the pages of a read add up to what the read used.
            $row->forceFill(['credits' => (string) Dec::of($row->credits)->plus($charge)])->save();
            $this->show($page, $row, ['credits']);

            if ($shortfall->isPositive() || ! $left->isPositive()) {
                $this->pause($locked, $read, $shortfall->isPositive() && $inWallet->isLessThan($held) ? DocumentRead::PAUSE_WALLET : DocumentRead::PAUSE_CREDIT_CAP);
            }

            $this->spend->afterSpend($organization, $locked->kind, (string) $this->freeIn($wallet, $organization));

            return (string) $charge->toScale(4);
        });
    }

    /**
     * The part of a call's provider cost that matches the credits nobody could be charged for: all
     * of it when nothing was charged, none of it when the whole metered amount was.
     *
     * @param  string  $usd  The call's provider cost.
     * @param  string  $metered  CostMeter::credits() of the call.
     * @param  string  $charged  What was actually charged for it.
     */
    public static function uncharged(string $usd, string $metered, string $charged): BigDecimal
    {
        $cost = Dec::of($usd);
        $metered = Dec::of($metered);
        $shortfall = $metered->minus(Dec::of($charged));

        // A shortfall above nothing means the metered amount was above nothing too.
        if (! $cost->isPositive() || ! $shortfall->isPositive()) {
            return BigDecimal::zero()->toScale(6);
        }

        return $cost->multipliedBy($shortfall)->dividedBy($metered, 6, RoundingMode::HalfUp);
    }

    /**
     * A page that failed, or that the reader found blank: nobody is charged. Its metered credits
     * come off the read's hold and count as burned, and its cost is the platform's. When the hold
     * reaches zero the read is paused with 'credit_cap', so a read can never cost the platform and
     * the company together more than its limit.
     *
     * Moves no money and locks only the read, so it may be called with the read already locked.
     *
     * @param  string  $usd  The provider's cost of the call, estimated when it reported none.
     */
    public function burnRead(DocumentRead $read, DocumentReadPage $page, string $metered, string $usd): void
    {
        $this->assertPageOf($read, $page);

        DB::transaction(function () use ($read, $metered, $usd) {
            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);
            $metered = $this->metered($metered);
            $running = $locked->status === DocumentRead::RUNNING;
            $left = $running ? $this->notBelowZero(Dec::of($locked->credits_held)->minus($metered)) : BigDecimal::zero();

            $this->change($locked, $read, [
                'credits_held' => (string) $left,
                'credits_burned' => (string) Dec::of($locked->credits_burned)->plus($metered),
                'absorbed_usd' => (string) Dec::of($locked->absorbed_usd)->plus(Dec::of($usd)->toScale(6, RoundingMode::HalfUp)),
                'last_progress_at' => now(),
            ]);

            if ($running && ! $left->isPositive()) {
                $this->pause($locked, $read, DocumentRead::PAUSE_CREDIT_CAP);
            }
        });
    }

    /**
     * Gives back whatever a read still holds. Call it in the same transaction as every pause, cancel,
     * finish and expiry, and when consent is withdrawn. It does not change the read's status.
     *
     * Moves no money and locks only the read, so it may be called with the read already locked.
     *
     * @return string What was released.
     */
    public function releaseRead(DocumentRead $read): string
    {
        return DB::transaction(function () use ($read) {
            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);
            $released = Dec::of($locked->credits_held);

            $this->change($locked, $read, ['credits_held' => '0']);

            return (string) $released->toScale(4);
        });
    }

    /**
     * Charges a finished text call: the metered amount, but never more than the call's maximum or
     * the wallet has. A call the guards refused, or that failed, is simply not charged: do not call this.
     *
     * Written against the ai_text_calls columns id, organization_id, attempts, max_credits and
     * credits, and the ledger column ai_text_call_id. A second call for the same attempt changes
     * nothing and returns what the first one charged.
     *
     * @return string What was actually charged.
     */
    public function chargeCall(AiTextCall $call, string $metered): string
    {
        return DB::transaction(function () use ($call, $metered) {
            $organization = Organization::findOrFail($call->organization_id);
            $wallet = $this->lockedWallet($organization);

            // The row as it is saved now: the caller's copy may be older than the attempt it reports.
            $locked = $call->newQueryWithoutScopes()->lockForUpdate()->findOrFail($call->id);
            $reference = "tc:{$locked->id}:{$locked->attempts}";

            if ($earlier = $this->posted($wallet, $reference)) {
                return $this->amountOf($earlier);
            }

            $budgetLeft = Dec::of($locked->max_credits)->minus(Dec::of($locked->credits));
            $charge = $this->smallest($this->metered($metered), $budgetLeft, Dec::of($wallet->available()));

            if (! $charge->isPositive()) {
                return '0.0000';
            }

            $this->debit($wallet, $charge, save: false);
            $entry = $this->postOnce($wallet, $charge, $reference, ['ai_text_call_id' => $call->id]);

            if (! $entry->wasRecentlyCreated) {
                return $this->amountOf($entry);
            }

            $wallet->save();

            $credits = (string) Dec::of($locked->credits)->plus($charge);
            $locked->newQueryWithoutScopes()->whereKey($locked->id)->update(['credits' => $credits]);
            $call->setAttribute('credits', $credits)->syncOriginalAttribute('credits');

            // The call still counts as running here, so the balance reported is, if anything, a little low.
            $this->spend->afterSpend($organization, (string) $locked->purpose, (string) $this->freeIn($wallet, $organization));

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

    private function post(AgentCreditWallet $wallet, string $type, BigDecimal $credits, array $links = []): AgentCreditLedger
    {
        return AgentCreditLedger::create([
            'organization_id' => $wallet->organization_id,
            'credit_wallet_id' => $wallet->id,
            'type' => $type,
            'credits' => (string) $credits,
            'balance_after' => $wallet->available(),
            ...$links,
        ]);
    }

    /** The ledger line already written under this reference, if any. Call it inside the wallet lock. */
    private function posted(AgentCreditWallet $wallet, string $reference): ?AgentCreditLedger
    {
        return AgentCreditLedger::withoutGlobalScopes()
            ->where('organization_id', $wallet->organization_id)
            ->where('reference', $reference)
            ->first();
    }

    /**
     * Writes a usage line that can exist only once. The wallet must already show the new balances
     * but not be saved yet: if another worker's line is found instead, nothing of this one is kept.
     */
    private function postOnce(AgentCreditWallet $wallet, BigDecimal $charge, string $reference, array $links): AgentCreditLedger
    {
        return Idempotent::create(
            fn () => $this->post($wallet, 'usage', $charge->negated(), ['reference' => $reference, ...$links]),
            fn () => $this->posted($wallet, $reference),
        );
    }

    /** @return string The credits a usage line took, as a positive figure. */
    private function amountOf(AgentCreditLedger $entry): string
    {
        return (string) Dec::of($entry->credits)->abs()->toScale(4, RoundingMode::HalfUp);
    }

    /** Takes credits out of the wallet, included ones first because they lapse. */
    private function debit(AgentCreditWallet $wallet, BigDecimal $charge, bool $save = true): void
    {
        $included = Dec::of($wallet->included_balance);
        $fromIncluded = $this->smallest($charge, $included);

        $wallet->included_balance = (string) $included->minus($fromIncluded);
        $wallet->purchased_balance = (string) Dec::of($wallet->purchased_balance)->minus($charge->minus($fromIncluded));

        if ($save) {
            $wallet->save();
        }
    }

    /** Everything on hold for the company, leaving out one read whose hold is about to be replaced. */
    private function heldExcept(Organization $organization, ?int $readId): BigDecimal
    {
        $reads = DocumentRead::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', DocumentRead::RUNNING)
            ->when($readId, fn ($query) => $query->where('id', '!=', $readId))
            ->sum('credits_held');

        return Dec::of($reads)->plus($this->spend->heldByTextCalls($organization));
    }

    private function freeIn(AgentCreditWallet $wallet, Organization $organization): BigDecimal
    {
        return $this->notBelowZero(Dec::of($wallet->available())->minus($this->heldExcept($organization, null)));
    }

    private function metered(string $credits): BigDecimal
    {
        return $this->notBelowZero(Dec::of($credits)->toScale(4, RoundingMode::Up));
    }

    /**
     * @return array{0: BigDecimal, 1: BigDecimal} The free balance, and how much of it this person may put on hold.
     */
    private function reach(AgentCreditWallet $wallet, Organization $organization, User $by, ?int $exceptReadId): array
    {
        $available = Dec::of($wallet->available());
        $others = $this->heldExcept($organization, $exceptReadId);
        $free = $this->notBelowZero($available->minus($others));

        if ($this->leads($by)) {
            return [$free, $free];
        }

        $share = $available->multipliedBy(Dec::of(config('modules.document_reading.hold_share')));

        return [$free, $this->smallest($free, $this->notBelowZero($share->minus($others)))];
    }

    private function notBelowZero(BigDecimal $value): BigDecimal
    {
        return $value->isNegative() ? BigDecimal::zero() : $value;
    }

    private function leads(User $user): bool
    {
        return $user->custom_role_id === null && in_array($user->role, User::SEES_ALL_PROJECTS, true);
    }

    private function assertPageOf(DocumentRead $read, DocumentReadPage $page): void
    {
        if ($page->document_read_id !== $read->id || $page->organization_id !== $read->organization_id) {
            throw new LogicException('A page is charged to its own read.');
        }
    }

    /** A read that can pay for nothing more stops, and gives back whatever it still holds. */
    private function pause(DocumentRead $locked, DocumentRead $read, string $reason): void
    {
        if ($locked->status !== DocumentRead::RUNNING) {
            return;
        }

        $this->change($locked, $read, ['status' => DocumentRead::PAUSED, 'pause_reason' => $reason, 'credits_held' => '0']);

        AuditLog::record('ai.read_paused', $locked, ['status' => DocumentRead::RUNNING], ['status' => DocumentRead::PAUSED, 'pause_reason' => $reason], null, $locked->organization_id);
    }

    /**
     * Saves changes on the locked row and shows them on the caller's copy, without touching anything
     * else the caller has set on that copy and not saved yet.
     *
     * @param  array<string, mixed>  $changes
     */
    private function change(DocumentRead $locked, DocumentRead $read, array $changes): void
    {
        $locked->forceFill($changes)->save();
        $this->show($read, $locked, array_keys($changes));
    }

    /**
     * Copies saved values onto another copy of the same row and marks them as saved there.
     *
     * @param  list<string>  $keys
     */
    private function show(Model $copy, Model $saved, array $keys): void
    {
        $copy->setRawAttributes([...$copy->getAttributes(), ...array_intersect_key($saved->getAttributes(), array_flip($keys))]);
        $copy->syncOriginalAttributes($keys);
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
