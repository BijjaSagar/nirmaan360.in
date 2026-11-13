<?php

namespace App\Services\TenderReading;

use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns what the reader returned for one page, or one run of text pages, into proposed facts on the
 * tender sheet, and keeps the sheet's contradictions marked.
 *
 * Everything here is database work only: it runs inside the transaction that marks the page as read
 * and charges it, or with no signed-in person on the queue, so every query names the company.
 */
class TenderFactBuilder
{
    /**
     * Stages the facts of a page that was just read. A page read again replaces the proposals it made
     * the first time; a fact a person already confirmed or rejected stays, and the same value found
     * again at the same place is not proposed a second time.
     */
    public function stage(DocumentReadPage $page): void
    {
        $read = $page->relationLoaded('documentRead') && $page->documentRead !== null
            ? $page->documentRead
            : DocumentRead::withoutGlobalScopes()->where('organization_id', $page->organization_id)->findOrFail($page->document_read_id);

        $organization = (int) $read->organization_id;
        $tender = (int) ($read->options['tender_id'] ?? 0);
        $result = is_array($page->result) ? $page->result : [];
        $source = $page->pass === TenderReadKind::IMAGE ? CrmTenderFact::FROM_IMAGE : CrmTenderFact::FROM_TEXT;
        $from = (int) $page->page_no;
        $to = max($from, (int) ($page->page_to ?? $from));

        if ($tender < 1) {
            return;
        }

        $facts = fn () => CrmTenderFact::withoutGlobalScopes()->where('organization_id', $organization)
            ->where('document_read_id', $read->id)->where('source', $source)->whereBetween('page', [$from, $to]);

        $facts()->where('status', CrmTenderFact::PROPOSED)->delete();

        $kept = $facts()->get()->map(fn (CrmTenderFact $fact) => $this->place($fact->field, $fact->page, $fact->value_kind, $fact->value_raw, $fact->value_norm))->flip();

        foreach ((array) ($result['facts'] ?? []) as $fact) {
            $field = (string) $fact['field'];
            $kind = TenderFields::kind($field);
            $raw = (string) $fact['value'];
            $norm = TenderValue::normalise($kind, $raw);

            if ($kept->has($this->place($field, (int) $fact['page'], $kind, $raw, $norm))) {
                continue;
            }

            CrmTenderFact::withoutGlobalScopes()->create([
                'organization_id' => $organization,
                'tender_id' => $tender,
                'document_id' => isset($read->options['document_id']) ? (int) $read->options['document_id'] : null,
                'document_read_id' => $read->id,
                'field' => $field,
                'label' => filled($fact['label'] ?? null) ? (string) $fact['label'] : null,
                'value_raw' => $raw,
                'value_norm' => $norm,
                'value_kind' => $kind,
                'page' => (int) $fact['page'],
                'quote' => (string) ($fact['quote'] ?? ''),
                'quote_line' => $fact['quote_line'] ?? null,
                'quote_found' => $fact['quote_found'] ?? null,
                'source' => $source,
                'status' => CrmTenderFact::PROPOSED,
            ]);
        }

        $this->noteReview($read, (array) ($result['unreadable_pages'] ?? []), (int) ($result['dropped'] ?? 0));
        $this->regroup($organization, $tender);
    }

    /**
     * Marks the contradictions of a tender's sheet. Facts are compared by field, and by label for a
     * field that repeats; when the standing values of one comparison differ, they share a conflict
     * group and are shown side by side. Code never chooses between them.
     */
    public function regroup(int $organizationId, int $tenderId): void
    {
        $facts = CrmTenderFact::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('tender_id', $tenderId)
            ->get(['id', 'field', 'label', 'value_kind', 'value_raw', 'value_norm', 'status', 'conflict_group']);

        $standing = $facts->filter->isStanding();

        foreach ($standing->groupBy(fn (CrmTenderFact $fact) => self::topic($fact->field, $fact->label)) as $group) {
            $values = $group->map(fn (CrmTenderFact $fact) => TenderValue::compareKey($fact->value_kind, $fact->value_raw, $fact->value_norm))->uniqueStrict();
            $wanted = $values->count() > 1 ? ($group->pluck('conflict_group')->filter()->first() ?? (string) Str::uuid()) : null;

            $this->setGroup($organizationId, $group->where('conflict_group', '!==', $wanted), $wanted);
        }

        // A fact that no longer stands is in no contradiction.
        $this->setGroup($organizationId, $facts->reject->isStanding()->whereNotNull('conflict_group'), null);
    }

    /** What facts are compared by: the field, and for a field that repeats the label too. */
    public static function topic(string $field, ?string $label): string
    {
        return TenderFields::repeatable($field) ? $field.'|'.(TenderValue::textKey((string) $label) ?? '') : $field;
    }

    /** Deletes the facts of a read that nobody confirmed, once the read's files are gone. */
    public function purge(DocumentRead $read): void
    {
        $tenders = CrmTenderFact::withoutGlobalScopes()->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)->distinct()->pluck('tender_id');

        CrmTenderFact::withoutGlobalScopes()->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)
            ->whereIn('status', [CrmTenderFact::PROPOSED, CrmTenderFact::REJECTED])
            ->delete();

        foreach ($tenders as $tender) {
            $this->regroup((int) $read->organization_id, (int) $tender);
        }
    }

    /** @param  Collection<int, CrmTenderFact>  $facts */
    private function setGroup(int $organizationId, Collection $facts, ?string $group): void
    {
        if ($facts->isEmpty()) {
            return;
        }

        CrmTenderFact::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->whereKey($facts->modelKeys())
            ->update(['conflict_group' => $group, 'updated_at' => now()]);
    }

    /** One fact's place: what it says, where. */
    private function place(string $field, int $page, string $kind, string $raw, ?string $norm): string
    {
        return $field.'|'.$page.'|'.TenderValue::compareKey($kind, $raw, $norm);
    }

    /**
     * Keeps on the read the pages the reader called unreadable and the facts it dropped for naming a
     * page outside the run, so the sheet can list them after the read's files are gone.
     *
     * @param  list<int>  $unreadable
     */
    private function noteReview(DocumentRead $read, array $unreadable, int $dropped): void
    {
        $review = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->whereKey($read->id)->value('review');
        $review = is_array($review) ? $review : (is_string($review) ? (array) json_decode($review, true) : []);

        $pages = array_values(array_unique(array_map(intval(...), [...(array) ($review['unreadable_pages'] ?? []), ...$unreadable])));
        sort($pages);

        $review['unreadable_pages'] = $pages;
        $review['dropped_facts'] = (int) ($review['dropped_facts'] ?? 0) + max(0, $dropped);

        DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->whereKey($read->id)
            ->update(['review' => json_encode($review)]);

        $read->setAttribute('review', $review);
        $read->syncOriginalAttribute('review');
    }
}
