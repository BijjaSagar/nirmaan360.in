<?php

namespace Tests\Feature\Reports;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class ReportsTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->org = $this->tenant('Acme Infra', 'business');
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
    }

    protected function tearDown(): void
    {
        // Workbooks and PDFs leave reference cycles behind. The whole suite runs in one process, so collect them.
        gc_collect_cycles();
        parent::tearDown();
    }

    /** A workbook upload built from rows of cell values. A string starting with = is written as a formula. */
    protected function workbook(array $rows, string $name = 'boq.xlsx', string $sheet = 'BOQ', bool $saveFormulaResults = true): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle($sheet);

        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $book->getActiveSheet()->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        $writer = IOFactory::createWriter($book, 'Xlsx');
        // Without this a formula is saved with no result, as a plain generator would write it. It also stops the writer calculating.
        $writer->setPreCalculateFormulas($saveFormulaResults);
        $writer->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** The standard BOQ heading row. */
    protected function boqHeading(): array
    {
        return ['section', 'code', 'description', 'unit', 'qty', 'rate'];
    }

    /** Rows of a downloaded workbook, as arrays of cell values. */
    protected function readWorkbook(TestResponse $response, ?string $sheet = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'read').'.xlsx';
        file_put_contents($path, $response->getContent());

        $book = IOFactory::load($path);
        $worksheet = $sheet ? $book->getSheetByName($sheet) : $book->getSheet(0);
        $rows = $worksheet->toArray(null, false, false, false);
        $book->disconnectWorksheets();
        @unlink($path);

        return $rows;
    }

    protected function assertPdf(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    protected function assertWorkbook(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $response->getContent());
    }
}
