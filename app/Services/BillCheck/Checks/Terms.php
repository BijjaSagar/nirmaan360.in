<?php

namespace App\Services\BillCheck\Checks;

use App\Models\ContractTerm;
use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Services\ContractTerms\ContractTerms;
use App\Services\Finance\SettlementService;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Holds the bill against the dated contract terms people have confirmed (App\Services\ContractTerms).
 *
 * The app has no percentage, amount or period of its own. Every figure here is one a person typed
 * from the agreement and confirmed, in force on the day in question, or the one saved on the bill.
 * A term changes no bill: deductions that are not on the bill are only listed beside it.
 */
final class Terms extends Check
{
    public function __construct(private ContractTerms $terms) {}

    public function run(BillFacts $facts): array
    {
        return $this->inspect($facts)[0];
    }

    /** @return array{0: list<Flag>, 1: array<string, mixed>|null} The findings, and the sheet of deductions that are not on the bill. */
    public function inspect(BillFacts $facts): array
    {
        if ($facts->contractMissing()) {
            return [[], null];
        }

        $deductions = $this->terms->effective($facts->project, $facts->workOrder, $facts->bill->period_to, 'deduction');
        $sheet = $this->sheet($facts, $deductions->reject(fn (ContractTerm $term) => isset(ContractTerm::BILL_PERCENTAGES[$term->code]))->values());

        $flags = [...$this->billPercentages($facts, $deductions), ...$this->paymentsOverdue($facts)];

        if ($sheet !== null) {
            $flags[] = $this->flag($facts->isClient() ? 'T3' : 'T3.sub', 'T3', [
                'count' => count($sheet['lines']), 'total' => $sheet['total'], 'receipt' => $sheet['expected_receipt'],
            ]);
        }

        return [$flags, $sheet];
    }

    /**
     * T1 and T2, for the four percentages the bill works out itself. Without a confirmed term in
     * force at the end of the bill period, the percentage is only what was typed on the contract
     * form. With one, the two are compared as numbers.
     *
     * A final bill holds no retention and recovers the whole advance, so those two are left out
     * there. A bill raised without GST is left to F4 while no GST term is confirmed.
     *
     * @param  Collection<int, ContractTerm>  $deductions
     * @return list<Flag>
     */
    private function billPercentages(BillFacts $facts, Collection $deductions): array
    {
        $flags = [];

        foreach (ContractTerm::BILL_PERCENTAGES as $code => $key) {
            if ($facts->bill->is_final && in_array($code, ['retention', 'advance_recovery'], true)) {
                continue;
            }

            $onBill = Dec::of($facts->terms[$key] ?? 0);
            $confirmed = $deductions->where('code', $code)->whereNotNull('pct');

            if ($confirmed->isEmpty()) {
                $withoutGst = $code === 'gst' && $onBill->isZero() && Dec::of($facts->termsToday()[$key] ?? 0)->isPositive();

                if (! $withoutGst) {
                    $flags[] = $this->flag('T1', "T1:{$code}", ['head' => $code, 'pct' => BillFacts::pct($onBill)]);
                }

                continue;
            }

            foreach ($confirmed as $term) {
                if (! Dec::of($term->pct)->isEqualTo($onBill)) {
                    $flags[] = $this->flag('T2', "T2:{$code}:{$term->id}", [
                        'head' => $code, 'bill' => BillFacts::pct($onBill), 'term' => BillFacts::pct($term->pct),
                        'source' => (string) $term->source_ref, 'from' => $term->effective_from->toDateString(),
                    ]);
                }
            }
        }

        return $flags;
    }

    /**
     * T3: one line for each confirmed deduction that the bill does not work out, with its amount on
     * the base the term names. Each line is worked out on its own from this bill; one does not lower
     * the amount another is worked out on. A term whose amount is typed on each bill has no figure.
     *
     * A limit on a deduction is its highest total over the whole contract, so what the same term
     * would have taken on the earlier bills is counted first and only the room left is used.
     *
     * @param  Collection<int, ContractTerm>  $others
     * @return array<string, mixed>|null
     */
    private function sheet(BillFacts $facts, Collection $others): ?array
    {
        if ($others->isEmpty()) {
            return null;
        }

        $lines = [];
        $total = BigDecimal::zero();
        $unpriced = 0;

        foreach ($others as $term) {
            $line = [
                'term_id' => $term->id, 'code' => $term->code, 'label' => $term->label, 'method' => $term->method,
                'pct' => $term->pct === null ? null : BillFacts::pct($term->pct), 'base' => $term->base, 'base_amount' => null,
                'fixed_amount' => $term->amount === null ? null : Dec::money($term->amount),
                'cap_amount' => $term->cap_amount === null ? null : Dec::money($term->cap_amount), 'earlier_amount' => null,
                'nature' => $term->nature, 'source_ref' => $term->source_ref,
                'effective_from' => $term->effective_from->toDateString(), 'effective_to' => $term->effective_to?->toDateString(),
                'amount' => null,
            ];

            if ($term->method === 'percent' && $term->pct !== null) {
                $base = $facts->baseAmount((string) $term->base);
                $amount = $this->share($base, $term);

                if ($term->cap_amount !== null) {
                    $cap = Dec::of($term->cap_amount);
                    $earlier = $this->takenBefore($facts, $term, $cap);
                    $room = $cap->minus($earlier);
                    $amount = $amount->isGreaterThan($room) ? $room : $amount;
                    $line['earlier_amount'] = Dec::money($earlier);
                }

                $line['base_amount'] = Dec::money($base);
                $line['amount'] = Dec::money($amount);
            } elseif ($term->method === 'fixed_per_bill' && $term->amount !== null) {
                $line['amount'] = Dec::money($term->amount);
            }

            if ($line['amount'] === null) {
                $unpriced++;
            } else {
                $total = $total->plus(Dec::of($line['amount']));
            }

            $lines[] = $line;
        }

        return [
            'party' => $facts->isClient() ? 'client' : 'subcontractor',
            'lines' => $lines,
            'unpriced' => $unpriced,
            'total' => Dec::money($total),
            'net_payable' => Dec::money($facts->bill->net_payable),
            'expected_receipt' => Dec::money(Dec::of($facts->bill->net_payable)->minus($total)),
        ];
    }

    /** The term's percentage of a base, never below nothing. */
    private function share(BigDecimal $base, ContractTerm $term): BigDecimal
    {
        $amount = Dec::of(Dec::percentOf($base, $term->pct));

        return $amount->isNegative() ? BigDecimal::zero() : $amount;
    }

    /** What this term would have taken on the earlier bills it covers, stopping at its limit. */
    private function takenBefore(BillFacts $facts, ContractTerm $term, BigDecimal $cap): BigDecimal
    {
        $taken = BigDecimal::zero();

        foreach ($facts->liveEarlier() as $earlier) {
            if ($term->covers($earlier->period_to)) {
                $taken = $taken->plus($this->share($facts->baseAmount((string) $term->base, $earlier), $term));
            }
        }

        return $taken->isGreaterThan($cap) ? $cap : $taken;
    }

    /**
     * T4: earlier approved client bills that are not fully paid although the payment period a person
     * confirmed for the contract has run out. The period is counted from the bill's date, which is
     * the day it was approved, as the finance screens date it. Read from the finance records, so a
     * company without that module has nothing to compare.
     *
     * @return list<Flag>
     */
    private function paymentsOverdue(BillFacts $facts): array
    {
        if (! $facts->isClient() || ! $facts->organization->allowsFeature('finance') || ! class_exists(SettlementService::class)) {
            return [];
        }

        $flags = [];

        foreach (app(SettlementService::class)->clientBills($facts->bill->organization_id, $facts->bill->project_id) as $position) {
            $earlier = $position['bill'];

            if ($earlier->id >= $facts->bill->id || ! Dec::of($position['outstanding'])->isPositive()) {
                continue;
            }

            $billed = CarbonImmutable::parse(($earlier->approved_at ?? $earlier->period_to)->toDateString());
            $term = $this->terms->effective($facts->project, null, $billed, 'payment_period')->first(fn (ContractTerm $term) => $term->days !== null);

            if ($term === null) {
                continue;
            }

            $due = $billed->addDays($term->days);

            if ($due->lessThan($facts->today)) {
                $flags[] = $this->flag('T4', "T4:{$earlier->id}", [
                    'number' => $earlier->number, 'due' => $due->toDateString(),
                    'outstanding' => Dec::money($position['outstanding']), 'source' => (string) $term->source_ref,
                ]);
            }
        }

        return $flags;
    }
}
