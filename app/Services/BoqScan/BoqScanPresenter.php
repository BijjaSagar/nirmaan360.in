<?php

namespace App\Services\BoqScan;

use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\PrintedNumber;
use App\Support\Dec;
use Illuminate\Support\Facades\Route;

/**
 * The review of a scanned BOQ as JSON, one document page at a time, for the review screen. Every
 * figure here is money or comes with it, so the routes that show it ask for money.view.
 *
 * Values are canonical decimal strings, never floats. Text the reader returned is data: the screen
 * escapes it and never uses it as markup, a link or a path.
 */
class BoqScanPresenter
{
    private const STATUSES = [DocumentReadPage::READ, DocumentReadPage::FAILED, DocumentReadPage::RUNNING, DocumentReadPage::QUEUED, DocumentReadPage::WAITING, DocumentReadPage::SKIPPED];

    public function __construct(private ScanReconciler $reconciler) {}

    /**
     * The rows of one document page, that page's checks, a line per page of the document for moving
     * between them, the checks of the whole read and the totals.
     *
     * @return array<string, mixed>
     */
    public function page(DocumentRead $read, int $number): array
    {
        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->findOrFail($read->id);
        $state = $this->reconciler->run($read);

        $rows = BoqScanRow::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->where('page_no', $number)->orderBy('sort')->orderBy('id')->get();

        return [
            'read' => ['id' => $read->id, 'status' => $read->status, 'pages_total' => (int) $read->pages_total, 'language' => $read->options['language'] ?? null],
            'rows' => $rows->map(fn (BoqScanRow $row) => $this->row($row, $state))->all(),
            'page' => $this->pageInfo($read, $number, $state),
            'pages' => $this->pages($read, $state),
            'read_flags' => $this->flags($state->read),
            'summary' => $state->summary,
        ];
    }

    /** @return array<string, mixed> One row and the totals, after a change to it. */
    public function changed(DocumentRead $read, BoqScanRow $row): array
    {
        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->findOrFail($read->id);
        $state = $this->reconciler->run($read);

        return ['row' => $this->row($row->refresh(), $state), 'summary' => $state->summary];
    }

    /** @return array<string, mixed> */
    public function row(BoqScanRow $row, ScanState $state): array
    {
        $raw = [
            'item_no' => (string) $row->item_no_raw,
            'description' => (string) $row->description_raw,
            'unit' => (string) $row->unit_raw,
            'qty' => (string) $row->qty_raw,
            'rate' => (string) $row->rate_raw,
            'amount' => (string) $row->amount_raw,
        ];

        $hadComma = array_values(array_filter(['qty', 'rate', 'amount'], fn (string $field) => $raw[$field] !== '' && PrintedNumber::parse($raw[$field], $field)->hadComma));
        $product = $row->qty !== null && $row->rate !== null ? Dec::money(Dec::of($row->qty)->multipliedBy(Dec::of($row->rate))) : null;

        return [
            'id' => $row->id,
            'page_no' => (int) $row->page_no,
            'part' => $row->part,
            'sort' => (int) $row->sort,
            'kind' => $row->kind,
            'kind_read' => $row->kind_read,
            'kind_set' => $row->kind_set_by !== null,
            'added_by_hand' => ! $row->fromReader(),
            'y_top' => $row->y_top === null ? null : (int) $row->y_top,
            'y_bottom' => $row->y_bottom === null ? null : (int) $row->y_bottom,
            'raw' => $raw,
            'state' => [
                'item_no' => $row->stateOf('item_no'),
                'description' => $row->stateOf('description'),
                'unit' => $row->stateOf('unit'),
                'qty' => $row->stateOf('qty'),
                'rate' => $row->stateOf('rate'),
                'amount' => $row->stateOf('amount'),
            ],
            'script' => [
                'qty' => ($row->scripts ?? [])['qty'] ?? null,
                'rate' => ($row->scripts ?? [])['rate'] ?? null,
                'amount' => ($row->scripts ?? [])['amount'] ?? null,
            ],
            'had_comma' => $hadComma,
            'section' => $row->section,
            'code' => $row->code,
            'description' => $row->description,
            'unit' => $row->unit,
            'qty' => $row->qty,
            'rate' => $row->rate,
            'amount' => $row->amount,
            'product' => $product,
            'percent' => $row->percent,
            'typed' => array_values($row->typed ?? []),
            'flags' => $this->flags($state->flagsOf($row)),
            'override' => isset(($row->overrides ?? [])[ScanFlag::AMOUNT_MISMATCH]) && $this->reconciler->overridden($row),
            'confirmed' => $row->confirmed_at !== null,
            'confirmed_mode' => $row->confirmed_mode,
            'can_confirm' => $row->confirmed_at === null && $state->canConfirm($row),
            'page_confirmable' => $state->pageConfirmable($row),
        ];
    }

    /**
     * For the field app, after a read: where it stands and how much a person still has to do. The
     * review itself is on the web.
     *
     * @return array<string, mixed>
     */
    public function forApp(DocumentRead $read): array
    {
        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->findOrFail($read->id);
        $state = $this->reconciler->run($read);
        $summary = $state->summary;

        return [
            'id' => $read->id,
            'status' => $read->status,
            'pages_total' => (int) $read->pages_total,
            'pages_read' => (int) $read->pages_read,
            'pages_failed' => (int) $read->pages_failed,
            'rows' => (int) $summary['rows'],
            'needs_attention' => count(array_filter($state->openFlags(), fn (array $flag) => $flag['code'] !== ScanFlag::UNCONFIRMED_ROW)),
            // The review screen's address. It is on the web only.
            'review_url' => Route::has('boq-scans.show') ? route('boq-scans.show', $read) : url('boq-scans/'.$read->id),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @return list<array<string, mixed>>
     */
    private function flags(array $flags): array
    {
        return array_map(fn (array $flag) => [...$flag, 'message' => ScanFlag::message($flag)], $flags);
    }

    /** @return array<string, mixed> */
    private function pageInfo(DocumentRead $read, int $number, ScanState $state): array
    {
        $info = (array) (($read->review ?? [])['pages'][(string) $number] ?? []);
        $readings = DocumentReadPage::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->where('page_no', $number)->orderBy('id')->get(['id', 'page_no', 'pass', 'part', 'status', 'fail_reason', 'image_path', 'image_width', 'image_height', 'part_top', 'part_height']);

        return [
            'page_no' => $number,
            'status' => $this->statusOf($readings->where('pass', BoqReadKind::ROWS)),
            'readings' => $readings->map(fn (DocumentReadPage $page) => [
                'page_id' => $page->id,
                'pass' => $page->pass,
                'part' => $page->part,
                'status' => $page->status,
                'fail_reason' => $page->fail_reason,
                'width' => $page->image_width === null ? null : (int) $page->image_width,
                'height' => $page->image_height === null ? null : (int) $page->image_height,
                'part_top' => $page->part_top === null ? null : (int) $page->part_top,
                'part_height' => $page->part_height === null ? null : (int) $page->part_height,
                'image_url' => $read->files_purged_at === null && filled($page->image_path) ? route('document-reads.pages.image', [$read, $page]) : null,
            ])->values()->all(),
            'parts' => (array) ($info['parts'] ?? []),
            'extra_amount_rows' => array_values((array) ($info['extra_amount_rows'] ?? [])),
            'no_lines' => isset($info['no_lines']),
            'flags' => $this->flags($state->pageFlags($number)),
            'confirm_clean' => $state->pageMayConfirmClean($number) && $state->cleanRowsOf($number)->isNotEmpty(),
            'clean_rows' => $state->cleanRowsOf($number)->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pages(DocumentRead $read, ScanState $state): array
    {
        $readings = DocumentReadPage::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->where('pass', BoqReadKind::ROWS)->get(['id', 'page_no', 'status']);
        $total = max((int) $read->pages_total, (int) $readings->max('page_no'), (int) $state->live->max('page_no'));
        $pages = [];

        for ($number = 1; $number <= $total; $number++) {
            $onPage = $state->live->where('page_no', $number);
            $open = count(array_filter($state->pageFlags($number), ScanState::open(...)));

            foreach ($onPage as $row) {
                $open += count(array_filter($state->flagsOf($row), ScanState::open(...)));
            }

            $pages[] = [
                'page_no' => $number,
                'status' => $this->statusOf($readings->where('page_no', $number)),
                'rows' => $onPage->count(),
                'open' => $open,
            ];
        }

        return $pages;
    }

    /** One word for where the first reading of a page stands: read when any part of it was read. */
    private function statusOf($readings): ?string
    {
        foreach (self::STATUSES as $status) {
            if ($readings->contains(fn ($page) => $page->status === $status)) {
                return $status;
            }
        }

        return $readings->isEmpty() ? null : 'superseded';
    }
}
