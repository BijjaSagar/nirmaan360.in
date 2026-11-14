<?php

namespace App\Services\TenderReading;

use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Services\DocumentReading\Contracts\ChecksSubjectAccess;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\ReadFiles;
use App\Services\Notify\Audience;
use RuntimeException;

/**
 * The tender reader. A tender PDF with a text layer is read as runs of text pages ("text"); a page
 * that has no usable text is read as a page picture ("image"). Either way the reader lists every place
 * a date, deposit, period or condition appears, with the value and the whole line it stands in, copied.
 *
 * The page of a fact is decided by code: on a picture it is the picture's page, whatever the reader
 * said; in a run of text pages it must be one of the run's pages, or the fact is dropped and counted.
 * The quoted line is then held against the server's own text of that page (TenderQuote).
 *
 * Nothing the reader returns changes the tender. Each fact is a proposal on the tender sheet until a
 * person confirms it with the page drawn beside it.
 */
class TenderReadKind implements ChecksSubjectAccess, ReadKind
{
    public const KEY = 'tender';

    public const TEXT = 'text';

    public const IMAGE = 'image';

    /** What the reader is asked, for text pages. The same for every company and every tender. */
    public const TEXT_INSTRUCTIONS = <<<'TEXT'
    The pages are the text of a tender document for construction work, taken out of a PDF. Each page is inside a <page number="N"> tag.
    List every place where the document states one of the fields of the form. Return every place a field appears, not only the first: a deadline, a deposit or a period that is stated twice is listed twice, each with its own page.
    - "field": the key from the list below that the statement is about.
    - "label": the words the document uses for it, copied, such as "Earnest Money Deposit" or the name of the document to submit. For "deduction", "checklist_item", "clause" and "other_date" the label says which one it is.
    - "value": the value exactly as printed, with its digits, commas, units and words. Do not convert, round, add up or complete it.
    - "page": the number of the page tag the statement is in.
    - "quote": the whole line the value stands in, copied exactly from the page text, at most 300 characters.
    - "unreadable_pages": numbers of pages whose text is garbled, such as letters that do not form words.
    Fields: reference (tender or NIT number), title (name of work), authority (department or office inviting the tender), location, state, estimated_value (estimated cost put to tender), published_on, pre_bid_at (pre-bid meeting date and time), pre_bid_venue, due_at (last date and time for submission), opens_at (opening of the technical or first bid), financial_opens_at (opening of the financial or price bid), portal_url (address of the tender portal), emd_amount, emd_percent, emd_mode (form in which the earnest money is paid), emd_validity, tender_fee, processing_fee, security_deposit, additional_security, retention, completion_period (time allowed to complete the work), defect_liability_period, bid_validity, eligibility_class (class or category of registration asked for), eligibility_turnover, eligibility_similar_work, eligibility_bid_capacity, deduction (any deduction from bills that the document names), checklist_item (a document the bidder must submit), clause (another condition a bidder must know before bidding), other_date (any other date the document fixes).
    Do not list anything that is not stated on these pages. A field that is not stated is simply left out.
    TEXT;

    /** What the reader is asked, for a page picture. */
    public const IMAGE_INSTRUCTIONS = <<<'TEXT'
    The picture is one page of a tender document for construction work.
    List every place where the page states one of the fields of the form, with the same rules as for text: every place a field appears, the label as printed, the value exactly as printed, the whole line it stands in as the quote (at most 300 characters). "page" is the page number printed on the page, or 0 when none is printed. If the page cannot be read, list its page number under "unreadable_pages".
    Fields: reference, title, authority, location, state, estimated_value, published_on, pre_bid_at, pre_bid_venue, due_at, opens_at, financial_opens_at, portal_url, emd_amount, emd_percent, emd_mode, emd_validity, tender_fee, processing_fee, security_deposit, additional_security, retention, completion_period, defect_liability_period, bid_validity, eligibility_class, eligibility_turnover, eligibility_similar_work, eligibility_bid_capacity, deduction, checklist_item, clause, other_date.
    Do not list anything that is not on this page.
    TEXT;

    public function key(): string
    {
        return self::KEY;
    }

    public function passes(): array
    {
        return [self::TEXT, self::IMAGE];
    }

    public function limits(): array
    {
        return ['max_pages' => 150, 'min_short_side' => 600];
    }

    /** @return array<string, mixed> The form of the answer. Field keys come from the config, in lower case. */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'facts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string', 'enum' => TenderFields::keys()],
                            'label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'page' => ['type' => 'integer'],
                            'quote' => ['type' => 'string'],
                        ],
                        'required' => ['field', 'label', 'value', 'page', 'quote'],
                        'additionalProperties' => false,
                    ],
                ],
                'unreadable_pages' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'required' => ['facts', 'unreadable_pages'],
            'additionalProperties' => false,
        ];
    }

    public function request(DocumentReadPage $page, ?string $imageBase64, ?string $text): array
    {
        if ($imageBase64 !== null) {
            $language = $page->documentRead?->options['language'] ?? null;

            return ReaderRequest::image(self::KEY, $page, self::IMAGE_INSTRUCTIONS, self::schema(), $imageBase64, is_string($language) ? $language : null);
        }

        return ReaderRequest::text(self::KEY, $page, self::TEXT_INSTRUCTIONS, self::schema(), (string) $text);
    }

    /**
     * Cuts every string to its length, keeps only facts of a page the call was about and of a field
     * on the list, and holds each quoted line against the page's own text. Never unusable for having
     * found nothing: a tender page with no field on it is an answer too.
     */
    public function accept(array $output, DocumentReadPage $page): ?array
    {
        $picture = $page->pass === self::IMAGE;
        $from = (int) $page->page_no;
        $to = $picture ? $from : max($from, (int) ($page->page_to ?? $from));
        $texts = $picture ? [] : $this->texts($page, $from, $to);
        $facts = [];
        $dropped = 0;

        foreach ((array) ($output['facts'] ?? []) as $fact) {
            $field = is_array($fact) ? strtolower((string) ($fact['field'] ?? '')) : '';
            $value = trim(mb_substr((string) ($fact['value'] ?? ''), 0, TenderFields::setting('value_chars', 500)));
            $number = $picture ? $from : (int) ($fact['page'] ?? 0);

            if (! TenderFields::exists($field) || $value === '' || $number < $from || $number > $to) {
                $dropped++;

                continue;
            }

            $quote = trim(mb_substr((string) ($fact['quote'] ?? ''), 0, TenderFields::setting('quote_chars', 300)));
            $check = $picture ? null : TenderQuote::check($quote, $value, $texts[$number] ?? '');

            $facts[] = [
                'field' => $field,
                'label' => trim(mb_substr((string) ($fact['label'] ?? ''), 0, TenderFields::setting('label_chars', 160))),
                'value' => $value,
                'page' => $number,
                'quote' => $quote,
                'quote_line' => $check === null || $check['line'] === null ? null : mb_substr($check['line'], 0, 2000),
                // Null on a picture: there is no text the line can be held against.
                'quote_found' => $check === null ? null : ($texts === [] ? false : $check['found']),
                'value_in_quote' => $check === null ? $this->valueIn($value, $quote) : $check['value_in_quote'],
            ];
        }

        $unreadable = array_values(array_unique(array_filter(
            array_map(intval(...), (array) ($output['unreadable_pages'] ?? [])),
            fn (int $number) => $picture ? true : $number >= $from && $number <= $to,
        )));

        return [
            'facts' => $facts,
            // On a picture the page is the picture's own, whatever number the reader saw printed on it.
            'unreadable_pages' => $picture ? ($unreadable === [] ? [] : [$from]) : $unreadable,
            'dropped' => $dropped,
        ];
    }

    public function chargeable(DocumentReadPage $page): bool
    {
        return true;
    }

    public function pageRead(DocumentReadPage $page): void
    {
        app(TenderFactBuilder::class)->stage($page);
    }

    public function nextPasses(DocumentReadPage $page): array
    {
        return [];
    }

    public function onCutOff(DocumentReadPage $page): string
    {
        return $page->pass === self::TEXT ? 'split_run' : 'fail';
    }

    public function finished(DocumentRead $read): void
    {
        $tender = (int) ($read->options['tender_id'] ?? 0);

        if ($tender > 0) {
            app(TenderFactBuilder::class)->regroup((int) $read->organization_id, $tender);
        }
    }

    /**
     * What every shared step of a tender read asks for: uploading its pages, pricing, starting,
     * continuing, reading a page again, cancelling and watching. Each of them leads to pages being sent
     * or stopped, so it needs crm.manage, as starting a read and answering its questions do. Someone
     * with crm.view only watches through the tender's own reading screen and sheet.
     */
    public function abilities(DocumentRead $read): array
    {
        return ['all' => ['crm.manage'], 'any' => []];
    }

    /**
     * A tender read is opened with no project: the tender is the company's own while it is bid for.
     * When it is won, the tender and its documents are taken into a project (TenderConversionService),
     * so someone limited to other projects stops seeing them, and from then on the read too: its status,
     * its page pictures, and every step that spends credits on it. Looked up afresh each time, without
     * the signed-in scope, because this also runs on the queue for the person who started the read.
     */
    public function sees(User $user, DocumentRead $read): bool
    {
        if (! $user->isProjectLimited()) {
            return true;
        }

        $organization = (int) $read->organization_id;
        $projects = [
            CrmTender::withoutGlobalScopes()->where('organization_id', $organization)->whereKey((int) ($read->options['tender_id'] ?? 0))->value('project_id'),
            CrmTenderDocument::withoutGlobalScopes()->where('organization_id', $organization)->whereKey((int) ($read->options['document_id'] ?? 0))->value('project_id'),
        ];

        foreach ($projects as $project) {
            if (! Audience::seesProject($user, $project === null ? null : (int) $project)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The read's files are gone. A fact that nobody confirmed goes with them; a confirmed fact stays
     * with the tender, as its own record of what the document said.
     */
    public function purge(DocumentRead $read): void
    {
        app(TenderFactBuilder::class)->purge($read);
    }

    /** @return array<int, string> The server's own text of the run's pages, or nothing when it cannot be had. */
    private function texts(DocumentReadPage $page, int $from, int $to): array
    {
        $read = $page->documentRead;

        if ($read === null || ! ReadFiles::owns($read, $read->text_path)) {
            return [];
        }

        try {
            return ReadFiles::texts($read, $from, $to);
        } catch (RuntimeException) {
            return [];
        }
    }

    private function valueIn(string $value, string $quote): bool
    {
        $value = mb_strtolower(TenderQuote::spelling($value));

        return $value !== '' && str_contains(mb_strtolower(TenderQuote::spelling($quote)), $value);
    }
}
