<?php

namespace App\Services\Payroll;

use App\Support\Dec;
use Brick\Math\RoundingMode;

/**
 * Turns a worker's attendance marks into days and pay. No database, so every rule can be tested alone.
 *
 * Present and overtime count as a full day, half counts as half a day, absent counts as nothing.
 * Overtime hours earn pay on any day the worker came, at the hourly rate (daily wage / shift hours)
 * times the overtime multiplier.
 */
final class WageCalculator
{
    /**
     * @param  iterable<array{status:string, ot_hours?:mixed}|object>  $marks
     * @return array{present:string, half:string, absent:string, payable:string, ot_hours:string}
     */
    public function tally(iterable $marks): array
    {
        $present = $half = $absent = $payable = $overtime = Dec::of(0);

        foreach ($marks as $mark) {
            $status = is_array($mark) ? $mark['status'] : $mark->status;
            $hours = is_array($mark) ? ($mark['ot_hours'] ?? 0) : $mark->ot_hours;

            if ($status === 'absent') {
                $absent = $absent->plus(1);

                continue;
            }

            if ($status === 'half') {
                $half = $half->plus(1);
                $payable = $payable->plus('0.5');
            } else {
                $present = $present->plus(1);
                $payable = $payable->plus(1);
            }

            $overtime = $overtime->plus(Dec::of($hours));
        }

        return [
            'present' => Dec::qty($present),
            'half' => Dec::qty($half),
            'absent' => Dec::qty($absent),
            'payable' => Dec::qty($payable),
            'ot_hours' => Dec::qty($overtime),
        ];
    }

    /**
     * @param  array{payable:string, ot_hours:string}  $tally
     * @param  array{shift_hours:mixed, ot_multiplier:mixed}  $terms
     * @return array{day_pay:string, ot_pay:string, gross:string}
     */
    public function pay(mixed $dailyWage, array $tally, array $terms): array
    {
        $wage = Dec::of($dailyWage);
        $dayPay = Dec::money($wage->multipliedBy(Dec::of($tally['payable'])));

        // Rounded once, on the whole overtime amount, so paise do not pile up hour by hour.
        $otPay = Dec::money(
            $wage->multipliedBy(Dec::of($tally['ot_hours']))
                ->multipliedBy(Dec::of($terms['ot_multiplier']))
                ->dividedBy(Dec::of($terms['shift_hours']), 8, RoundingMode::HalfUp)
        );

        return [
            'day_pay' => $dayPay,
            'ot_pay' => $otPay,
            'gross' => Dec::money(Dec::of($dayPay)->plus(Dec::of($otPay))),
        ];
    }
}
