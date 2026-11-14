<?php

namespace App\Services\DocumentReading\Contracts;

/**
 * Optional, beside ReadKind: tells the platform admin's dry-run page how this kind's code reads
 * the cells of an answer, and which checks its code makes on a page. Without it the page shows
 * every cell as it was read, with the script of its digits, and no checks.
 *
 * Nothing here touches the database: a dry run has no read and no company.
 */
interface DescribesTrialResult
{
    /**
     * How the code reads each field of a pass. A field is a cell's place in the answer without
     * positions: the "qty" cell of every entry of "rows" is "rows.qty".
     *
     * @return array<string, string> Field => qty, rate, amount, percent or count (read by PrintedNumber),
     *                               date (PrintedDate) or unit (UnitText). A field left out is shown as read.
     */
    public function cellKinds(string $pass): array;

    /**
     * The kind's own checks by code on one page, from the accepted answers of every pass read on
     * it so far: for a BOQ, quantity times rate against the amount of the second pass.
     *
     * @param  array<string, array<string, mixed>>  $answers  Pass => the accepted answer for this page.
     * @return list<array{label: string, ok: bool, note?: ?string}> A label and a note for the admin. Counts and positions, not a company's data.
     */
    public function pageChecks(array $answers): array;
}
