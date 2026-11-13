<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Reports\ReportsTestCase;

/**
 * BOQ files in contractors' own layouts. Every file is built here with invented text and figures,
 * in the shapes such files come in: letterheads, two-row headings, Marathi and Hindi headings,
 * engineers' estimates, rate cards and tender schedules.
 */
abstract class BoqImportTestCase extends ReportsTestCase
{
    /**
     * A workbook from sheets of rows. A null cell is left empty.
     *
     * @param  array<string, list<array<int, mixed>>>  $sheets  Sheet name => rows.
     * @param  list<string>  $merges  Merged ranges on the first sheet, such as A1:A2.
     */
    protected function book(array $sheets, string $name = 'boq.xlsx', string $writer = 'Xlsx', array $merges = []): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($title);

            foreach ($rows as $r => $cells) {
                foreach (array_values($cells) as $c => $value) {
                    if ($value !== null && $value !== '') {
                        $sheet->setCellValue([$c + 1, $r + 1], $value);
                    }
                }
            }
        }

        foreach ($merges as $range) {
            $book->getSheet(0)->mergeCells($range);
        }

        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.'.strtolower($writer);
        IOFactory::createWriter($book, $writer)->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param  list<array<int, mixed>>  $rows */
    protected function sheet(array $rows, string $name = 'boq.xlsx', string $writer = 'Xlsx', array $merges = []): UploadedFile
    {
        return $this->book(['Sheet1' => $rows], $name, $writer, $merges);
    }

    protected function csvFile(string $content, string $name = 'boq.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    protected function upload(Project $project, UploadedFile $file, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->pm)->post(route('boq.excel.import', $project), ['file' => $file]);
    }

    /** Upload a file and expect it on the column check. Returns what the preview shows. */
    protected function staged(Project $project, UploadedFile $file, ?User $user = null): array
    {
        $this->upload($project, $file, $user)->assertRedirect(route('boq.sheet.preview', $project));

        return $this->preview($project);
    }

    protected function preview(Project $project): array
    {
        return $this->get(route('boq.sheet.preview', $project))->assertOk()->viewData('preview');
    }

    protected function choose(Project $project, array $input): TestResponse
    {
        return $this->post(route('boq.sheet.choose', $project), $input);
    }

    protected function confirm(Project $project): TestResponse
    {
        return $this->post(route('boq.sheet.confirm', $project), ['version' => $this->preview($project)['version']]);
    }

    /** @return array<string, BoqItem> The project's lines by item code. */
    protected function lines(Project $project): array
    {
        return BoqItem::with('section')->where('project_id', $project->id)->orderBy('sort')->get()->keyBy('code')->all();
    }

    /** The column a field was read from, as a letter. */
    protected function columnOf(array $preview, string $field): ?string
    {
        foreach ($preview['columns'] as $column) {
            if ($column['field'] === $field) {
                return $column['letter'];
            }
        }

        return null;
    }
}
