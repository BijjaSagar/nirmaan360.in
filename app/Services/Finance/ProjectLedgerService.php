<?php

namespace App\Services\Finance;

use App\Models\ClientReceipt;
use App\Models\PettyCashEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\VendorPayment;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * One running account for a project, built from the documents themselves.
 *
 * Debit is what others owe the project or what it has paid out. Credit is what it has
 * received or now owes. The balance therefore always equals
 * receivable outstanding − payable outstanding + petty cash in hand.
 */
class ProjectLedgerService
{
    public function __construct(private SettlementService $settlement) {}

    /**
     * @return array{entries:list<array>, opening:string, total_debit:string, total_credit:string, closing:string, summary:array<string,mixed>}
     */
    public function statement(Project $project, ?string $from = null, ?string $to = null): array
    {
        $opening = BigDecimal::zero();
        $running = BigDecimal::zero();
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();
        $entries = [];
        $openingCounted = false;

        foreach ($this->entries($project) as $entry) {
            if ($from !== null && $entry['date'] < $from) {
                $opening = $opening->plus(Dec::of($entry['amount']));

                continue;
            }

            if (! $openingCounted) {
                $running = $opening;
                $openingCounted = true;
            }

            if ($to !== null && $entry['date'] > $to) {
                continue;
            }

            $running = $running->plus(Dec::of($entry['amount']));
            $totalDebit = $totalDebit->plus(Dec::of($entry['debit']));
            $totalCredit = $totalCredit->plus(Dec::of($entry['credit']));
            $entries[] = [...$entry, 'balance' => Dec::money($running)];
        }

        return [
            'entries' => $entries,
            'opening' => Dec::money($opening),
            'total_debit' => Dec::money($totalDebit),
            'total_credit' => Dec::money($totalCredit),
            'closing' => Dec::money($openingCounted ? $running : $opening),
            'summary' => $this->summary($project),
        ];
    }

    /** The project's position across all dates. */
    public function summary(Project $project): array
    {
        $clientBills = $this->settlement->clientBills($project->organization_id, $project->id);
        $payables = $this->settlement->payableBills($project->organization_id, $project->id);
        $petty = $this->pettyEntries($project);

        $receivableOutstanding = $this->total($clientBills, 'outstanding');
        $payableOutstanding = $this->total($payables, 'outstanding');
        $received = $this->total($clientBills, 'received');
        $paid = $this->total($payables, 'paid');
        $topups = $this->pettySum($petty, 'topup');
        $spent = $this->pettySum($petty, 'expense');
        $pettyInHand = $topups->minus($spent);

        return [
            'billed_to_client' => Dec::money($this->total($clientBills, 'receivable')),
            'received' => Dec::money($received),
            'tds_by_client' => Dec::money($this->total($clientBills, 'tds')),
            'receivable_outstanding' => Dec::money($receivableOutstanding),
            'retention_with_client' => Dec::money($this->total($clientBills, 'retention')),
            'billed_by_vendors' => Dec::money($this->total($payables, 'payable')),
            'paid' => Dec::money($paid),
            'payable_outstanding' => Dec::money($payableOutstanding),
            'retention_from_subcontractors' => Dec::money($this->total($payables, 'retention')),
            'petty_topups' => Dec::money($topups),
            'petty_spent' => Dec::money($spent),
            'petty_in_hand' => Dec::money($pettyInHand),
            'net_position' => Dec::money($receivableOutstanding->minus($payableOutstanding)->plus($pettyInHand)),
            'net_cash' => Dec::money($received->minus($paid)->minus($topups)),
            // Bills someone marked paid by hand while the receipts or payments do not add up to it.
            'marked_paid_without_entries' => $clientBills->concat($payables)
                ->filter(fn ($row) => $row['bill'] instanceof RaBill && $row['bill']->status === 'PAID' && Dec::of($row['outstanding'])->isPositive())
                ->count(),
        ];
    }

    /** @return list<array{date:string, type:string, reference:string, party:?string, note:?string, url:?string, amount:string, debit:string, credit:string}> */
    private function entries(Project $project): array
    {
        $entries = [];
        $organizationId = $project->organization_id;

        foreach ($this->settlement->clientBills($organizationId, $project->id) as $row) {
            $bill = $row['bill'];
            $entries[] = $this->entry($this->billDate($bill), 1, $bill->id, 'client_bill', $bill->number, $bill->party?->name, null, route('ra-bills.show', $bill), $row['receivable']);
        }

        foreach ($this->settlement->payableBills($organizationId, $project->id) as $row) {
            $bill = $row['bill'];

            if ($row['kind'] === 'vendor') {
                $entries[] = $this->entry($bill->bill_date->toDateString(), 2, $bill->id, $bill->reversal_of_id ? 'vendor_reversal' : 'vendor_bill', $bill->bill_no, $bill->party?->name, $bill->cost_head, route('finance.project.vendor-bills.show', $bill), Dec::money(BigDecimal::zero()->minus(Dec::of($row['payable']))));
            } else {
                $entries[] = $this->entry($this->billDate($bill), 3, $bill->id, 'subcontractor_bill', $bill->number, $bill->party?->name, null, route('ra-bills.show', $bill), Dec::money(BigDecimal::zero()->minus(Dec::of($row['payable']))));
            }
        }

        foreach (ClientReceipt::with('party', 'raBill')->where('project_id', $project->id)->get() as $receipt) {
            $entries[] = $this->entry($receipt->received_on->toDateString(), 4, $receipt->id, $receipt->isReversal() ? 'receipt_reversal' : 'receipt', $receipt->voucherNo(), $receipt->party?->name, $receipt->raBill?->number, null, Dec::money(BigDecimal::zero()->minus(Dec::of($receipt->settled()))));
        }

        foreach (VendorPayment::with('party', 'vendorBill', 'raBill')->where('project_id', $project->id)->get() as $payment) {
            $entries[] = $this->entry($payment->paid_on->toDateString(), 5, $payment->id, $payment->isReversal() ? 'payment_reversal' : 'payment', $payment->voucherNo(), $payment->party?->name, $payment->documentNo(), null, Dec::money($payment->amount));
        }

        foreach ($this->pettyEntries($project) as $petty) {
            $isTopup = $petty->type === 'topup';
            $entries[] = $this->entry($petty->entry_date->toDateString(), 6, $petty->id, $isTopup ? 'petty_topup' : 'petty_expense', 'PC-'.$petty->id, $petty->wallet?->name, $petty->category ?? $petty->description, route('petty.index', $project), $isTopup ? Dec::money($petty->amount) : Dec::money(BigDecimal::zero()->minus(Dec::of($petty->amount))));
        }

        usort($entries, fn ($a, $b) => [$a['date'], $a['order'], $a['id']] <=> [$b['date'], $b['order'], $b['id']]);

        return $entries;
    }

    private function entry(string $date, int $order, int $id, string $type, string $reference, ?string $party, ?string $note, ?string $url, string $amount): array
    {
        $signed = Dec::of($amount);

        return [
            'date' => $date,
            'order' => $order,
            'id' => $id,
            'type' => $type,
            'reference' => $reference,
            'party' => $party,
            'note' => $note,
            'url' => $url,
            'amount' => Dec::money($signed),
            'debit' => $signed->isPositive() ? Dec::money($signed) : '0.00',
            'credit' => $signed->isNegative() ? Dec::money($signed->abs()) : '0.00',
        ];
    }

    private function billDate(RaBill $bill): string
    {
        return ($bill->approved_at ?? $bill->period_to)->toDateString();
    }

    /** @return Collection<int, PettyCashEntry> */
    private function pettyEntries(Project $project): Collection
    {
        return PettyCashEntry::with('wallet')
            ->whereHas('wallet', fn ($q) => $q->where('project_id', $project->id))
            ->get();
    }

    private function pettySum(Collection $entries, string $type): BigDecimal
    {
        return $entries->where('type', $type)->reduce(fn (BigDecimal $sum, $entry) => $sum->plus(Dec::of($entry->amount)), BigDecimal::zero());
    }

    private function total(Collection $rows, string $key): BigDecimal
    {
        return $rows->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row[$key])), BigDecimal::zero());
    }
}
