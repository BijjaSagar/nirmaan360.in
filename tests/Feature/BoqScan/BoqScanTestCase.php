<?php

namespace Tests\Feature\BoqScan;

use App\Models\BoqItem;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Project;
use App\Models\User;
use App\Services\BoqScan\BoqReadKind;
use App\Services\BoqScan\BoqScanIngest;
use App\Services\BoqScan\ScanReconciler;
use App\Services\BoqScan\ScanState;
use App\Services\DocumentReading\AiDocumentConsent;
use Illuminate\Support\Facades\Route;
use Tests\Feature\DocumentReading\PageReadingTestCase;

/**
 * An invented company on the Business plan that may use the readers, with the BOQ reader registered
 * in place of the stand-in the reader's own tests use, and a project whose BOQ is being scanned.
 * Every company, description and figure here is made up.
 *
 * stage() puts a page through the same staging the page reader runs once a page has been read, so
 * the checks can be tested without the provider; BoqScanReaderTest runs the whole path.
 */
abstract class BoqScanTestCase extends PageReadingTestCase
{
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // The module registers its kind once its screens are in place; until then each test does.
        config(['modules.reader_stand_ins.read_kinds' => [], 'modules.boq_scan.read_kinds' => [BoqReadKind::class]]);

        $this->project = $this->makeProject($this->organization, ['code' => 'NB1', 'name' => 'North Block']);
    }

    /**
     * The scan screen is registered only while the kind is registered in the module's config file,
     * and the app was booted before setUp registered it. This loads the module's routes again, inside
     * the signed-in group routes/web.php puts them in, as a company with the reader has them.
     */
    protected function withScanScreen(): void
    {
        Route::middleware(['web', 'auth', 'active'])->group(base_path('routes/modules/boq_scan.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    /** A BOQ read of the project, ready for review unless told otherwise. */
    protected function scan(array $overrides = [], ?User $by = null): DocumentRead
    {
        return $this->makeRead($this->organization, $by ?? $this->owner, [
            'project_id' => $this->project->id,
            'status' => DocumentRead::READY,
            'pages_total' => 2,
            'consent_version' => AiDocumentConsent::VERSION,
            'options' => ['language' => 'en'],
            ...$overrides,
        ]);
    }

    /**
     * One page as the reader returned it, staged as the page reader stages it: the first reading,
     * then, for a table, the second.
     *
     * @param  list<array<int, string>>  $rows  [item_no, description, unit, qty, rate, kind = item, state of qty = read, state of rate = read]
     * @param  ?list<array<int, string>>  $amounts  [item_no, amount, state = read], top to bottom. Null for no second reading.
     */
    protected function stage(DocumentRead $read, int $number, array $rows, ?array $amounts = [], string $pageKind = 'boq_table', string $part = 'full', array $columns = ['item_no', 'description', 'unit', 'qty', 'rate', 'amount']): void
    {
        $cell = fn (string $raw, string $state = 'read') => ['raw' => $raw, 'state' => $raw === '' && $state === 'read' ? 'absent' : $state];
        $first = [];

        foreach ($rows as $index => $row) {
            $first[] = [
                'row_no' => $index + 1, 'kind' => $row[5] ?? 'item', 'y_top' => 100 + $index * 80, 'y_bottom' => 160 + $index * 80,
                'item_no' => $cell($row[0]), 'description' => $cell($row[1]), 'unit' => $cell($row[2]),
                'qty' => $cell($row[3], $row[6] ?? 'read'), 'rate' => $cell($row[4], $row[7] ?? 'read'),
            ];
        }

        $half = $part === 'full' ? [] : ['part_top' => $part === 'top' ? 0 : 450, 'part_height' => 550];
        $rowsPage = $this->makePage($read, $number, ['pass' => 'rows', 'part' => $part, 'status' => DocumentReadPage::READ, 'image_height' => 2000, 'result' => ['page_kind' => $pageKind, 'column_order' => $columns, 'rows' => $first], ...$half]);
        $this->ingest($rowsPage, $read);

        if ($amounts === null || $pageKind !== 'boq_table') {
            return;
        }

        $second = [];

        foreach ($amounts as $index => $amount) {
            // An amount sits on the row of the same position unless it names a row by number.
            $at = $amount[3] ?? $index;
            $second[] = ['row_no' => $index + 1, 'y_top' => 100 + $at * 80, 'y_bottom' => 160 + $at * 80, 'item_no' => $cell($amount[0]), 'amount' => $cell($amount[1], $amount[2] ?? 'read')];
        }

        $amountsPage = $this->makePage($read, $number, ['pass' => 'amounts', 'part' => $part, 'model' => 'claude-haiku-5-5', 'status' => DocumentReadPage::READ, 'image_height' => 2000, 'result' => ['rows' => $second], ...$half]);
        $this->ingest($amountsPage, $read);
    }

    protected function ingest(DocumentReadPage $page, DocumentRead $read): void
    {
        $page->setRelation('documentRead', DocumentRead::withoutGlobalScopes()->findOrFail($read->id));
        app(BoqScanIngest::class)->pageRead($page);
    }

    protected function state(DocumentRead $read): ScanState
    {
        return app(ScanReconciler::class)->run(DocumentRead::withoutGlobalScopes()->findOrFail($read->id));
    }

    /** The row whose code or printed item number is this. */
    protected function row(DocumentRead $read, string $code): BoqScanRow
    {
        return BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)
            ->where(fn ($query) => $query->where('code', $code)->orWhere('item_no_raw', $code))->orderBy('sort')->firstOrFail();
    }

    /** @return list<string> The codes of the flags standing on a row, open or not. */
    protected function codesOn(DocumentRead $read, BoqScanRow|string $row, bool $openOnly = false): array
    {
        $row = $row instanceof BoqScanRow ? $row : $this->row($read, $row);
        $flags = $this->state($read)->flagsOf($row);

        return array_values(array_map(fn ($flag) => $flag['code'], $openOnly ? array_filter($flags, ScanState::open(...)) : $flags));
    }

    /** A clean two-line page with its printed total: every line multiplies out and adds up. */
    protected function cleanPage(DocumentRead $read, int $number = 1): void
    {
        $this->stage($read, $number, [
            ['', 'EARTHWORK', '', '', '', 'heading'],
            ['1', 'Excavation in ordinary soil', 'cum', '120.500', '450.00'],
            ['2', 'Filling with murum in layers', 'cum', '80.000', '300.00'],
            ['3', 'PCC 1:4:8 below footings', 'cum', '12.000', '5200.00'],
            ['', 'Total', '', '', '', 'total'],
        ], [
            ['1', '54,225.00', 'read', 1],
            ['2', '24,000.00', 'read', 2],
            ['3', '62,400.00', 'read', 3],
            ['', '1,40,625.00', 'read', 4],
        ]);
    }

    /** Confirms every line one by one, as a person would after checking each. */
    protected function confirmAll(DocumentRead $read): void
    {
        foreach (BoqScanRow::withoutGlobalScopes()->where('document_read_id', $read->id)->whereIn('kind', BoqScanRow::IMPORTED)->get() as $row) {
            $row->forceFill(['confirmed_by' => $this->owner->id, 'confirmed_at' => now(), 'confirmed_mode' => 'row'])->save();
        }
    }

    protected function boqLine(string $code, string $qty = '100.000', string $rate = '500.00', string $unit = 'cum'): BoqItem
    {
        return BoqItem::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'code' => $code,
            'description' => 'Existing line '.$code,
            'unit' => $unit,
            'qty' => $qty,
            'rate' => $rate,
            'sort' => (int) BoqItem::withoutGlobalScopes()->where('project_id', $this->project->id)->max('sort') + 1,
        ]);
    }
}
