<?php

namespace App\Services;

use App\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * Works out RA bill deductions in the order set on the contract.
 *
 * Each percentage applies to the gross amount, or to the balance left after the
 * earlier deductions when the basis is "running". GST is charged on the gross
 * (taxable) value, and net payable = gross − deductions + GST.
 */
class DeductionEngine
{
    private const LABELS = [
        'retention' => 'Retention',
        'advance' => 'Advance recovery',
        'material' => 'Material recovery',
        'tds' => 'TDS',
    ];

    /**
     * @param  array{retention_pct?:mixed, tds_pct?:mixed, gst_pct?:mixed, advance_balance?:mixed, advance_recovery_pct?:mixed, material_recovery?:mixed, order?:list<string>, basis?:string}  $terms
     * @return array{deductions:list<array{type:string,label:string,pct:?string,amount:string}>, deduction_total:string, gst_amount:string, net_payable:string}
     */
    public function compute(mixed $gross, array $terms): array
    {
        $gross = Dec::of($gross);
        $running = $gross;
        $total = BigDecimal::zero();
        $basis = $terms['basis'] ?? 'gross';
        $deductions = [];

        foreach ($terms['order'] ?? config('buildos.default_deduction_order') as $type) {
            $base = $basis === 'running' ? $running : $gross;

            // A final bill can recover more advance than the work is worth. Percentages then have nothing to apply to.
            if (! empty($terms['recover_full_advance']) && $base->isNegative()) {
                $base = BigDecimal::zero();
            }

            [$pct, $amount] = $this->amountFor($type, $base, $terms);

            if (Dec::of($amount)->isZero()) {
                continue;
            }

            $deductions[] = ['type' => $type, 'label' => self::LABELS[$type], 'pct' => $pct, 'amount' => $amount];
            $running = $running->minus(Dec::of($amount));
            $total = $total->plus(Dec::of($amount));
        }

        $gst = Dec::percentOf($gross, $terms['gst_pct'] ?? 0);

        return [
            'deductions' => $deductions,
            'deduction_total' => Dec::money($total),
            'gst_amount' => $gst,
            'net_payable' => Dec::money($gross->minus($total)->plus(Dec::of($gst))),
        ];
    }

    /** @return array{0:?string,1:string} Percentage used and the amount. */
    private function amountFor(string $type, BigDecimal $base, array $terms): array
    {
        return match ($type) {
            'retention' => [Dec::money($terms['retention_pct'] ?? 0), Dec::percentOf($base, $terms['retention_pct'] ?? 0)],
            'tds' => [Dec::money($terms['tds_pct'] ?? 0), Dec::percentOf($base, $terms['tds_pct'] ?? 0)],
            'material' => [null, Dec::money($terms['material_recovery'] ?? 0)],
            'advance' => $this->advanceRecovery($base, $terms),
            default => throw new \InvalidArgumentException("Unknown deduction type [{$type}]."),
        };
    }

    /** Recovery is a percentage of the base, capped at the advance still outstanding. A final bill recovers all of it. */
    private function advanceRecovery(BigDecimal $base, array $terms): array
    {
        $pct = Dec::money($terms['advance_recovery_pct'] ?? 0);
        $amount = Dec::of(Dec::percentOf($base, $pct));
        $balance = Dec::of($terms['advance_balance'] ?? 0);

        if ($balance->isNegativeOrZero()) {
            return [$pct, '0.00'];
        }

        if (! empty($terms['recover_full_advance'])) {
            return [null, Dec::money($balance)];
        }

        return [$pct, Dec::money($amount->isGreaterThan($balance) ? $balance : $amount)];
    }
}
