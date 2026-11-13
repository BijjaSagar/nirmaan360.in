<?php

namespace App\Services\BillCheck;

use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\RaBill;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stands in front of "submit for approval" for a company that requires the pre-check.
 *
 * A stored check goes stale while the bill itself stays the same, because most findings depend on
 * other records: earlier bills, today's contract form and rates, measurements, the approval rule and
 * the confirmed terms. So the gate never trusts the stored check. It runs the check again at the
 * moment of submitting and lets the bill through only when every finding that needs an
 * acknowledgement is one that was acknowledged, with the same figures.
 *
 * A company that has not asked for this is not touched at all.
 */
class BillCheckGate
{
    public function __construct(private RaBillPreCheck $preCheck, private BillChecks $checks, private FlagText $text) {}

    /** @throws ValidationException When the company requires the check and something is new, changed or not acknowledged. */
    public function assertMaySubmit(RaBill $bill, User $by): void
    {
        if (! $this->checks->isRequired($bill->organization_id)) {
            return;
        }

        $refusal = null;
        $toStore = null;
        $stored = null;

        DB::transaction(function () use ($bill, $by, &$refusal, &$toStore, &$stored) {
            $bill = $this->checks->locked($bill);

            // A bill that is past draft is not this gate's business. Submitting it is refused a line later, for its own reason.
            if (! $bill->isEditable()) {
                return;
            }

            $fresh = $this->preCheck->inspect($bill);
            $latest = $this->checks->latest($bill);

            $known = collect($latest?->flags ?? [])->mapWithKeys(fn (array $flag) => [(string) ($flag['key'] ?? '') => Flag::fromArray($flag)->hash()]);
            $acknowledged = $latest === null ? [] : BillCheckAck::withoutGlobalScopes()->where('bill_check_id', $latest->id)->pluck('flag_key')->all();

            $changed = [];
            $open = [];

            foreach ($fresh->toAcknowledge() as $flag) {
                if ($known->get($flag->key) !== $flag->hash()) {
                    $changed[] = $flag;
                } elseif (! in_array($flag->key, $acknowledged, true)) {
                    $open[] = $flag;
                }
            }

            if ($latest === null) {
                // Nobody has checked this bill. The run just made is kept, so the bill page shows what was found.
                $toStore = $fresh;
                $refusal = $changed === [] ? null : __('Check this bill before submitting it. Your company requires every finding of the check to be acknowledged first.');
            } elseif ($changed !== []) {
                $toStore = $fresh;
                $refusal = __('Something this bill depends on has changed since the check. Check it again.').' '
                    .__('New or changed: :finding', ['finding' => $this->text->line($changed[0], Money::visible($by))])
                    .(count($changed) > 1 ? ' '.__('And :count more.', ['count' => count($changed) - 1]) : '');
            } elseif ($open !== []) {
                $refusal = __('Acknowledge every finding of the check before submitting this bill. Still open: :count.', ['count' => count($open)]);
            }

            if ($toStore !== null) {
                $stored = $this->preCheck->store($bill, $toStore, $by);
            }
        });

        if ($refusal === null) {
            return;
        }

        if ($stored !== null) {
            $this->keepAfterRollBack($bill, $stored, $toStore, $by);
        }

        throw ValidationException::withMessages(['check' => $refusal]);
    }

    /**
     * A caller that wraps the submit in its own transaction, as the agent tool runner does, rolls
     * the fresh check back together with the refusal. It is written again once that has happened,
     * so the bill page still shows what changed. With no outer transaction this does nothing.
     */
    private function keepAfterRollBack(RaBill $bill, BillCheck $stored, CheckResult $result, User $by): void
    {
        DB::afterRollBack(function () use ($bill, $stored, $result, $by) {
            try {
                if (! BillCheck::withoutGlobalScopes()->whereKey($stored->id)->exists()) {
                    $this->preCheck->store($bill, $result, $by);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
