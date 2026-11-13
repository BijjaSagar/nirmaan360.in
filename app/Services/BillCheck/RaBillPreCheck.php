<?php

namespace App\Services\BillCheck;

use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Models\RaBill;
use App\Models\User;
use App\Services\BillCheck\Checks\Arithmetic;
use App\Services\BillCheck\Checks\Duplicates;
use App\Services\BillCheck\Checks\EarlierBills;
use App\Services\BillCheck\Checks\Measurements;
use App\Services\BillCheck\Checks\Process;
use App\Services\BillCheck\Checks\Quantities;
use App\Services\BillCheck\Checks\Terms;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The pre-check of an RA bill: adds the bill up again, holds it against the earlier bills, the BOQ or
 * work order, the measurements, the approval rule and the confirmed contract terms, and lists what
 * it finds. Pure code, no AI model.
 *
 * It reads billing data and never changes it: no bill, line, deduction, measurement or ledger is
 * written here, and the deduction engine is only asked to work a bill out again. The one thing
 * stored is the check itself.
 *
 * An empty list means these checks found nothing. It does not mean the bill will be passed.
 */
class RaBillPreCheck
{
    /** The order findings are listed in: what does not add up first. */
    private const ORDER = [Flag::BLOCKING => 0, Flag::TICK => 1, Flag::NOTE => 2];

    public function __construct(
        private Arithmetic $arithmetic,
        private EarlierBills $earlierBills,
        private Quantities $quantities,
        private Measurements $measurements,
        private Duplicates $duplicates,
        private Process $process,
        private Terms $terms,
    ) {}

    /** Runs every check on the bill as the records stand now. Stores nothing. */
    public function inspect(RaBill $bill, ?CarbonInterface $today = null): CheckResult
    {
        $facts = new BillFacts($bill, $today);

        if ($facts->contractMissing()) {
            // Without its work order nobody can say which bills, measurements and terms belong to this
            // bill's contract. Only what the bill says about itself is checked, and the rest is named as left out.
            $flags = [...$this->arithmetic->run($facts), ...$this->process->run($facts), new Flag('X1', 'X1', FlagCatalog::level('X1'))];
            $sheet = null;
        } else {
            [$termFlags, $sheet] = $this->terms->inspect($facts);

            $flags = [
                ...$this->arithmetic->run($facts),
                ...$this->earlierBills->run($facts),
                ...$this->quantities->run($facts),
                ...$this->measurements->run($facts),
                ...$this->duplicates->run($facts),
                ...$this->process->run($facts),
                ...$termFlags,
            ];
        }

        $keys = array_map(fn (Flag $flag) => $flag->key, $flags);

        if (count($keys) !== count(array_unique($keys))) {
            throw new LogicException('Two findings of one check share a key: '.implode(', ', array_diff_assoc($keys, array_unique($keys))));
        }

        // A stable sort, so findings of one level stay in the order the checks made them.
        usort($flags, fn (Flag $a, Flag $b) => self::ORDER[$a->level] <=> self::ORDER[$b->level]);

        return new CheckResult($flags, $sheet);
    }

    /** Runs the check and stores it. */
    public function run(RaBill $bill, User $by): BillCheck
    {
        return $this->store($bill, $this->inspect($bill), $by);
    }

    /** Stores what a run found, as a new check of the bill. */
    public function store(RaBill $bill, CheckResult $result, ?User $by): BillCheck
    {
        $check = BillCheck::create([
            'organization_id' => $bill->organization_id,
            'project_id' => $bill->project_id,
            'ra_bill_id' => $bill->id,
            'flags' => $result->flagsForStorage(),
            'flags_hash' => $result->hash(),
            'summary' => $result->summary(),
            'expected' => $result->expected,
            'run_by' => $by?->id,
        ]);

        // The findings carry rupee figures, so the audit row keeps the count and the hash only.
        AuditLog::record('bill_check.run', $check, null, ['ra_bill_id' => $bill->id, 'flags_hash' => $check->flags_hash, ...$result->summary()], $by);

        return $check;
    }
}
