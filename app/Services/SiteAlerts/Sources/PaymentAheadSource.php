<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\Project;
use App\Services\ContractorControl\Figures;
use App\Services\ContractorControl\WorkPaymentRatio;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use App\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * A contractor paid ahead of the work, beyond the project's limit: the classic "paid 90%, work 70%", or
 * anything paid on a contract with no value. The line is WorkPaymentRatio's over_limit, which
 * PaymentAheadGuard also holds at a payment's final approval, so the alert and the approval agree.
 * The figures come from contractor_control's WorkPaymentRatio. Payments are money, so the alert
 * needs money.view and site staff never see it.
 */
class PaymentAheadSource implements AlertSource
{
    public const TYPE = 'payment.ahead';

    public function __construct(private WorkPaymentRatio $ratio) {}

    public function types(): array
    {
        return [self::TYPE];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        foreach ($this->ratio->forProject($project) as $row) {
            if (! $row['over_limit']) {
                continue;
            }

            $workOrder = $row['work_order'];
            $gap = Dec::of($row['gap_pct']);
            $limit = Dec::of($row['limit_pct']);

            // Paid on a contract with no value: none of it is covered by work done, and its percentages are both 0.
            if (Dec::of($row['contract_value'])->isZero()) {
                yield new Finding(
                    type: self::TYPE,
                    title: 'Payment ahead of work',
                    message: ':contract (:party): paid on a contract with no value, so none of the payment is covered by work done.',
                    params: [
                        'contract' => $workOrder->number,
                        'party' => $row['party']?->name ?? '—',
                    ],
                    reference: 'work_order:'.$workOrder->id,
                    severity: Alert::CRITICAL,
                    data: Arr::only($row, ['contract_value', 'paid', 'paid_pct', 'work_pct', 'gap_pct', 'limit_pct', 'balance_work_pct', 'balance_work_value', 'balance_money', 'short_by']),
                    needs: 'money.view',
                    url: route('contractor-control.contracts.show', $workOrder),
                );

                continue;
            }

            yield new Finding(
                type: self::TYPE,
                title: 'Payment ahead of work',
                message: ':contract (:party): paid :paid%, work :work%, :gap% ahead against a :limit% limit.',
                params: [
                    'contract' => $workOrder->number,
                    'party' => $row['party']?->name ?? '—',
                    'paid' => Figures::pct($row['paid_pct']),
                    'work' => Figures::pct($row['work_pct']),
                    'gap' => Figures::pct($row['gap_pct']),
                    'limit' => Figures::pct($row['limit_pct']),
                ],
                reference: 'work_order:'.$workOrder->id,
                severity: $gap->isGreaterThanOrEqualTo($limit->multipliedBy(2)) ? Alert::CRITICAL : Alert::WARNING,
                data: Arr::only($row, ['contract_value', 'paid', 'paid_pct', 'work_pct', 'gap_pct', 'limit_pct', 'balance_work_pct', 'balance_work_value', 'balance_money', 'short_by']),
                needs: 'money.view',
                url: route('contractor-control.contracts.show', $workOrder),
            );
        }
    }
}
