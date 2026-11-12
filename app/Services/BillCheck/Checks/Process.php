<?php

namespace App\Services\BillCheck\Checks;

use App\Services\ApprovalService;
use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Support\Dec;

/**
 * What happens to the bill once it is submitted, and whether it names what a bill needs to name.
 */
final class Process extends Check
{
    public function __construct(private ApprovalService $approvals) {}

    public function run(BillFacts $facts): array
    {
        return [...$this->approvalChain($facts), ...$this->parties($facts), ...$this->gstOnOrOff($facts)];
    }

    /** F1 and F2. @return list<Flag> */
    private function approvalChain(BillFacts $facts): array
    {
        $levels = $this->approvals->levelsFor($facts->bill->approvalDocType(), $facts->bill->organization_id);

        return match (true) {
            $levels === [] => [$this->flag('F1', 'F1')],
            in_array('client', $levels, true) => [$this->flag('F2', 'F2')],
            default => [],
        };
    }

    /** F3: a client bill that names no client, or charges GST without the details a tax invoice takes its place and supplier from. @return list<Flag> */
    private function parties(BillFacts $facts): array
    {
        if (! $facts->isClient()) {
            return [];
        }

        $flags = [];

        if ($facts->bill->party_id === null) {
            $flags[] = $this->flag('F3.client', 'F3:client');
        }

        if (Dec::of($facts->bill->gst_amount)->isPositive()) {
            if (blank($facts->organization->gstin)) {
                $flags[] = $this->flag('F3.gstin', 'F3:gstin');
            }

            if (blank($facts->project->state)) {
                $flags[] = $this->flag('F3.state', 'F3:state');
            }
        }

        return $flags;
    }

    /** F4: GST on the bill and not on the contract form today, or the other way round. @return list<Flag> */
    private function gstOnOrOff(BillFacts $facts): array
    {
        if ($facts->contractMissing()) {
            return [];
        }

        $bill = Dec::of($facts->terms['gst_pct'] ?? 0);
        $contract = Dec::of($facts->termsToday()['gst_pct'] ?? 0);

        return match (true) {
            $bill->isZero() && $contract->isPositive() => [$this->flag('F4.off', 'F4', ['pct' => BillFacts::pct($contract)])],
            $bill->isPositive() && $contract->isZero() => [$this->flag('F4.on', 'F4', ['pct' => BillFacts::pct($bill)])],
            default => [],
        };
    }
}
