<?php

namespace App\Services\DocumentReading\Contracts;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;

/**
 * One kind of document a reader can read: a scanned BOQ, a challan photo, a tender. A feature
 * module writes the class and lists it under "read_kinds" in its own config/modules file. Its
 * models, output limits and time limits are in config modules.document_reading.kinds under key().
 *
 * The page reader does everything that is the same for every kind: the claim, the call, the charge,
 * the retries and the pauses. A kind says what to ask and what to do with the answer.
 *
 * Nothing here runs with a signed-in person, so every query a kind makes names its company.
 */
interface ReadKind
{
    /** The value of document_reads.kind, and the key of this kind's figures in the config. */
    public function key(): string;

    /**
     * Every pass this kind can make over a page, in the order they are read. Each has figures in the config.
     *
     * @return list<string>
     */
    public function passes(): array;

    /** @return array{max_pages:int, min_short_side:int} The most pages one read may have, and the shortest side in pixels a page picture may have. */
    public function limits(): array;

    /**
     * The Messages body for one call, built with ReaderRequest. Exactly one of the two is given: the
     * page picture, or the wrapped text of the pages page_no to page_to.
     *
     * The instructions and the form are the same for every company: nothing about the company, the
     * person, the project or the document's name goes in. It must also work for a page that was
     * never saved and whose documentRead relation is set by hand, with an empty picture or text:
     * the prompt is measured that way, and the platform admin's dry run calls it that way.
     *
     * @return array<string, mixed>
     */
    public function request(DocumentReadPage $page, ?string $imageBase64, ?string $text): array;

    /**
     * Looks at an answer that already has the right shape. Returns it cleaned (cut to field lengths,
     * positions clamped), or null when it cannot be used; the page then fails as invalid_output.
     * No database work: the page may be a dry-run page that was never saved.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>|null
     */
    public function accept(array $output, DocumentReadPage $page): ?array;

    /** False when the reader reports that the page is blank. Such a page is not charged. $page->result holds the accepted answer. */
    public function chargeable(DocumentReadPage $page): bool;

    /**
     * Writes the kind's staging rows from $page->result. Runs inside the transaction that marks the
     * page as read and charges it, so it does database work only: no file, no HTTP call, no job.
     * If it throws, nothing of the page is kept and the page fails as internal_error.
     */
    public function pageRead(DocumentReadPage $page): void;

    /**
     * The passes to read next on the same page, now that this one is read. The page reader makes a
     * waiting row for each, with the same picture and the pass's usual model.
     *
     * @return list<string>
     */
    public function nextPasses(DocumentReadPage $page): array;

    /**
     * What to do when the answer was cut off at the output limit:
     * 'split_run' (a run of text pages is read again as two halves, down to single pages),
     * 'offer_halves' (the page fails and the screen offers to read its top and bottom half), or
     * 'fail' (the page fails).
     */
    public function onCutOff(DocumentReadPage $page): string;

    /**
     * No page of the read is waiting or being read any more. Runs inside the transaction that marks
     * the read as ready or failed, so database work only. It can run again if a page is read again later.
     */
    public function finished(DocumentRead $read): void;

    /**
     * The permissions a person needs to open this read: every one under "all", and at least one
     * of each list under "any".
     *
     * @return array{all: list<string>, any: list<list<string>>}
     */
    public function abilities(DocumentRead $read): array;

    /** The read's files have been deleted. Blank or delete whatever this kind staged from them. Database work only. */
    public function purge(DocumentRead $read): void;
}
