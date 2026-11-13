<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\CrmTenderBoqItem;
use App\Services\Imports\BoqImportLayouts;
use App\Services\Imports\SheetFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Crm\BuildsCrm;

/**
 * The column check page and the files around it: Unicode text files, sheets past the row limit, a unit
 * remembered from an earlier file, the unit box, a template-shaped file read by its own headings, the
 * tender import's messages, and the words the page uses.
 */
class SheetPreviewTest extends BoqImportTestCase
{
    use BuildsCrm;

    public function test_a_unicode_text_file_in_marathi_is_read(): void
    {
        $text = "अ.क्र.\tकामाचा तपशील\tएकक\tपरिमाण\tदर\tरक्कम\r\n1\tपायासाठी खोदकाम करणे\tघ.मी.\t80\t260\t20800\r\n2\tसाधे काँक्रीट टाकणे\tघ.मी.\t12\t5400\t64800\r\n";
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        foreach (['with its mark' => "\xFF\xFE".$utf16, 'without it' => $utf16] as $label => $content) {
            $path = tempnam(sys_get_temp_dir(), 'u16');
            file_put_contents($path, $content);
            $this->assertSame(SheetFile::CSV, SheetFile::sniff($path), $label);

            $project = $this->makeProject($this->org);
            $preview = $this->staged($project, $this->csvFile($content, 'unicode.txt'));
            $this->assertSame([], $preview['errors'], $label);
            $this->assertSame(['B', 2], [$this->columnOf($preview, 'description'), $preview['line_count']], $label);
        }
    }

    public function test_rows_past_the_limit_are_never_dropped_unseen(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', 'Amount'], null, 'A1');
        $serial = 0;

        for ($row = 2; $row <= 5600; $row++) {
            if ($row % 25 === 21) {
                continue;
            }

            $sheet->fromArray($row % 25 === 22 ? [null, 'Block '.$row.' works'] : [++$serial, 'Sample item '.$serial, 'cum', 2, 100, 200], null, 'A'.$row);
        }

        $path = tempnam(sys_get_temp_dir(), 'long').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, new UploadedFile($path, 'long.xlsx', null, null, true));

        $this->assertSame('The sheet has more than 5000 rows. Split it into smaller files.', $preview['errors'][0]['message'] ?? null);
        $this->get(route('boq.sheet.preview', $project))->assertDontSee('Import these');
    }

    public function test_a_unit_typed_for_an_earlier_file_is_only_suggested(): void
    {
        $heading = ['Sr No', 'Description of Work', 'Qty', 'Rate', 'Amount'];
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([$heading, [1, 'Sample concrete for footings', 10, 5000, 50000]]));
        $this->choose($project, ['unit' => 'cum']);
        $this->confirm($project)->assertSessionHas('success');

        $second = $this->makeProject($this->org);
        $preview = $this->staged($second, $this->sheet([$heading, [1, 'Sample floor tiles', 300, 900, 270000]]));
        $this->assertSame([null, 'cum', true], [$preview['unit'], $preview['suggested_unit'], $preview['needs_unit']]);
        $this->assertSame('unit', $preview['errors'][0]['field']);
        $this->get(route('boq.sheet.preview', $second))->assertSee('The last file in this layout used cum. Type it again to use it.');

        // The tender import has no page to ask on, so it still needs a unit column.
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([$heading, [1, 'Sample floor tiles', 300, 900, 270000], [2, 'Sample door shutters', 12, 4000, 48000]])])
            ->assertSessionHasErrors(['file' => 'This sheet has no unit column. Add one, with the unit of each line.']);
        $this->assertSame(0, CrmTenderBoqItem::count());
    }

    public function test_the_unit_box_never_stops_the_columns_being_changed(): void
    {
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([['Sr No', 'Description', 'Qty', 'Rate'], [1, 'Sample item one', 5, 100], [2, 'Sample item two', 6, 200]]));

        $html = $this->get(route('boq.sheet.preview', $project))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input id="unit"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="unit"[^>]*\srequired/', $html);
    }

    public function test_a_template_shaped_file_with_section_rows_is_read_by_its_own_headings(): void
    {
        $project = $this->makeProject($this->org);
        $this->upload($project, $this->workbook([$this->boqHeading(), ['', 'A', 'EARTH WORK', '', '', ''], ['', '1', 'Sample excavation', 'cum', 10, 200]]))
            ->assertRedirect(route('boq.excel.index', $project));
        $this->get(route('boq.excel.index', $project))->assertSee('Read it by its own headings');

        $preview = $this->preview($project);
        $this->assertSame([], $preview['errors']);
        $this->confirm($project)->assertSessionHas('success');

        $line = BoqItem::with('section')->where('project_id', $project->id)->sole();
        $this->assertSame(['1', 'Sample excavation', 'EARTH WORK'], [$line->code, $line->description, $line->section->name]);
    }

    public function test_the_tender_import_names_a_missing_unit(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            ['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            ['1', 'Sample pipes 110 mm', 'Rmt', 120, 450, 54000],
            ['2', 'Sample extra over for bends', null, 'QRO', 300, null],
            ['3', 'Sample valves', 'Nos', 10, 1200, 12000],
        ])])->assertSessionHasErrors(['file' => 'Line 3 has no unit. Add the unit to that row and upload the file again.']);
        $this->assertSame(0, CrmTenderBoqItem::count());
    }

    public function test_the_page_counts_only_rows_truly_left_out_and_says_nothing_is_imported_yet(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            ['A', 'SAMPLE WORKS'],
            [1, 'Sample work one', 'cum', 10, 100, 1000],
            [2, 'Sample work two', 'cum', 5, 100, 500],
            [null, 'Sub Total', null, null, null, 9000],
            [null, null, 'Signed: Sample Engineer'],
        ]));

        // The section and the subtotal are used; only the signature is left out.
        $this->assertSame(1, $preview['left_out']);
        $this->get(route('boq.sheet.preview', $project))->assertSee('add up to')->assertDontSee('were imported');
    }

    public function test_the_read_as_choices_differ_in_every_language(): void
    {
        foreach (['en', 'hi', 'mr'] as $locale) {
            App::setLocale($locale);
            $labels = BoqImportLayouts::fieldLabels();
            $this->assertSame(count($labels), count(array_unique($labels)), $locale);
        }
    }
}
