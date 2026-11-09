<?php

namespace App\Http\Presenters;

use App\Models\ContractorPayment;

/**
 * The only way contractor figures leave the service layer for someone without money.view.
 * Each method names what may go out, so a new money field stays private unless added here.
 */
final class ContractorControlPresenter
{
    /** For screens: who the contract is with and how much work is done, and nothing about money. */
    public static function workOnly(array $row): array
    {
        return [
            'work_order' => $row['work_order'],
            'party' => $row['party'],
            'project' => $row['project'] ?? null,
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];
    }

    public static function workOnlyLine(array $row): array
    {
        return [
            'line' => $row['line'],
            'boq_item' => $row['boq_item'],
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];
    }

    /** For the API. */
    public static function contract(array $row, bool $canSeeMoney): array
    {
        $base = [
            'work_order_id' => $row['work_order']->id,
            'number' => $row['work_order']->number,
            'title' => $row['work_order']->title,
            'contractor' => $row['party']?->name,
            'contract_status' => $row['work_order']->status,
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];

        return $canSeeMoney ? [...$base, ...self::moneyFigures($row)] : $base;
    }

    public static function line(array $row, bool $canSeeMoney): array
    {
        $base = [
            'boq_item_id' => $row['boq_item']?->id,
            'code' => $row['boq_item']?->code,
            'description' => $row['boq_item']?->description,
            'unit' => $row['boq_item']?->unit,
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];

        return $canSeeMoney ? [...$base, ...self::moneyFigures($row)] : $base;
    }

    public static function milestone(array $row, bool $canSeeMoney): array
    {
        $base = [
            'id' => $row['part']->id,
            'title' => $row['part']->title,
            'done' => $row['part']->isDone(),
            'done_on' => $row['part']->done_on?->toDateString(),
        ];

        return $canSeeMoney ? [...$base, 'amount' => $row['amount'], 'paid' => $row['paid'], 'balance' => $row['balance'], 'state' => $row['state']] : $base;
    }

    public static function payment(ContractorPayment $payment, bool $canSeeMoney, ?array $impact = null): array
    {
        $base = [
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status,
            'work_order_id' => $payment->work_order_id,
            'work_order_number' => $payment->workOrder?->number,
            'contractor' => $payment->party?->name,
            'boq_item_id' => $payment->boq_item_id,
            'milestone_id' => $payment->contractor_milestone_id,
            'paid_on' => $payment->paid_on?->toDateString(),
            'entered_by' => $payment->enteredBy?->name,
            'client_uuid' => $payment->client_uuid,
        ];

        if (! $canSeeMoney) {
            return $base;
        }

        return [
            ...$base,
            'amount' => $payment->amount,
            'mode' => $payment->mode,
            'paid_to' => $payment->paid_to,
            'reference' => $payment->reference,
            'is_advance' => $payment->is_advance,
            'notes' => $payment->notes,
            'has_bill' => $payment->bill_path !== null,
            'is_reversal' => $payment->isReversal(),
            'approval_id' => $payment->currentApproval?->id,
            'impact' => $impact ? self::impact($impact) : null,
        ];
    }

    private static function impact(array $impact): array
    {
        return [
            'now' => self::moneyFigures($impact['now']) + ['work_pct' => $impact['now']['work_pct']],
            'after' => $impact['after'] ? self::moneyFigures($impact['after']) + ['work_pct' => $impact['after']['work_pct']] : null,
            'item' => $impact['item'] ? self::moneyFigures($impact['item']) + ['work_pct' => $impact['item']['work_pct']] : null,
        ];
    }

    private static function moneyFigures(array $row): array
    {
        return [
            'contract_value' => $row['contract_value'],
            'paid' => $row['paid'],
            'advance' => $row['advance'] ?? null,
            'paid_pct' => $row['paid_pct'],
            'gap_pct' => $row['gap_pct'],
            'ratio_status' => $row['status'],
            'over_limit' => $row['over_limit'],
            'limit_pct' => $row['limit_pct'],
            'label' => $row['label'],
            'balance_work_value' => $row['balance_work_value'],
            'balance_money' => $row['balance_money'],
            'short_by' => $row['short_by'],
        ];
    }
}
