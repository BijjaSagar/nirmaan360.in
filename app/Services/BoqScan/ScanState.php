<?php

namespace App\Services\BoqScan;

use App\Models\BoqScanRow;
use Illuminate\Support\Collection;

/**
 * The checks of one read at one moment, as ScanReconciler worked them out. Asked by the screens and
 * by every step that confirms something, so all of them go by the same flags.
 */
final class ScanState
{
    /** Cells that must all be marked "read" by the reader for a row to be confirmed with its page. */
    private const CLEAN_CELLS = ['item_no', 'description', 'unit', 'qty', 'rate', 'amount'];

    /**
     * @param  Collection<int, BoqScanRow>  $live  The rows that are not left out, in order.
     * @param  array<int, list<array<string, mixed>>>  $rows  Row id => flags.
     * @param  array<int, list<array<string, mixed>>>  $pages  Page number => flags.
     * @param  array<int, bool>  $cleanPages  Page number => whether its clean rows may be confirmed together at all.
     * @param  array<int, bool>  $suspect  Page number => whether quantity and rate may be the wrong way round there.
     * @param  list<array<string, mixed>>  $read
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly Collection $live,
        public readonly array $rows,
        public readonly array $pages,
        public readonly array $cleanPages,
        public readonly array $suspect,
        public readonly array $read,
        public readonly array $summary,
    ) {}

    /** @return list<array<string, mixed>> */
    public function flagsOf(BoqScanRow $row): array
    {
        return $this->rows[$row->id] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function pageFlags(int $number): array
    {
        return $this->pages[$number] ?? [];
    }

    /** A flag that still needs something: a block, or a tick not given. */
    public static function open(array $flag): bool
    {
        return $flag['level'] === ScanFlag::BLOCK || ! ($flag['ticked'] ?? false);
    }

    /**
     * Whether a person may confirm this row on its own: it becomes a BOQ line, nothing on it stops,
     * every tick on it is given, and its page does not stand with quantity and rate in doubt.
     */
    public function canConfirm(BoqScanRow $row): bool
    {
        if (! $row->isImported()) {
            return false;
        }

        foreach ($this->flagsOf($row) as $flag) {
            if ($flag['code'] !== ScanFlag::UNCONFIRMED_ROW && self::open($flag)) {
                return false;
            }
        }

        foreach ($this->pageFlags((int) $row->page_no) as $flag) {
            if ($flag['code'] === ScanFlag::COLUMNS_SUSPECT) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether "Confirm the clean rows of this page" takes this row: an item line read as it is, with
     * no flag of any kind, every cell marked read, nothing typed, and not the first line of its page.
     * The first line of every page, every add-on, every typed and every flagged row is confirmed one by one.
     */
    public function pageConfirmable(BoqScanRow $row): bool
    {
        if ($row->kind !== BoqScanRow::ITEM || ! $row->fromReader() || $row->confirmed_at !== null || ! ($this->cleanPages[(int) $row->page_no] ?? false)) {
            return false;
        }

        if (($row->typed ?? []) !== [] || array_filter($this->flagsOf($row), fn (array $flag) => $flag['code'] !== ScanFlag::UNCONFIRMED_ROW) !== []) {
            return false;
        }

        foreach (self::CLEAN_CELLS as $cell) {
            if ($row->stateOf($cell) !== 'read') {
                return false;
            }
        }

        return $this->firstLineOf((int) $row->page_no)?->id !== $row->id;
    }

    /** @return Collection<int, BoqScanRow> */
    public function cleanRowsOf(int $number): Collection
    {
        return $this->live->where('page_no', $number)->filter(fn (BoqScanRow $row) => $this->pageConfirmable($row))->values();
    }

    /** Whether the page's clean rows may be confirmed together at all. */
    public function pageMayConfirmClean(int $number): bool
    {
        return $this->cleanPages[$number] ?? false;
    }

    /** @return list<array<string, mixed>> Every flag that still needs something, with where it stands. */
    public function openFlags(): array
    {
        $open = [];

        foreach ($this->rows as $id => $flags) {
            foreach ($flags as $flag) {
                if (self::open($flag)) {
                    $open[] = ['row_id' => $id, ...$flag];
                }
            }
        }

        foreach ($this->pages as $number => $flags) {
            foreach ($flags as $flag) {
                if (self::open($flag)) {
                    $open[] = ['page_no' => $number, ...$flag];
                }
            }
        }

        foreach ($this->read as $flag) {
            if (self::open($flag)) {
                $open[] = $flag;
            }
        }

        return $open;
    }

    /** The flag of this code now standing on a row, if any. */
    public function rowFlag(BoqScanRow $row, string $code): ?array
    {
        foreach ($this->flagsOf($row) as $flag) {
            if ($flag['code'] === $code) {
                return $flag;
            }
        }

        return null;
    }

    private function firstLineOf(int $number): ?BoqScanRow
    {
        return $this->live->where('page_no', $number)->where('kind', BoqScanRow::ITEM)->sortBy([['sort', 'asc'], ['id', 'asc']])->first();
    }
}
