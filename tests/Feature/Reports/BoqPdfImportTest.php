<?php

namespace Tests\Feature\Reports;

use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\BoqPdfImport;
use App\Services\Reports\BoqPdfReader;
use App\Support\Dec;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/**
 * A BOQ read from a PDF. The PDFs are made here with dompdf, in the two layouts people upload:
 * the Excel template and an engineer's estimate. Every figure is invented.
 */
class BoqPdfImportTest extends ReportsTestCase
{
    private const ESTIMATE_HEADING = ['Sr. No.', 'Description of Items', 'Qty.', 'Rate', 'Unit', 'Amount in Rs.'];

    /** The same invented estimate as EstimateImportTest, as table rows. @return list<list<string>> */
    private function estimate(string $civilTotal = '1055000'): array
    {
        return [
            self::ESTIMATE_HEADING,
            ['1', 'Excavation for foundation in soft murum including lift and lead up to 50 m and disposal of surplus material', '10', '350', 'Rs/cu.m', '3500'],
            ['2', 'Providing and casting in situ cement concrete M-20 for R.C.C. works (Excluding reinforcement)', '', '4000', 'Rs/cu.m', '40000'],
            ['', 'a. Column footing 4', '1', '', '', ''],
            ['', 'b. Slabs 1.5', '4', '', '', ''],
            ['3', 'Providing R.C.C. centering works as per drawing', '', '', 'Rs/sqm', '3500'],
            ['', 'a. Plinth area 2', '2', '750', '', ''],
            ['', 'b. Slab area 1', '1', '2000', '', ''],
            ['4', 'Providing and fixing Teakwood door', '2', '15000', 'Rs/door', '30000'],
            ['', 'Providing and fixing flush door', '3', '4000', 'Rs/door', '12000'],
            ['5', 'Providing HYSD reinforcement bars of various diameters', '1', '66000', 'Rs/M.T', '66000'],
            ['6', 'Providing elevator', '1', '1', '', '900000'],
            ['', 'Total Civil Works', '', '', '', $civilTotal],
            ['7', 'Add 6% paint work on inner and external walls', '', '', '', '6000'],
            ['7', 'Add 6% towards supervision and work charge establishment.', '', '', '', '6000'],
            ['', 'Total Estimate Amount', '', '', '', '1067000'],
            ['', 'Say', '', '', '', '64020'],
        ];
    }

    /** @return list<list<string>> */
    private function template(int $rows = 3): array
    {
        $table = [$this->boqHeading()];

        for ($i = 1; $i <= $rows; $i++) {
            $table[] = [$i % 2 ? 'Substructure' : 'Superstructure', 'C'.$i, 'Line '.$i.' of the works, with enough words to need a second line in a narrow column', 'cum', (string) ($i * 2), (string) ($i * 100 + 0.5)];
        }

        return $table;
    }

    /**
     * A PDF of a table, as a browser or Excel would print it.
     *
     * @param  list<list<string>>  $rows  The heading first.
     * @param  array{borders?: bool, title?: bool, words?: bool}  $options
     */
    private function pdf(array $rows, array $options = [], string $name = 'estimate.pdf'): UploadedFile
    {
        $borders = $options['borders'] ?? true;
        $html = '<html><head><style>body{font-family:DejaVu Sans;font-size:9pt} table{border-collapse:collapse;width:100%} th,td{padding:3px;vertical-align:top;'.($borders ? 'border:1px solid #000;' : '').'} td.n{text-align:right}</style></head><body>';

        if ($options['title'] ?? true) {
            $html .= '<p>Estimate for a sample bungalow</p><p>Client: A Client, Somewhere</p>';
        }

        $html .= '<table><thead><tr>'.implode('', array_map(fn (string $heading) => '<th>'.e($heading).'</th>', $rows[0])).'</tr></thead><tbody>';

        foreach (array_slice($rows, 1) as $row) {
            $html .= '<tr>';

            foreach (array_pad($row, count($rows[0]), '') as $cell) {
                $html .= '<td'.(is_numeric($cell) ? ' class="n"' : '').'>'.e($cell).'</td>';
            }

            $html .= '</tr>';
        }

        if ($options['words'] ?? true) {
            $html .= '<tr><td colspan="'.count($rows[0]).'">IN WORDS (RS. SOME AMOUNT ONLY)</td></tr>';
        }

        $html .= '</tbody></table></body></html>';

        return $this->upload(Pdf::loadHTML($html)->setPaper('a4')->output(), $name);
    }

    /** A PDF written by hand: pages with no text, such as a scan. */
    private function textlessPdf(int $pages, string $content = '0.5 g 50 50 495 742 re f'): UploadedFile
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];

        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (3 + $i * 2).' 0 R';
        }

        $objects[] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';

        for ($i = 0; $i < $pages; $i++) {
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents '.(4 + $i * 2).' 0 R >>';
            $objects[] = '<< /Length '.strlen($content).' >>'."\nstream\n".$content."\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer << /Root 1 0 R /Size '.(count($objects) + 1)." >>\nstartxref\n".$xref."\n%%EOF\n";

        return $this->upload($pdf, 'scan.pdf');
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function uploadAs(User $user, Project $project, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user)->post(route('boq.excel.import', $project), ['file' => $file]);
    }

    private function token(): ?string
    {
        return session(BoqPdfImport::SESSION);
    }

    /** @return list<array<string, ?string>> The lines of a project as plain values, in BOQ order. */
    private function lines(Project $project): array
    {
        return BoqItem::with('section')->where('project_id', $project->id)->orderBy('sort')->get()
            ->map(fn (BoqItem $item) => ['code' => $item->code, 'description' => $item->description, 'unit' => $item->unit, 'qty' => $item->qty, 'rate' => $item->rate, 'category' => $item->category, 'details' => $item->details, 'section' => $item->section?->name])
            ->all();
    }

    public function test_an_uploaded_pdf_is_shown_first_and_nothing_is_written(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()))->assertRedirect(route('boq.pdf.preview', $project));

        $this->assertNotNull($this->token());
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(0, BoqSection::where('project_id', $project->id)->count());
        $this->assertSame(0, BoqRevision::where('project_id', $project->id)->count());

        $page = $this->get(route('boq.pdf.preview', $project))->assertOk();
        $page->assertSee('Nothing has been saved yet')
            ->assertSee('Providing and fixing Teakwood door')
            ->assertSee('Excavation for foundation in soft murum including lift and lead up to 50 m and disposal of surplus material')
            ->assertSee('Import these 10 rows')
            ->assertSee('Matches the lines.')
            ->assertSee(inr('1067000'))
            ->assertSee('Add-ons')
            ->assertDontSee('Total Civil Works');

        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_confirming_writes_the_same_lines_as_the_excel_import_of_the_same_rows(): void
    {
        $this->actingAs($this->pm);
        $excel = $this->makeProject($this->org, ['code' => 'XLS1']);
        $excelResult = app(BoqExcelService::class)->import($excel, $this->workbook($this->estimate(), 'estimate.xlsx', 'Sheet1')->getRealPath());
        $this->assertTrue($excelResult->ok(), json_encode($excelResult->errors));

        $pdf = $this->makeProject($this->org, ['code' => 'PDF1']);
        $this->uploadAs($this->pm, $pdf, $this->pdf($this->estimate()))->assertRedirect(route('boq.pdf.preview', $pdf));

        $this->post(route('boq.pdf.confirm', $pdf), ['token' => $this->token()])
            ->assertRedirect(route('boq.excel.index', $pdf))
            ->assertSessionHas('success', '10 BOQ rows imported from the PDF.');

        $this->assertSame($this->lines($excel), $this->lines($pdf));
        $this->assertSame(['1', '2', '3a', '3b', '4', '4.1', '5', '6', '7', '7.1'], array_column($this->lines($pdf), 'code'));

        $total = BoqItem::where('project_id', $pdf->id)->get()->reduce(fn ($sum, BoqItem $item) => $sum->plus($item->amount()), Dec::of(0));
        $this->assertSame('1067000.00', Dec::money($total));

        $this->assertSame('pdf_import', BoqRevision::where('project_id', $pdf->id)->first()->source);
        $this->assertNull($this->token());
        $this->assertEquals(['created' => 10, 'updated' => 0, 'unchanged' => 0], collect(session('boq_import'))->only(['created', 'updated', 'unchanged'])->all());

        $this->get(route('boq.excel.index', $pdf))->assertOk()->assertSee('Import finished');

        // The preview is gone once it is written.
        $this->get(route('boq.pdf.preview', $pdf))->assertRedirect(route('boq.excel.index', $pdf));
    }

    public function test_the_template_layout_is_read_with_its_sections_and_held_to_the_strict_rules(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->pdf($this->template(), ['words' => false]))->assertRedirect(route('boq.pdf.preview', $project));
        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('Import these 3 rows')->assertSee('Superstructure');
        $this->post(route('boq.pdf.confirm', $project), ['token' => $this->token()])->assertRedirect(route('boq.excel.index', $project));

        $lines = $this->lines($project);
        $this->assertSame(['C1', 'C2', 'C3'], array_column($lines, 'code'));
        $this->assertSame('Line 2 of the works, with enough words to need a second line in a narrow column', $lines[1]['description']);
        $this->assertSame('4.000', $lines[1]['qty']);
        $this->assertSame('200.50', $lines[1]['rate']);
        $this->assertSame('Superstructure', $lines[1]['section']);

        // A template row without a code is a problem, so the preview has no confirm button and confirming writes nothing.
        $other = $this->makeProject($this->org);
        $rows = $this->template(2);
        $rows[2][1] = '';
        $this->uploadAs($this->pm, $other, $this->pdf($rows, ['words' => false]));
        $this->get(route('boq.pdf.preview', $other))->assertOk()->assertSee('This PDF cannot be imported as it is')->assertSee('Item code is missing')->assertDontSee('Import these');
        $this->post(route('boq.pdf.confirm', $other), ['token' => $this->token()])->assertRedirect(route('boq.excel.index', $other));
        $this->assertSame(0, BoqItem::where('project_id', $other->id)->count());
        $this->get(route('boq.excel.index', $other))->assertOk()->assertSee('Nothing was imported')->assertSee('Item code is missing');
    }

    public function test_wrapped_descriptions_are_joined_even_when_the_table_has_no_rules(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate(), ['borders' => false]))->assertRedirect(route('boq.pdf.preview', $project));
        $this->post(route('boq.pdf.confirm', $project), ['token' => $this->token()])->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['1', '2', '3a', '3b', '4', '4.1', '5', '6', '7', '7.1'], array_column($lines, 'code'));
        $this->assertSame('Excavation for foundation in soft murum including lift and lead up to 50 m and disposal of surplus material', $lines[0]['description']);
        $this->assertSame('Providing and casting in situ cement concrete M-20 for R.C.C. works (Excluding reinforcement)', $lines[1]['description']);
        $this->assertStringContainsString('a. Column footing 4 × 1; b. Slabs 1.5 × 4', $lines[1]['details']);
    }

    public function test_a_long_table_runs_over_pages_with_the_heading_repeated(): void
    {
        $project = $this->makeProject($this->org);
        $file = $this->pdf($this->template(90), ['words' => false]);

        $this->assertGreaterThan(1, app(BoqPdfReader::class)->read($file->getRealPath())->pages);

        $this->uploadAs($this->pm, $project, $file)->assertRedirect(route('boq.pdf.preview', $project));
        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('Import these 90 rows');
        $this->post(route('boq.pdf.confirm', $project), ['token' => $this->token()])->assertSessionHas('success');

        $codes = array_column($this->lines($project), 'code');
        $this->assertCount(90, $codes);
        $this->assertSame('C1', $codes[0]);
        $this->assertSame('C90', $codes[89]);
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->where('description', 'like', '%description%')->count());
    }

    public function test_the_preview_points_out_a_total_that_does_not_match(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate(civilTotal: '1000000')));

        $this->get(route('boq.pdf.preview', $project))->assertOk()
            ->assertSee('Does not match the lines')
            ->assertSee('Please check these')
            ->assertSee('add up to');
    }

    public function test_a_scanned_pdf_is_refused_with_a_plain_message(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->textlessPdf(2))->assertRedirect(route('boq.excel.index', $project));

        $this->assertNull($this->token());
        $message = session('boq_import.errors.0.message');
        $this->assertStringContainsString('scanned copy', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(AI|OCR|recogni)/i', $message);
        $this->get(route('boq.excel.index', $project))->assertOk()
            ->assertSee('Nothing was imported')
            ->assertSee('upload the Excel file');
    }

    public function test_a_damaged_or_wrong_file_fails_with_an_ordinary_message(): void
    {
        $project = $this->makeProject($this->org);
        $message = 'This file could not be read as a PDF';

        foreach ([
            "%PDF-1.4\n".random_bytes(4000),
            'not a pdf at all',
            "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 1 0 R >> endobj\ntrailer << /Root 1 0 R >>",
        ] as $bytes) {
            $this->uploadAs($this->pm, $project, $this->upload($bytes, 'bad.pdf'))->assertRedirect(route('boq.excel.index', $project));
            $this->assertStringContainsString($message, session('boq_import.errors.0.message'));
        }

        // A file cut short part way through is read as far as it goes or refused, never a server error.
        $whole = file_get_contents($this->pdf($this->estimate())->getRealPath());
        $response = $this->uploadAs($this->pm, $project, $this->upload(substr($whole, 0, (int) (strlen($whole) * 0.6)), 'cut.pdf'));
        $this->assertContains($response->getStatusCode(), [302]);
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        // A PDF with text but no table of the right shape says so.
        $letter = '<p>Dear Sir,</p><p>This letter is about the site and carries no table.</p><p>Work is going well.</p><p>Regards,</p><p>The engineer</p>';
        $this->uploadAs($this->pm, $project, $this->upload(Pdf::loadHTML($letter)->output(), 'letter.pdf'));
        $this->assertStringContainsString('Could not find the heading row in the PDF', session('boq_import.errors.0.message'));
    }

    public function test_size_and_page_limits_are_enforced(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'))->assertSessionHasErrors('file');
        $this->assertNull($this->token());

        $this->uploadAs($this->pm, $project, $this->textlessPdf(BoqPdfReader::MAX_PAGES + 1))->assertRedirect(route('boq.excel.index', $project));
        $this->assertStringContainsString('51 pages', session('boq_import.errors.0.message'));
        $this->assertNull($this->token());
    }

    public function test_an_expired_preview_cannot_be_seen_or_confirmed(): void
    {
        $project = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));
        $token = $this->token();

        $this->travel(BoqPdfImport::TTL_MINUTES + 1)->minutes();

        $this->get(route('boq.pdf.preview', $project))->assertRedirect(route('boq.excel.index', $project));
        $this->assertStringContainsString('expired', session('boq_import.errors.0.message'));

        $this->post(route('boq.pdf.confirm', $project), ['token' => $token])->assertRedirect(route('boq.excel.index', $project));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_someone_elses_token_and_another_projects_token_are_refused(): void
    {
        $project = $this->makeProject($this->org);
        $other = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));
        $token = $this->token();

        $colleague = $this->member($this->org, 'pm');
        $this->actingAs($colleague)->post(route('boq.pdf.confirm', $project), ['token' => $token])->assertRedirect(route('boq.excel.index', $project));
        $this->assertStringContainsString('belongs to someone else', session('boq_import.errors.0.message'));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        $this->actingAs($this->pm)->post(route('boq.pdf.confirm', $other), ['token' => $token])->assertRedirect(route('boq.excel.index', $other));
        $this->assertSame(0, BoqItem::where('project_id', $other->id)->count());

        foreach (['', 'short', str_repeat('x', 40)] as $bad) {
            $this->actingAs($this->pm)->post(route('boq.pdf.confirm', $project), ['token' => $bad])->assertRedirect(route('boq.excel.index', $project));
        }

        // The rightful owner can still go ahead.
        $this->actingAs($this->pm)->post(route('boq.pdf.confirm', $project), ['token' => $token])->assertSessionHas('success');
        $this->assertSame(10, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_another_company_and_a_person_limited_to_other_projects_get_a_404(): void
    {
        $project = $this->makeProject($this->org);
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->uploadAs($outsider, $project, $this->pdf($this->estimate()))->assertNotFound();
        $this->actingAs($outsider)->get(route('boq.pdf.preview', $project))->assertNotFound();
        $this->actingAs($outsider)->post(route('boq.pdf.confirm', $project), ['token' => str_repeat('a', 40)])->assertNotFound();

        $elsewhere = $this->makeProject($this->org);
        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $limited->refresh();

        $this->uploadAs($limited, $project, $this->pdf($this->estimate()))->assertNotFound();
        $this->actingAs($limited)->get(route('boq.pdf.preview', $project))->assertNotFound();
        $this->actingAs($limited)->post(route('boq.pdf.confirm', $project), ['token' => str_repeat('a', 40)])->assertNotFound();
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_without_boq_manage_every_pdf_route_is_forbidden(): void
    {
        $project = $this->makeProject($this->org);
        $engineer = $this->member($this->org, 'engineer');

        $this->uploadAs($engineer, $project, $this->pdf($this->estimate()))->assertForbidden();
        $this->actingAs($engineer)->get(route('boq.pdf.preview', $project))->assertForbidden();
        $this->actingAs($engineer)->post(route('boq.pdf.confirm', $project), ['token' => str_repeat('a', 40)])->assertForbidden();
        $this->actingAs($engineer)->post(route('boq.pdf.discard', $project), ['token' => str_repeat('a', 40)])->assertForbidden();
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_discarding_the_preview_forgets_it(): void
    {
        $project = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));
        $token = $this->token();

        $this->post(route('boq.pdf.discard', $project), ['token' => $token])
            ->assertRedirect(route('boq.excel.index', $project))
            ->assertSessionHas('success', 'The PDF was set aside. Nothing was imported.');

        $this->assertNull($this->token());
        $this->assertNull(Cache::get('boq-pdf-import:'.$token));
        $this->get(route('boq.pdf.preview', $project))->assertRedirect(route('boq.excel.index', $project));
        $this->post(route('boq.pdf.confirm', $project), ['token' => $token])->assertRedirect(route('boq.excel.index', $project));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_excel_upload_still_imports_straight_away(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->workbook($this->estimate(), 'estimate.xlsx', 'Sheet1'))
            ->assertRedirect(route('boq.excel.index', $project))
            ->assertSessionHas('success');

        $this->assertSame(10, BoqItem::where('project_id', $project->id)->count());
        $this->assertNull($this->token());
    }

    public function test_the_preview_reads_in_hindi(): void
    {
        $project = $this->makeProject($this->org);
        $this->pm->update(['locale' => 'hi']);

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));

        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('इम्पोर्ट से पहले PDF जाँचें')->assertSee('ये 10 पंक्तियाँ इम्पोर्ट करें');
    }

    public function test_reading_a_pdf_gives_no_time_limit_to_a_process_that_has_none(): void
    {
        // The console, the queue and this test run have no limit. One set here would stop the process later, part way through other work.
        $this->assertSame('0', ini_get('max_execution_time'));

        $read = app(BoqPdfReader::class)->read($this->pdf($this->estimate())->getRealPath());

        $this->assertTrue($read->ok());
        $this->assertSame('0', ini_get('max_execution_time'));
    }

    public function test_reading_a_pdf_in_a_web_request_raises_a_shorter_time_limit_only(): void
    {
        try {
            // A web request gets a limit from PHP-FPM. A short one is raised to 120 seconds, a longer one is kept.
            foreach (['30' => '120', '300' => '300'] as $given => $expected) {
                set_time_limit((int) $given);

                $this->assertTrue(app(BoqPdfReader::class)->read($this->pdf($this->estimate())->getRealPath())->ok());
                $this->assertSame($expected, ini_get('max_execution_time'));
            }
        } finally {
            set_time_limit(0);
        }
    }
}
