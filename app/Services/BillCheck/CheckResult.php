<?php

namespace App\Services\BillCheck;

/**
 * What one run of the pre-check found, before anything is stored.
 */
final class CheckResult
{
    /**
     * @param  list<Flag>  $flags  In the order the card shows them.
     * @param  array<string, mixed>|null  $expected  The sheet of deductions that are not on the bill, or null when the contract has none entered.
     */
    public function __construct(public readonly array $flags, public readonly ?array $expected) {}

    /** sha256 over the sorted list of (key, level, figures) of every finding. */
    public function hash(): string
    {
        $rows = array_map(fn (Flag $flag) => $flag->canonical(), $this->flags);
        usort($rows, fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return hash('sha256', (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{blocking: int, tick: int, note: int} */
    public function summary(): array
    {
        $counts = array_fill_keys(Flag::LEVELS, 0);

        foreach ($this->flags as $flag) {
            $counts[$flag->level]++;
        }

        return $counts;
    }

    /** @return list<Flag> The findings that need an acknowledgement. */
    public function toAcknowledge(): array
    {
        return array_values(array_filter($this->flags, fn (Flag $flag) => $flag->needsAck()));
    }

    /** @return list<array<string, mixed>> */
    public function flagsForStorage(): array
    {
        return array_map(fn (Flag $flag) => $flag->toArray(), $this->flags);
    }
}
