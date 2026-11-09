<?php

namespace App\Services\Finance;

use App\Models\ClientReceipt;
use App\Models\PettyCashEntry;
use App\Models\VendorPayment;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A week-by-week view of money due in and out, from bills that are approved and unsettled.
 * The starting figure is cash recorded in this app, not a bank balance.
 */
class CashFlowForecastService
{
    public function __construct(private SettlementService $settlement) {}

    public function forecast(int $organizationId, CarbonInterface $today, int $weeks = 12, ?int $creditDays = null, ?int $projectId = null): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $creditDays ??= (int) config('modules.finance.default_credit_days');
        $items = [];

        foreach ($this->settlement->clientBills($organizationId, $projectId) as $row) {
            if (Dec::of($row['cash_expected'])->isPositive()) {
                $bill = $row['bill'];
                $items[] = $this->item(($bill->approved_at ?? $bill->period_to)->copy()->addDays($creditDays), 'in', $bill->number, $bill->party?->name, $bill->project?->code, $row['cash_expected']);
            }
        }

        foreach ($this->settlement->payableBills($organizationId, $projectId) as $row) {
            if (! $row['open'] || ! Dec::of($row['outstanding'])->isPositive()) {
                continue;
            }

            $bill = $row['bill'];
            $due = $row['kind'] === 'vendor'
                ? ($bill->due_date ?? $bill->bill_date->copy()->addDays($creditDays))
                : ($bill->approved_at ?? $bill->period_to)->copy()->addDays($creditDays);

            $items[] = $this->item($due, 'out', $row['kind'] === 'vendor' ? $bill->bill_no : $bill->number, $bill->party?->name, $bill->project?->code, $row['outstanding']);
        }

        usort($items, fn ($a, $b) => [$a['due'], $a['reference']] <=> [$b['due'], $b['reference']]);

        $opening = $this->netCashToDate($organizationId, $projectId);
        $buckets = $this->buckets($today, $weeks);

        foreach ($items as $item) {
            $key = $this->bucketKey($today, $item['due'], $weeks);
            $column = $item['direction'];
            $buckets[$key][$column] = $buckets[$key][$column]->plus(Dec::of($item['amount']));
        }

        $running = $opening;
        $totalIn = BigDecimal::zero();
        $totalOut = BigDecimal::zero();
        $rows = [];

        foreach ($buckets as $bucket) {
            $running = $running->plus($bucket['in'])->minus($bucket['out']);
            $totalIn = $totalIn->plus($bucket['in']);
            $totalOut = $totalOut->plus($bucket['out']);
            $rows[] = [
                ...$bucket,
                'in' => Dec::money($bucket['in']),
                'out' => Dec::money($bucket['out']),
                'net' => Dec::money($bucket['in']->minus($bucket['out'])),
                'closing' => Dec::money($running),
            ];
        }

        return [
            'opening' => Dec::money($opening),
            'rows' => $rows,
            'items' => $items,
            'total_in' => Dec::money($totalIn),
            'total_out' => Dec::money($totalOut),
            'closing' => Dec::money($running),
            'weeks' => $weeks,
            'credit_days' => $creditDays,
        ];
    }

    private function item(CarbonInterface $due, string $direction, string $reference, ?string $party, ?string $project, string $amount): array
    {
        return ['due' => $due->toDateString(), 'direction' => $direction, 'reference' => $reference, 'party' => $party, 'project' => $project, 'amount' => $amount];
    }

    /** @return array<string, array{key:string, from:?string, to:?string, in:BigDecimal, out:BigDecimal}> */
    private function buckets(CarbonImmutable $today, int $weeks): array
    {
        $empty = ['in' => BigDecimal::zero(), 'out' => BigDecimal::zero()];
        $buckets = ['overdue' => ['key' => 'overdue', 'from' => null, 'to' => $today->subDay()->toDateString(), ...$empty]];

        for ($week = 0; $week < $weeks; $week++) {
            $buckets['week'.$week] = ['key' => 'week', 'from' => $today->addDays($week * 7)->toDateString(), 'to' => $today->addDays($week * 7 + 6)->toDateString(), ...$empty];
        }

        $buckets['later'] = ['key' => 'later', 'from' => $today->addDays($weeks * 7)->toDateString(), 'to' => null, ...$empty];

        return $buckets;
    }

    private function bucketKey(CarbonImmutable $today, string $due, int $weeks): string
    {
        $dueDate = CarbonImmutable::parse($due)->startOfDay();

        if ($dueDate->lessThan($today)) {
            return 'overdue';
        }

        $week = (int) floor($today->diffInDays($dueDate) / 7);

        return $week >= $weeks ? 'later' : 'week'.$week;
    }

    /** Money actually received, less money paid out and cash sent to site wallets. */
    private function netCashToDate(int $organizationId, ?int $projectId): BigDecimal
    {
        $received = ClientReceipt::withoutGlobalScopes()->where('organization_id', $organizationId)->when($projectId, fn ($q) => $q->where('project_id', $projectId))->get();
        $paid = VendorPayment::withoutGlobalScopes()->where('organization_id', $organizationId)->when($projectId, fn ($q) => $q->where('project_id', $projectId))->get();
        $topups = PettyCashEntry::withoutGlobalScopes()->where('organization_id', $organizationId)->where('type', 'topup')
            ->when($projectId, fn ($q) => $q->whereHas('wallet', fn ($wallet) => $wallet->where('project_id', $projectId)))
            ->get();

        $net = BigDecimal::zero();

        foreach ($received as $row) {
            $net = $net->plus(Dec::of($row->amount));
        }

        foreach ($paid->concat($topups) as $row) {
            $net = $net->minus(Dec::of($row->amount));
        }

        return $net;
    }
}
