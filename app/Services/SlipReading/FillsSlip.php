<?php

namespace App\Services\SlipReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\SlipRead;

/**
 * What the challan reader and the bill reader do with an answer, the same for both: the code checks
 * it onto the reading's row (ChallanChecks, BillChecks).
 *
 * Any answer after the first is a second reading of the same photo, however it was asked for: the
 * module's own "read again", or the page read again through the shared reader routes. It is
 * compared with the first, which is still on the row, so a value the two disagree on is not
 * offered, and it counts as the reading's one second reading.
 *
 * A reading that has ended (failed, discarded or used) takes no answer any more and is not charged
 * for one: it can never fill a form again.
 *
 * Expects $this->checks with build(array $answer, SlipRead $slip, ?array $earlier).
 */
trait FillsSlip
{
    /** Not charged: a photo the reader could not read at all, like a blank page, and an answer for a reading that has ended. */
    public function chargeable(DocumentReadPage $page): bool
    {
        if (($page->result['doc_kind'] ?? null) === 'unreadable') {
            return false;
        }

        $slip = SlipRead::withoutGlobalScopes()->where('organization_id', $page->organization_id)->where('document_read_id', $page->document_read_id)->first();

        return $slip === null || $this->takesAnswers($slip);
    }

    public function pageRead(DocumentReadPage $page): void
    {
        $slip = $this->slipOf((int) $page->organization_id, (int) $page->document_read_id);

        if ($slip === null || ! is_array($page->result) || ! $this->takesAnswers($slip)) {
            return;
        }

        $earlier = is_array($slip->fields) ? ['fields' => $slip->fields, 'lines' => $slip->lines ?? []] : null;
        $built = $this->checks->build($page->result, $slip, $earlier);

        $slip->forceFill([
            'doc_kind' => $built['doc_kind'],
            'fields' => $built['fields'],
            'lines' => $built['lines'],
            'warnings' => $built['warnings'],
            'suggestions' => $built['suggestions'],
            'rechecked_at' => $earlier === null ? $slip->rechecked_at : ($slip->rechecked_at ?? now()),
        ])->save();
    }

    public function finished(DocumentRead $read): void
    {
        $slip = $this->slipOf((int) $read->organization_id, (int) $read->id);

        if ($slip === null || ! $this->takesAnswers($slip)) {
            return;
        }

        $slip->forceFill(['status' => $read->status === DocumentRead::READY && is_array($slip->fields) ? SlipRead::READY : SlipRead::FAILED])->save();
    }

    /** A reading still being read, or read and not yet used. */
    private function takesAnswers(SlipRead $slip): bool
    {
        return in_array($slip->status, [SlipRead::READING, SlipRead::READY], true);
    }

    private function slipOf(int $organizationId, int $readId): ?SlipRead
    {
        return SlipRead::withoutGlobalScopes()->where('organization_id', $organizationId)->where('document_read_id', $readId)->lockForUpdate()->first();
    }
}
