<?php

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\BoqPdfImport;
use App\Services\Reports\BoqPdfReader;
use App\Services\Reports\PdfTable;
use App\Services\Reports\PdfTableTimedOut;
use App\Services\Reports\PdfTextLayer;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DocumentReading\Support\FakeKind;

/**
 * A BOQ read from a PDF. The PDFs are made here with dompdf, in the two layouts people upload:
 * the Excel template and an engineer's estimate. Every figure is invented.
 *
 * Confirming a preview is a statement by a person: the language the PDF is printed in, that the
 * rows were compared with its pages, a tick against every line the reader repaired or left out,
 * and a reason when the file's own total is not what the lines add up to. confirm() below sends
 * those answers the way the preview page does, with the ticks read off the page it was shown.
 */
class BoqPdfImportTest extends ReportsTestCase
{
    use WritesPdfByHand;

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

    /** @return list<string> The value of every tick box the preview page shows under "Please check these". */
    private function ticks(Project $project): array
    {
        preg_match_all('/name="ack_notes\[\]" value="([0-9a-f]{40})"/', (string) $this->get(route('boq.pdf.preview', $project))->assertOk()->getContent(), $found);

        return $found[1];
    }

    /**
     * Confirm the staged PDF as a person does on the preview: the language, the pages tick and a tick for every note shown.
     * $answers replaces any of them. A null takes that answer out.
     */
    private function confirm(Project $project, array $answers = [], ?string $token = null): TestResponse
    {
        $token ??= $this->token();

        return $this->post(route('boq.pdf.confirm', $project), array_filter([
            'token' => $token,
            'printed_language' => 'en',
            'pages_checked' => '1',
            'ack_notes' => array_key_exists('ack_notes', $answers) ? null : $this->ticks($project),
            ...$answers,
        ], fn ($answer) => $answer !== null));
    }

    private function nothingWritten(Project $project): void
    {
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(0, BoqSection::where('project_id', $project->id)->count());
        $this->assertSame(0, BoqRevision::where('project_id', $project->id)->count());
        $this->assertSame(0, AuditLog::where('action', 'boq.pdf_import_confirmed')->where('auditable_id', $project->id)->count());
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

        // The estimate has four places where the import is not what the file says: a quantity worked out, a rate
        // changed to keep the amount, a unit taken as nos, and the "Add %" rows. Each is ticked on its own line.
        $this->assertCount(4, $this->ticks($pdf));

        $this->confirm($pdf)
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
        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('Import these 3 rows')->assertSee('Superstructure')->assertDontSee('Please check these');
        // The template layout is read as it is written, so there is nothing to tick. The language and the pages are still asked.
        $this->assertSame([], $this->ticks($project));
        $this->confirm($project)->assertRedirect(route('boq.excel.index', $project))->assertSessionHas('success');

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
        $this->get(route('boq.pdf.preview', $other))->assertOk()->assertSee('This PDF cannot be imported as it is')->assertSee('Item code is missing')->assertDontSee('Import these')->assertDontSee('Before you import');
        // The answers are not asked for a file that cannot be imported, and giving them changes nothing.
        $token = $this->token();
        $this->confirm($other, ['ack_notes' => []], $token)->assertRedirect(route('boq.excel.index', $other))->assertSessionMissing('success');
        $this->assertSame('Item code is missing.', session('boq_import.errors.0.message'));
        $this->post(route('boq.pdf.confirm', $other), ['token' => $token])->assertRedirect(route('boq.excel.index', $other));
        $this->assertSame(0, BoqItem::where('project_id', $other->id)->count());
        $this->get(route('boq.excel.index', $other))->assertOk()->assertSee('Nothing was imported')->assertSee('Item code is missing');
    }

    public function test_wrapped_descriptions_are_joined_even_when_the_table_has_no_rules(): void
    {
        $project = $this->makeProject($this->org);

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate(), ['borders' => false]))->assertRedirect(route('boq.pdf.preview', $project));
        $this->confirm($project)->assertSessionHas('success');

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
        $this->confirm($project)->assertSessionHas('success');

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
            ->assertSee('add up to')
            ->assertSee('Why the total does not match')
            ->assertSee('name="total_reason"', false);

        // A file whose total agrees is not asked for a reason.
        $agrees = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $agrees, $this->pdf($this->estimate()));
        $this->get(route('boq.pdf.preview', $agrees))->assertOk()->assertSee('Matches the lines.')->assertDontSee('Why the total does not match')->assertDontSee('name="total_reason"', false);
    }

    public function test_confirming_needs_every_answer_and_writes_nothing_without_them(): void
    {
        $project = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));
        $token = $this->token();
        $ticks = $this->ticks($project);
        $preview = route('boq.pdf.preview', $project);
        $this->assertCount(4, $ticks);

        // The bare press of the button, which used to be enough.
        $this->post(route('boq.pdf.confirm', $project), ['token' => $token])
            ->assertRedirect($preview)
            ->assertSessionHasErrors(['printed_language', 'pages_checked', 'ack_notes'])
            ->assertSessionDoesntHaveErrors(['total_reason']);
        $this->nothingWritten($project);

        $refused = [
            'printed_language' => [['printed_language' => null], ['printed_language' => ''], ['printed_language' => 'gu'], ['printed_language' => ['en']]],
            'pages_checked' => [['pages_checked' => null], ['pages_checked' => '0'], ['pages_checked' => 'no'], ['pages_checked' => ['1']]],
            // None ticked, one left out, one swapped for a made-up tick, and a single "all" in place of the ticks.
            'ack_notes' => [['ack_notes' => []], ['ack_notes' => array_slice($ticks, 1)], ['ack_notes' => [...array_slice($ticks, 0, 3), sha1('not a note')]], ['ack_notes' => 'all'], ['ack_notes' => [$ticks]]],
        ];

        foreach ($refused as $field => $cases) {
            foreach ($cases as $answers) {
                $this->confirm($project, $answers, $token)
                    ->assertRedirect($preview)
                    ->assertSessionHasErrors([$field])
                    ->assertSessionDoesntHaveErrors(array_values(array_diff(array_keys($refused), [$field])));
                $this->nothingWritten($project);
                $this->assertSame($token, $this->token(), 'The preview stays staged after a refusal.');
            }
        }

        // The refusal says how many lines are still to tick, and the preview comes back with what was answered.
        $this->confirm($project, ['ack_notes' => array_slice($ticks, 2)], $token)
            ->assertSessionHasErrors(['ack_notes' => 'Every line under "Please check these" needs its own tick. Not ticked yet: 2.']);
        // (Looking into the session from a test starts it a second time, which empties the errors, so this one is only followed.)
        $this->confirm($project, ['ack_notes' => array_slice($ticks, 1)], $token)->assertRedirect($preview);
        $page = $this->get($preview)->assertOk()->assertSee('needs its own tick. Not ticked yet: 1.')->getContent();
        $this->assertSame(2, substr_count($page, 'Not ticked yet: 1.'), 'Once at the head of the page and once above the lines.');
        $this->assertSame(3, preg_match_all('/name="ack_notes\[\]" value="[0-9a-f]{40}"[^>]*\schecked/', $page));
        $this->assertMatchesRegularExpression('/<option value="en" selected/', $page);
        $this->assertMatchesRegularExpression('/name="pages_checked"[^>]*\schecked/', $page);
        $this->nothingWritten($project);

        // With every answer it is written, and who answered what is kept.
        $this->confirm($project, ['total_reason' => 'Not asked for, so not kept.'], $token)->assertRedirect(route('boq.excel.index', $project))->assertSessionHas('success');
        $this->assertSame(10, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(['Imported from a PDF file'], BoqRevision::where('project_id', $project->id)->pluck('reason')->unique()->values()->all());

        $log = AuditLog::where('action', 'boq.pdf_import_confirmed')->sole();
        $this->assertSame([$this->org->id, $this->pm->id, $project->id], [$log->organization_id, $log->user_id, $log->auditable_id]);
        $this->assertSame($project->getMorphClass(), $log->auditable_type);
        $this->assertEquals(['file' => 'estimate.pdf', 'pages' => 1, 'rows' => 10, 'printed_language' => 'en', 'pages_checked' => true, 'notes_ticked' => 4, 'total_matches' => true, 'total_reason' => null], $log->new_values);
    }

    public function test_the_preview_asks_for_the_language_the_pages_and_a_tick_on_every_note(): void
    {
        $project = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));

        $page = $this->get(route('boq.pdf.preview', $project))->assertOk()
            ->assertSee('Before you import')
            ->assertSee('Language the PDF is printed in')
            ->assertSee('I compared these rows with the pages of the PDF')
            ->assertSee('Open the PDF beside this page and compare each row with it.')
            ->assertSee('No quantity in the file. It was worked out as amount divided by rate.')
            ->assertSee('Quantity times rate does not match the amount in the file.')
            ->assertSee('No unit in the file. It was taken as nos.')
            ->getContent();

        // No language is chosen for the person, and each of the three can be.
        $this->assertSame(1, preg_match_all('/<select[^>]*name="printed_language"[^>]*required/', $page));
        $this->assertSame(0, preg_match_all('/<option[^>]*selected/', $page));
        $this->assertSame(['', 'en', 'mr', 'hi'], $this->found('/<option value="([a-z]*)"/', $page));

        // One box for each note, each needed and none ticked, and nothing that ticks them together.
        $this->assertSame(4, preg_match_all('/<input type="checkbox" name="ack_notes\[\]" value="[0-9a-f]{40}" form="boq-pdf-confirm"[^>]*required>/', $page));
        $this->assertSame(1, preg_match_all('/<input type="checkbox" name="pages_checked" value="1" form="boq-pdf-confirm"[^>]*required>/', $page));
        $this->assertSame(5, preg_match_all('/type="checkbox"/', $page));
        $this->assertSame(0, preg_match_all('/type="checkbox"[^>]*\schecked/', $page));
        $this->assertCount(4, array_unique($this->ticks($project)));

        // Every answer is sent with the confirm form and none with the discard form.
        $this->assertSame(1, preg_match_all('/<form id="boq-pdf-confirm" method="POST" action="'.preg_quote(route('boq.pdf.confirm', $project), '/').'"/', $page));
    }

    /** @return list<string> */
    private function found(string $pattern, string $page): array
    {
        preg_match_all($pattern, $page, $found);

        return $found[1];
    }

    public function test_a_total_that_does_not_match_needs_a_reason_and_the_history_keeps_it(): void
    {
        $project = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate(civilTotal: '1000000')));
        $token = $this->token();
        $preview = route('boq.pdf.preview', $project);

        // The difference is itself one of the lines to tick.
        $this->assertCount(5, $this->ticks($project));

        foreach ([null, '', '          ', 'too short', "nine\n\n\n\n\nchar", str_repeat('x', BoqPdfImport::REASON_MAX + 1), ['a reason in a list']] as $reason) {
            $this->confirm($project, ['total_reason' => $reason], $token)
                ->assertRedirect($preview)
                ->assertSessionHasErrors(['total_reason'])
                ->assertSessionDoesntHaveErrors(['printed_language', 'pages_checked', 'ack_notes']);
            $this->nothingWritten($project);
        }

        $this->confirm($project, [], $token)->assertSessionHasErrors(['total_reason' => 'The total written in the file does not match the lines. To import it as it is, write why in at least 10 characters.']);
        $this->nothingWritten($project);

        // The reason alone is not enough: the other answers are still needed.
        $this->post(route('boq.pdf.confirm', $project), ['token' => $token, 'total_reason' => 'The civil total in the file is an old figure.'])
            ->assertSessionHasErrors(['printed_language', 'pages_checked', 'ack_notes'])
            ->assertSessionDoesntHaveErrors(['total_reason']);
        $this->nothingWritten($project);

        $this->confirm($project, ['total_reason' => "  The civil total in the file\n is an old figure.  "], $token)
            ->assertRedirect(route('boq.excel.index', $project))
            ->assertSessionHas('success', '10 BOQ rows imported from the PDF.');

        $this->assertSame(10, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(
            ['Imported from a PDF file. The total written in the file does not match the lines. Reason given: The civil total in the file is an old figure.'],
            BoqRevision::where('project_id', $project->id)->pluck('reason')->unique()->values()->all(),
        );
        $this->assertSame(10, BoqRevision::where('project_id', $project->id)->where('source', 'pdf_import')->count());

        $log = AuditLog::where('action', 'boq.pdf_import_confirmed')->sole();
        $this->assertFalse($log->new_values['total_matches']);
        $this->assertSame('The civil total in the file is an old figure.', $log->new_values['total_reason']);
        $this->assertSame(5, $log->new_values['notes_ticked']);

        $this->get(route('project-setup.history', $project))->assertOk()->assertSee('Reason given: The civil total in the file is an old figure.');
    }

    /** The template layout with the descriptions in Marathi, as a contractor's own sheet often has them. @return list<list<string>> */
    private function marathiTemplate(): array
    {
        return [
            $this->boqHeading(),
            ['Substructure', 'M1', 'पायासाठी मऊ मुरुमात खोदकाम करणे', 'cum', '10', '350'],
            ['Substructure', 'M2', 'सिमेंट काँक्रीट M-20 टाकणे', 'cum', '4', '4000'],
            ['Superstructure', 'M3', 'विटांचे बांधकाम करणे', 'cum', '6', '5200'],
        ];
    }

    public function test_a_pdf_said_to_be_in_marathi_or_hindi_must_read_as_devanagari(): void
    {
        // An English estimate said to be Marathi or Hindi: what was read is not in that script, as with an old font.
        $english = $this->makeProject($this->org);
        $this->uploadAs($this->pm, $english, $this->pdf($this->estimate()));
        $token = $this->token();

        foreach (['mr', 'hi'] as $language) {
            $this->confirm($english, ['printed_language' => $language], $token)
                ->assertRedirect(route('boq.pdf.preview', $english))
                ->assertSessionHasErrors(['printed_language' => "This PDF's text does not read as Marathi or Hindi. Upload the Excel file of the estimate instead."])
                ->assertSessionDoesntHaveErrors(['pages_checked', 'ack_notes']);
            $this->nothingWritten($english);
        }

        // Typed Marathi comes out of the PDF as Devanagari, and is accepted as Marathi or as Hindi.
        foreach (['mr', 'hi'] as $language) {
            $marathi = $this->makeProject($this->org);
            $this->uploadAs($this->pm, $marathi, $this->pdf($this->marathiTemplate(), ['words' => false]))->assertRedirect(route('boq.pdf.preview', $marathi));
            $this->get(route('boq.pdf.preview', $marathi))->assertOk()->assertSee('विटांचे बांधकाम करणे');
            $this->confirm($marathi, ['printed_language' => $language])->assertSessionHas('success');

            $lines = $this->lines($marathi);
            $this->assertSame(['M1', 'M2', 'M3'], array_column($lines, 'code'));
            $this->assertSame('सिमेंट काँक्रीट M-20 टाकणे', $lines[1]['description']);
            $this->assertSame($language, AuditLog::where('action', 'boq.pdf_import_confirmed')->where('auditable_id', $marathi->id)->sole()->new_values['printed_language']);
        }

        // A sheet in both languages counts as Marathi from 30 Devanagari letters in 100, and not below that.
        // Vowel signs are not letters: the Marathi line has 33, the English one 77 or 78, so 33 in 110 or in 111.
        $this->assertSame(30, BoqPdfImport::DEVANAGARI_SHARE);
        $marathiLine = 'पायासाठी खोदकाम काँक्रीट विटांचे बांधकाम प्लास्टर आणि रंगकाम करणे';
        $englishLine = 'Plain cement concrete in foundation and plinth with cement, sand and metal as directed ';
        $this->assertSame(33, preg_match_all('/(?=\p{L})\p{Devanagari}/u', $marathiLine));
        $this->assertSame([77, 78], [preg_match_all('/\p{L}/u', $englishLine.'fully'), preg_match_all('/\p{L}/u', $englishLine.'wholly')]);

        foreach (['fully' => true, 'wholly' => false] as $lastWord => $accepted) {
            $mixed = $this->makeProject($this->org);
            $rows = [$this->boqHeading(), ['Substructure', 'B1', $marathiLine, 'cum', '10', '350'], ['Substructure', 'B2', $englishLine.$lastWord, 'cum', '4', '4000']];
            $this->uploadAs($this->pm, $mixed, $this->pdf($rows, ['words' => false]))->assertRedirect(route('boq.pdf.preview', $mixed));
            $this->get(route('boq.pdf.preview', $mixed))->assertOk()->assertSee($marathiLine)->assertSee($englishLine.$lastWord);
            $response = $this->confirm($mixed, ['printed_language' => 'mr']);

            if ($accepted) {
                $response->assertSessionHas('success');
                $this->assertSame(2, BoqItem::where('project_id', $mixed->id)->count());
            } else {
                $response->assertSessionHasErrors(['printed_language']);
                $this->nothingWritten($mixed);
            }
        }
    }

    /** What a scanner app writes over the picture of an estimate page: its own reading, line by line. */
    private const SCANNED_LINES = [
        'Sr. No.   Description of Items   Qty.   Rate   Unit   Amount in Rs.',
        '1   Excavation for foundation in soft murum   10   350   Rs/cu.m   3500',
        '2   Providing and fixing flush door   3   4000   Rs/door   12000',
        'Total Estimate Amount   15500',
    ];

    public function test_a_pdf_whose_text_a_scanner_laid_over_the_picture_is_refused(): void
    {
        $project = $this->makeProject($this->org);
        $scan = $this->handPdf($this->pagePicture().$this->textLines(self::SCANNED_LINES, 3));

        $this->uploadAs($this->pm, $project, $this->upload($scan, 'scanned-estimate.pdf'))->assertRedirect(route('boq.excel.index', $project));

        $this->assertNull($this->token());
        $this->assertTrue(session('boq_import.scanned'));
        $message = session('boq_import.errors.0.message');
        $this->assertSame('The text in this PDF was added by a scanner or uses an old Marathi or Hindi font, so its figures cannot be read as typed text. Please upload the Excel file of the estimate instead.', $message);
        $this->assertDoesNotMatchRegularExpression('/\b(AI|OCR|recogni)/i', $message);
        $this->get(route('boq.excel.index', $project))->assertOk()->assertSee('Nothing was imported')->assertSee('added by a scanner');
        $this->nothingWritten($project);

        // The same words typed on the page are taken as text. They fail later, and only for want of a table.
        $this->uploadAs($this->pm, $project, $this->upload($this->handPdf($this->pagePicture().$this->textLines(self::SCANNED_LINES)), 'typed.pdf'));
        $this->assertStringContainsString('Could not find the heading row in the PDF', session('boq_import.errors.0.message'));
        $this->assertFalse(session('boq_import.scanned'));

        // One such page among typed ones is enough: the whole file is refused, not read in part.
        $typed = $this->textLines(self::SCANNED_LINES);
        $read = app(BoqPdfReader::class)->read($this->upload($this->handPdf([$typed, $this->pagePicture().$this->textLines(self::SCANNED_LINES, 3), $typed]), 'mixed.pdf')->getRealPath());
        $this->assertFalse($read->ok());
        $this->assertTrue($read->scanned);
        $this->assertSame($message, $read->error);
        $this->assertSame([], $read->grid);

        // A scan with next to no text keeps the plain message it always had.
        $bare = app(BoqPdfReader::class)->read($this->upload($this->handPdf($this->pagePicture().$this->textLines(['Page 1'], 3)), 'bare.pdf')->getRealPath());
        $this->assertTrue($bare->scanned);
        $this->assertStringContainsString('This PDF has no text in it, so it is a scanned copy or a picture.', (string) $bare->error);
    }

    public function test_a_pdf_in_an_old_marathi_or_hindi_font_is_refused(): void
    {
        $project = $this->makeProject($this->org);
        // The text shows on the page, but the file holds other letters than the font draws.
        $old = $this->handPdf($this->textLines(self::SCANNED_LINES), ['font' => 'ABCDEF+Kruti#20Dev#20010', 'picture' => 'none']);

        $this->uploadAs($this->pm, $project, $this->upload($old, 'old-font.pdf'))->assertRedirect(route('boq.excel.index', $project));

        $this->assertNull($this->token());
        $this->assertTrue(session('boq_import.scanned'));
        $this->assertStringContainsString('uses an old Marathi or Hindi font', session('boq_import.errors.0.message'));
        $this->nothingWritten($project);

        // The same page in an ordinary font gets as far as looking for the table.
        $this->uploadAs($this->pm, $project, $this->upload($this->handPdf($this->textLines(self::SCANNED_LINES), ['picture' => 'none']), 'plain.pdf'));
        $this->assertStringContainsString('Could not find the heading row in the PDF', session('boq_import.errors.0.message'));
    }

    public function test_an_old_font_outside_the_table_or_only_listed_beside_a_page_does_not_stop_a_typed_table(): void
    {
        $rows = [
            self::ESTIMATE_HEADING,
            ['1', 'Excavation for foundation', '10', '350', 'cu.m', '3500'],
            ['2', 'Providing and fixing flush door', '3', '4000', 'door', '12000'],
        ];
        $grid = [
            1 => [1 => 'Sr. No.', 2 => 'Description of Items', 3 => 'Qty.', 4 => 'Rate', 5 => 'Unit', 6 => 'Amount in Rs.'],
            2 => [1 => '1', 2 => 'Excavation for foundation', 3 => '10', 4 => '350', 5 => 'cu.m', 6 => '3500'],
            3 => [1 => '2', 2 => 'Providing and fixing flush door', 3 => '3', 4 => '4000', 5 => 'door', 6 => '12000'],
        ];
        $old = ['fonts' => ['F2' => 'SHREE-DEV-0714'], 'picture' => 'none'];
        $read = fn (string|array $content, array $options = []) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($content, [...$old, ...$options]), 'estimate.pdf')->getRealPath());

        // The same typed table, with an old font only listed beside the page, or on the page tree above it.
        foreach ([false, true] as $inherited) {
            $plain = $read($this->tableCells($rows), ['inherited' => $inherited]);
            $this->assertTrue($plain->ok(), (string) $plain->error);
            $this->assertSame($grid, $plain->grid);
        }

        // A Marathi title in the old font above the table, and a whole cover page in it before the table.
        $title = $read($this->textLines(['Old font title line', 'Old font office name'], 0, 810, 'F2').$this->tableCells($rows));
        $this->assertTrue($title->ok(), (string) $title->error);
        $this->assertSame($grid, $title->grid);

        $cover = $read([$this->textLines(array_fill(0, 12, 'Old font cover page'), 0, 800, 'F2'), $this->tableCells($rows)]);
        $this->assertTrue($cover->ok(), (string) $cover->error);
        $this->assertSame($grid, $cover->grid);
        $this->assertSame(2, $cover->pages);

        // A signature in the old font below the rows is no part of the table: the file imports, and the signature is not a row.
        $signed = $read($this->tableCells($rows).$this->textLines(['Old font signature'], 0, 600, 'F2'));
        $this->assertTrue($signed->ok(), (string) $signed->error);
        $this->assertSame($grid, $signed->grid);

        // Text of the table drawn in the old font: one description, or the heading.
        foreach ([$this->tableCells($rows, 760, [2 => 'F2']), $this->tableCells($rows, 760, [0 => 'F2'])] as $content) {
            $refused = $read($content);
            $this->assertFalse($refused->ok());
            $this->assertTrue($refused->scanned);
            $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $refused->error);
            $this->assertSame([], $refused->grid);
        }
    }

    /** The items of a typed estimate, two to a page. */
    private const LETTERHEAD_ITEMS = [
        ['1', 'Excavation for foundation', '10', '350', 'cu.m', '3500'],
        ['2', 'Providing and fixing flush door', '3', '4000', 'door', '12000'],
        ['3', 'Brick masonry in cement mortar', '20', '5200', 'cu.m', '104000'],
        ['4', 'Internal plaster', '150', '260', 'sq.m', '39000'],
        ['5', 'Vitrified tile flooring', '80', '1100', 'sq.m', '88000'],
        ['6', 'Painting with acrylic emulsion', '300', '95', 'sq.m', '28500'],
    ];

    /**
     * A page as many contractors print one: a letterhead in an old Marathi font with a phone number on it, the typed
     * table under its heading, and a footer in the old font with a PIN code on it. Every number is invented.
     *
     * @param  list<list<string>>  $rows  The heading first.
     * @param  array<int, string>  $fonts  As tableCells() takes them.
     */
    private function letterheadPage(array $rows, string $more = '', array $fonts = []): string
    {
        return 'BT /F2 12 Tf 1 0 0 1 170 815 Tm (Old font company name) Tj /F2 9 Tf 1 0 0 1 150 800 Tm (Old font office address) Tj 1 0 0 1 480 800 Tm (9800000000) Tj ET'."\n"
            .$this->tableCells($rows, 760, $fonts)
            .$more
            .'BT /F2 8 Tf 1 0 0 1 40 40 Tm (Old font footer line) Tj 1 0 0 1 480 40 Tm (400000) Tj ET'."\n";
    }

    /** A signature block in the old font below the last row, with a phone number under the amount column. */
    private const OLD_FONT_SIGNATURE = 'BT /F2 9 Tf 1 0 0 1 400 640 Tm (For Old font firm) Tj 1 0 0 1 400 626 Tm (Old font proprietor) Tj 1 0 0 1 480 612 Tm (9800000001) Tj ET'."\n";

    /** @param  list<list<string>>  $items  @return array<int, array<int, ?string>> The grid of the heading and these rows, an empty cell as null. */
    private function estimateGrid(array $items): array
    {
        $grid = [1 => array_combine(range(1, 6), self::ESTIMATE_HEADING)];

        foreach ($items as $index => $item) {
            $grid[$index + 2] = array_combine(range(1, 6), array_map(fn (string $cell) => $cell === '' ? null : $cell, $item));
        }

        return $grid;
    }

    public function test_an_old_font_letterhead_and_footer_on_every_page_do_not_stop_a_typed_table_or_join_it(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['fonts' => ['F2' => 'ABCDEF+Kruti#20Dev#20010'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());

        foreach ([2, 3] as $count) {
            $chunks = array_chunk(array_slice(self::LETTERHEAD_ITEMS, 0, 2 * $count), 2);
            $pages = [];

            // The heading is printed again on every page, with the letterhead above it and the footer below the rows.
            foreach ($chunks as $number => $chunk) {
                $pages[] = $this->letterheadPage([self::ESTIMATE_HEADING, ...$chunk], $number === $count - 1 ? self::OLD_FONT_SIGNATURE : '');
            }

            $read2 = $read($pages);
            $this->assertTrue($read2->ok(), "{$count} pages: ".$read2->error);
            $this->assertSame($count, $read2->pages);
            $this->assertSame($this->estimateGrid(array_merge(...$chunks)), $read2->grid, "{$count} pages: only the rows of the table, in order.");

            // The same pages with one description of the table in the old font are refused, whichever page it is on.
            foreach (array_keys($chunks) as $oldOn) {
                $mixed = $pages;
                $mixed[$oldOn] = $this->letterheadPage([self::ESTIMATE_HEADING, ...$chunks[$oldOn]], $oldOn === $count - 1 ? self::OLD_FONT_SIGNATURE : '', [2 => 'F2']);
                $refused = $read($mixed);
                $this->assertFalse($refused->ok(), "{$count} pages, old font on page ".($oldOn + 1));
                $this->assertTrue($refused->scanned);
                $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $refused->error);
                $this->assertSame([], $refused->grid);
            }

            // And so are they when the heading, or a figure of a row, is drawn in it.
            $heading = $pages;
            $heading[0] = $this->letterheadPage([self::ESTIMATE_HEADING, ...$chunks[0]], '', [0 => 'F2']);
            $this->assertFalse($read($heading)->ok());

            $figure = $pages;
            $lastChunk = $chunks[$count - 1];
            $withoutQuantity = $lastChunk;
            $withoutQuantity[1][2] = '';
            $figure[$count - 1] = $this->letterheadPage([self::ESTIMATE_HEADING, ...$withoutQuantity], 'BT /F2 9 Tf 1 0 0 1 300 724 Tm ('.$lastChunk[1][2].') Tj ET'."\n".self::OLD_FONT_SIGNATURE);
            $this->assertFalse($read($figure)->ok());
        }
    }

    public function test_an_old_font_signature_block_below_the_rows_imports_without_becoming_a_row(): void
    {
        $read = fn (string $content) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($content, ['fonts' => ['F2' => 'SHREE-DEV-0714'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        $items = array_slice(self::LETTERHEAD_ITEMS, 0, 3);

        // Close under the rows or far below them, with or without a phone number under the amount column.
        foreach ([self::OLD_FONT_SIGNATURE, $this->textLines(['Old font firm', 'Old font proprietor'], 0, 690, 'F2'), $this->textLines(['Old font signature'], 0, 300, 'F2')] as $signature) {
            $signed = $read($this->tableCells([self::ESTIMATE_HEADING, ...$items]).$signature);
            $this->assertTrue($signed->ok(), (string) $signed->error);
            $this->assertSame($this->estimateGrid($items), $signed->grid);
        }

        // A row with no figure inside the table stays a row, as it always did, and the import reports it.
        $junk = $read($this->tableCells([self::ESTIMATE_HEADING, $items[0], ['', 'Note written between the rows', '', '', 'see drawing', ''], $items[1]]));
        $this->assertTrue($junk->ok());
        $this->assertSame([2 => 'Note written between the rows', 5 => 'see drawing'], array_filter($junk->grid[3]));
        $this->assertSame($items[1], array_values($junk->grid[4]));
    }

    public function test_the_letterhead_and_footer_of_every_page_stay_out_when_the_heading_is_printed_once(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['fonts' => ['F2' => 'ABCDEF+Kruti#20Dev#20010'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        $items = array_slice(self::LETTERHEAD_ITEMS, 0, 4);
        $withoutPhone = fn (string $page) => str_replace(' 1 0 0 1 480 800 Tm (9800000000) Tj', '', $page);

        // As Excel and Word print a long table on letterhead paper: the heading on the first page only.
        foreach (['with a phone number' => fn (string $page) => $page, 'without one' => $withoutPhone] as $case => $letterhead) {
            $estimate = $read([
                $letterhead($this->letterheadPage([self::ESTIMATE_HEADING, $items[0], $items[1]])),
                $letterhead($this->letterheadPage([$items[2], $items[3]])),
            ]);
            $this->assertTrue($estimate->ok(), "{$case}: ".$estimate->error);
            $this->assertSame($this->estimateGrid($items), $estimate->grid, "{$case}: the letterhead and the footer are no rows.");
        }

        // A footer in the old font with a PIN code under the amount, printed on each page: no row, and the file imports.
        $footer = 'BT /F2 8 Tf 1 0 0 1 120 40 Tm (Old font footer line) Tj 1 0 0 1 480 40 Tm (400000) Tj ET'."\n";
        $footed = $read([$this->tableCells([self::ESTIMATE_HEADING, $items[0], $items[1]]).$footer, $this->tableCells([$items[2], $items[3]]).$footer]);
        $this->assertTrue($footed->ok(), (string) $footed->error);
        $this->assertSame($this->estimateGrid($items), $footed->grid);

        // Printed once, it cannot be told from a lump-sum row of the table, so the old font in it refuses the file, as before.
        $once = $read([$this->tableCells([self::ESTIMATE_HEADING, $items[0], $items[1]]).$footer, $this->tableCells([$items[2], $items[3]])]);
        $this->assertFalse($once->ok());
        $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $once->error);
    }

    public function test_rows_of_the_table_in_an_old_font_are_refused_however_far_below_the_other_rows(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['fonts' => ['F2' => 'ABCDEF+Kruti#20Dev#20010'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        $items = self::LETTERHEAD_ITEMS;

        // Items 4 and 5 drawn in the old font after a gap of three rows at the foot of the page, as a new section would sit.
        $page = $this->tableCells([self::ESTIMATE_HEADING, $items[0], $items[1], $items[2]]).$this->tableCells([$items[3], $items[4]], 640, [0 => 'F2', 1 => 'F2']);

        foreach (['on one page' => [$page], 'going on to a page without the heading' => [$page, $this->tableCells([$items[5]])], 'going on under the heading printed again' => [$page, $this->tableCells([self::ESTIMATE_HEADING, $items[5]])]] as $case => $pages) {
            $refused = $read($pages);
            $this->assertFalse($refused->ok(), $case);
            $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $refused->error, $case);
        }
    }

    public function test_a_typed_table_keeps_every_row_whatever_the_gaps_between_its_parts(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['picture' => 'none']), 'estimate.pdf')->getRealPath());
        $item = fn (int $number, string $what, string $qty = '10', string $rate = '100') => [(string) $number, $what.' item '.$number, $qty, $rate, 'cu.m', (string) ((int) $qty * (int) $rate)];
        $civil = array_map(fn (int $number) => $item($number, 'Civil'), range(1, 8));
        $electrical = array_map(fn (int $number) => $item($number, 'Electrical', '2', '500'), range(9, 11));
        $section = ['', 'ELECTRICAL WORKS', '', '', '', ''];

        // A last section set off by four blank rows, on the last page or at the foot of a page whose table goes on under its heading.
        $page = $this->tableCells([self::ESTIMATE_HEADING, ...$civil]).$this->tableCells([$section, ...$electrical], 760 - 18 * 13);
        $plumbing = array_map(fn (int $number) => $item($number, 'Plumbing', '1', '700'), range(12, 14));

        // Without rules a title line joins the description of the row under it, as it always has.
        $read9 = $electrical;
        $read9[0][1] = 'ELECTRICAL WORKS '.$read9[0][1];

        $onePage = $read([$page]);
        $this->assertTrue($onePage->ok(), (string) $onePage->error);
        $this->assertSame($this->estimateGrid([...$civil, ...$read9]), $onePage->grid);

        $twoPages = $read([$page, $this->tableCells([self::ESTIMATE_HEADING, ...$plumbing])]);
        $this->assertTrue($twoPages->ok(), (string) $twoPages->error);
        $this->assertSame($this->estimateGrid([...$civil, ...$read9, ...$plumbing]), $twoPages->grid);

        // Part A going on at the top of page 2, then Part B under its own heading lower down that page.
        $partB = ['', 'PART B ELECTRICAL', '', '', '', ''];
        $parts = $read([
            $this->tableCells([self::ESTIMATE_HEADING, ...array_slice($civil, 0, 4)]),
            $this->tableCells(array_slice($civil, 4, 3), 780).$this->tableCells([$partB], 700).$this->tableCells([self::ESTIMATE_HEADING, ...array_slice($electrical, 0, 2)], 680),
        ]);
        $this->assertTrue($parts->ok(), (string) $parts->error);
        $this->assertSame($this->estimateGrid([...array_slice($civil, 0, 7), $partB, ...array_slice($electrical, 0, 2)]), $parts->grid);
    }

    public function test_descriptions_under_a_centred_heading_are_read_with_or_without_rules(): void
    {
        $rows = [
            ['Description of Items', 'Qty.', 'Rate', 'Unit', 'Amount in Rs.'],
            ['Excavation for foundation in soft murum including lift and lead up to 50 m', '10', '350', 'cu.m', '3500'],
            ['Providing and fixing flush door', '3', '4000', 'door', '12000'],
            ['Brick masonry in cement mortar 1:6', '20', '5200', 'cu.m', '104000'],
        ];
        $grid = [];

        foreach ($rows as $index => $row) {
            $grid[$index + 1] = array_combine(range(1, 5), $row);
        }

        // dompdf centres a heading cell, so the heading starts far to the right of the descriptions under it.
        foreach ([true, false] as $borders) {
            $read = app(BoqPdfReader::class)->read($this->pdf($rows, ['borders' => $borders, 'title' => false, 'words' => false])->getRealPath());
            $this->assertTrue($read->ok(), (string) $read->error);
            $this->assertSame($grid, $read->grid, $borders ? 'With rules.' : 'Without rules.');
        }
    }

    public function test_a_footer_with_a_page_number_or_two_figures_on_every_page_stays_out(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['fonts' => ['F2' => 'ABCDEF+Kruti#20Dev#20010'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        $letterhead = 'BT /F2 12 Tf 1 0 0 1 170 815 Tm (Old font company name) Tj /F2 9 Tf 1 0 0 1 150 800 Tm (Old font office address) Tj ET'."\n";
        $at = fn (string $text, float $x, float $y, string $font = 'F1') => 'BT /'.$font.' 9 Tf 1 0 0 1 '.$x.' '.$y.' Tm ('.$text.') Tj ET'."\n";
        $footer = $at('Old font footer address line', 150, 40, 'F2');

        // The footer in the old font, and a typed page number that differs from page to page. Every number is invented.
        $footers = [
            'a page number centred under the footer' => fn (int $page, int $of) => $footer.$at((string) $page, 297, 25),
            'a page number centred over the footer' => fn (int $page, int $of) => $at((string) $page, 297, 55).$footer,
            '"Page n of m" on the footer line beside a PIN code' => fn (int $page, int $of) => 'BT /F2 8 Tf 1 0 0 1 120 40 Tm (Old font footer line) Tj 1 0 0 1 480 40 Tm (400000) Tj /F1 8 Tf 1 0 0 1 300 40 Tm (Page '.$page.' of '.$of.') Tj ET'."\n",
            'a PIN code under the quantity and a phone number under the amount' => fn (int $page, int $of) => 'BT /F2 8 Tf 1 0 0 1 120 40 Tm (Old font footer line) Tj 1 0 0 1 300 40 Tm (411001) Tj 1 0 0 1 480 40 Tm (9800000002) Tj ET'."\n",
            'a page number on the address line of the letterhead' => fn (int $page, int $of) => $at((string) $page, 540, 800).$footer,
        ];

        foreach ($footers as $case => $foot) {
            foreach ([2, 3] as $count) {
                $chunks = array_chunk(array_slice(self::LETTERHEAD_ITEMS, 0, 2 * $count), 2);

                foreach (['the heading on every page' => true, 'the heading on the first page only' => false] as $how => $repeated) {
                    $pages = [];

                    foreach ($chunks as $number => $chunk) {
                        $pages[] = $letterhead.$this->tableCells($number === 0 || $repeated ? [self::ESTIMATE_HEADING, ...$chunk] : $chunk).$foot($number + 1, $count);
                    }

                    $estimate = $read($pages);
                    $this->assertTrue($estimate->ok(), "{$case}, {$count} pages, {$how}: ".$estimate->error);
                    $this->assertSame($this->estimateGrid(array_merge(...$chunks)), $estimate->grid, "{$case}, {$count} pages, {$how}: only the rows of the table.");
                }
            }
        }
    }

    public function test_rows_printed_alike_on_pages_laid_out_alike_stay_rows(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['picture' => 'none']), 'estimate.pdf')->getRealPath());
        [$excavation, $door, $brick] = self::LETTERHEAD_ITEMS;
        $addOn = ['', 'Add 6% supervision charges', '', '', '', '7170'];

        // Two blocks printed alike, one to a page, each with an add-on and no serial number after its last item, with or without a total under it.
        foreach (['with a total' => true, 'without one' => false] as $case => $withTotal) {
            $total = fn (string $name) => $withTotal ? [['', 'Total of Block '.$name, '', '', '', '126670']] : [];
            $block = fn (string $name) => $this->tableCells([self::ESTIMATE_HEADING, ['', 'Block '.$name, '', '', '', ''], $excavation, $door, $brick, $addOn, ...$total($name)]);
            $blocks = $read([$block('A'), $block('B')]);
            $expected = [];

            foreach (['A', 'B'] as $name) {
                // Without rules a title line joins the description of the row under it, as it always has.
                $first = $excavation;
                $first[1] = 'Block '.$name.' '.$first[1];
                array_push($expected, $first, $door, $brick, $addOn, ...$total($name));
            }

            $this->assertTrue($blocks->ok(), "{$case}: ".$blocks->error);
            $this->assertSame($this->estimateGrid($expected), $blocks->grid, "{$case}: every add-on stays a row.");
        }

        // A lump sum with no serial number first under the heading on two pages.
        $lumpSum = ['', 'Mobilisation lump sum', '', '', 'LS', '25000'];
        $page = fn (string $end) => [$lumpSum, $excavation, $door, ['', $end, '', '', '', '1']];
        $sums = $read([$this->tableCells([self::ESTIMATE_HEADING, ...$page('Block A end')]), $this->tableCells([self::ESTIMATE_HEADING, ...$page('Block B end')])]);
        $this->assertTrue($sums->ok(), (string) $sums->error);
        $this->assertSame($this->estimateGrid([...$page('Block A end'), ...$page('Block B end')]), $sums->grid);
    }

    public function test_a_head_and_its_lettered_parts_are_looked_at_together_across_a_page(): void
    {
        $read = fn (array $pages, string $font) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['fonts' => ['F2' => $font], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        [$excavation, $door, , $plaster] = self::LETTERHEAD_ITEMS;
        $project = $this->makeProject($this->org);

        foreach (['the heading printed again' => [self::ESTIMATE_HEADING], 'the heading on the first page only' => []] as $how => $heading) {
            $old = count($heading);
            $cases = [
                // A head with a unit and no figure in the old font at the foot of page 1, and its parts with their rates on
                // page 2. The import writes the head into the description of each part.
                'a head over parts on the next page' => [[
                    $this->tableCells([self::ESTIMATE_HEADING, $excavation, $door]).$this->tableCells([['3', 'Old font centering head', '', '', 'sqm', '']], 706, [0 => 'F2']),
                    $this->tableCells([...$heading, ['', 'a. Plinth area', '2', '750', '', '1500'], ['', 'b. Slab area', '1', '2000', '', '2000'], $plaster]),
                ], 'description', 'Plinth area - Old font centering head'],
                // Lettered rows with no figure in the old font at the top of page 2, under a priced row of page 1. The import
                // writes them into the details of that row.
                'a break-up on the next page' => [[
                    $this->tableCells([self::ESTIMATE_HEADING, $excavation, ['2', 'Providing concrete M-20', '', '4000', 'cu.m', '40000']]),
                    $this->tableCells([...$heading, ['', 'a. Old font column footing', '', '', '', ''], ['', 'b. Old font slabs', '', '', '', '']], 760, [$old => 'F2', $old + 1 => 'F2'])
                        .$this->tableCells([$plaster], 760 - 18 * ($old + 1) - 30),
                ], 'details', 'Old font column footing'],
            ];

            foreach ($cases as $case => [$pages, $field, $written]) {
                $refused = $read($pages, 'ABCDEF+Kruti#20Dev#20010');
                $this->assertFalse($refused->ok(), "{$case}, {$how}");
                $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $refused->error, "{$case}, {$how}");

                // Typed, the same pages import, and the text of those rows is in the lines.
                $typed = $read($pages, 'Helvetica');
                $this->assertTrue($typed->ok(), "{$case}, {$how}: ".$typed->error);
                [$lines] = app(BoqExcelService::class)->checkGrid($project, $typed->grid);
                $this->assertNotSame([], array_filter($lines, fn (array $line) => str_contains((string) $line[$field], $written)), "{$case}, {$how}: the import writes it into a line.");
            }
        }
    }

    public function test_a_break_up_under_the_last_priced_row_stays_with_it_on_the_next_page_or_further_down(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['picture' => 'none']), 'estimate.pdf')->getRealPath());
        [$excavation, $door] = self::LETTERHEAD_ITEMS;
        $concrete = ['3', 'Providing concrete M-20', '', '4000', 'cu.m', '40000'];
        $parts = [['', 'a. Column footing', '', '', '', ''], ['', 'b. Slabs', '', '', '', '']];
        $first = $this->tableCells([self::ESTIMATE_HEADING, $excavation, $door, $concrete]);

        // Lettered rows with no figure that explain the last priced row, with nothing priced after them.
        foreach ([
            'on the next page' => [$first, $this->tableCells($parts)],
            'on the next page, under the heading printed again' => [$first, $this->tableCells([self::ESTIMATE_HEADING, ...$parts])],
            'more than four lines below it' => [$first.$this->tableCells($parts, 760 - 18 * 4 - 120)],
        ] as $case => $pages) {
            $estimate = $read($pages);
            $this->assertTrue($estimate->ok(), "{$case}: ".$estimate->error);
            $this->assertSame($this->estimateGrid([$excavation, $door, $concrete, ...$parts]), $estimate->grid, "{$case}: the break-up stays.");
        }

        // A note below the last row that only starts with a capital is still no part of it.
        $noted = $read([$first.$this->tableCells([['', 'A note on the rates', '', '', '', '']], 760 - 18 * 4 - 120)]);
        $this->assertTrue($noted->ok(), (string) $noted->error);
        $this->assertSame($this->estimateGrid([$excavation, $door, $concrete]), $noted->grid);
    }

    public function test_a_letterhead_or_footer_numbered_only_from_page_two_on_stays_out(): void
    {
        $read = fn (array $pages) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($pages, ['fonts' => ['F2' => 'ABCDEF+Kruti#20Dev#20010'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        $at = fn (string $text, float $x, float $y, string $font = 'F1') => 'BT /'.$font.' 9 Tf 1 0 0 1 '.$x.' '.$y.' Tm ('.$text.') Tj ET'."\n";
        $items = array_slice(self::LETTERHEAD_ITEMS, 0, 4);

        // The first page carries no page number; the second has a bare "2" on the same line. Every number is invented.
        $cases = [
            'on the letterhead' => fn (int $page) => $at('Old font company name', 150, 815, 'F2').($page > 1 ? $at((string) $page, 540, 815) : ''),
            'on the footer' => fn (int $page) => $at('Old font footer address line', 150, 40, 'F2').($page > 1 ? $at((string) $page, 540, 40) : ''),
        ];

        foreach ($cases as $case => $furniture) {
            foreach (['the heading on the first page only' => false, 'the heading on every page' => true] as $how => $repeated) {
                $estimate = $read([
                    $furniture(1).$this->tableCells([self::ESTIMATE_HEADING, $items[0], $items[1]]),
                    $furniture(2).$this->tableCells($repeated ? [self::ESTIMATE_HEADING, $items[2], $items[3]] : [$items[2], $items[3]]),
                ]);

                $this->assertTrue($estimate->ok(), "{$case}, {$how}: ".$estimate->error);
                $this->assertSame($this->estimateGrid($items), $estimate->grid, "{$case}, {$how}: only the rows of the table.");
            }
        }
    }

    public function test_text_outside_the_columns_of_the_table_is_put_in_none_of_them(): void
    {
        $read = fn (string $page) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($page, ['picture' => 'none']), 'estimate.pdf')->getRealPath());
        $at = fn (string $text, float $x, float $y) => 'BT /F1 9 Tf 1 0 0 1 '.$x.' '.$y.' Tm ('.$text.') Tj ET'."\n";
        $columns = [160, 200, 380, 420, 460, 500];
        $rows = [self::ESTIMATE_HEADING, ...array_slice(self::LETTERHEAD_ITEMS, 0, 3)];
        $page = '';

        foreach ($rows as $index => $row) {
            foreach ($row as $column => $cell) {
                $page .= $at($cell, $columns[$column], 760 - 18 * $index);
            }
        }

        // A checker's marks in the left margin, far from the table, beside two of its rows.
        $page .= $at('Checked', 20, 760 - 18).$at('Sd 7', 20, 760 - 36);
        $estimate = $read($page);

        $this->assertTrue($estimate->ok(), (string) $estimate->error);
        $this->assertSame($this->estimateGrid(array_slice(self::LETTERHEAD_ITEMS, 0, 3)), $estimate->grid);
    }

    public function test_putting_the_table_together_takes_time_in_step_with_the_text_and_stops_at_its_deadline(): void
    {
        $piece = fn (string $text, float $x, float $y) => ['x' => $x, 'x1' => $x + PdfTable::width($text, 8), 'y' => $y, 'size' => 8.0, 'text' => $text];
        $pages = function (int $count, int $lines, callable $line) use ($piece) {
            $pages = [];

            for ($page = 0; $page < $count; $page++) {
                $pieces = array_map(fn (array $cell) => $piece($cell[1], $cell[0], 100000), [[20, 'Sr. No.'], [80, 'Description'], [400, 'Unit'], [450, 'Qty'], [500, 'Rate'], [560, 'Amount']]);

                for ($number = 1; $number <= $lines; $number++) {
                    array_push($pieces, ...$line($number, 100000 - 12 * $number));
                }

                $pages[] = ['pieces' => $pieces, 'h' => [], 'v' => []];
            }

            return $pages;
        };

        // As a crafted file could hold them: the same note on every line of fifty pages, and long runs of lettered rows.
        $inputs = [
            'the same line in the same place on every page' => $pages(50, 400, fn (int $number, float $y) => [$piece('do', 80, $y)]),
            'long runs of lettered rows' => $pages(5, 3000, fn (int $number, float $y) => $number === 3000
                ? [$piece('a. part', 80, $y), $piece('1', 450, $y), $piece('2', 500, $y)]
                : [$piece('a. part', 80, $y)]),
        ];

        foreach ($inputs as $case => $input) {
            $started = hrtime(true);
            $this->assertNotSame([], (new PdfTable($input))->grid());
            $this->assertLessThan(8.0, (hrtime(true) - $started) / 1e9, $case);
        }

        // A deadline that has passed stops the work, and the reader says so in its own words.
        try {
            (new PdfTable($inputs['long runs of lettered rows'], hrtime(true)))->grid();
            $this->fail('A deadline that has passed stops the table.');
        } catch (PdfTableTimedOut) {
        }

        $path = $this->upload($this->handPdf($this->tableCells([self::ESTIMATE_HEADING, ...self::LETTERHEAD_ITEMS]), ['picture' => 'none']), 'estimate.pdf')->getRealPath();
        $this->assertTrue(app(BoqPdfReader::class)->read($path)->ok());
        $late = (new BoqPdfReader(new PdfTextLayer, 0))->read($path);
        $this->assertFalse($late->ok());
        $this->assertStringContainsString('too complex to read in the time allowed', (string) $late->error);
    }

    public function test_each_piece_of_text_is_in_the_font_that_draws_it_after_q_and_q_and_with_quote_operators(): void
    {
        $rows = [
            self::ESTIMATE_HEADING,
            ['1', 'Excavation for foundation', '10', '350', 'cu.m', '3500'],
            ['2', 'Providing and fixing flush door', '3', '4000', 'door', '12000'],
        ];
        $grid = [
            1 => [1 => 'Sr. No.', 2 => 'Description of Items', 3 => 'Qty.', 4 => 'Rate', 5 => 'Unit', 6 => 'Amount in Rs.'],
            2 => [1 => '1', 2 => 'Excavation for foundation', 3 => '10', 4 => '350', 5 => 'cu.m', 6 => '3500'],
            3 => [1 => '2', 2 => 'Providing and fixing flush door', 3 => '3', 4 => '4000', 5 => 'door', 6 => '12000'],
        ];
        $read = fn (string $content) => app(BoqPdfReader::class)->read($this->upload($this->handPdf($content, ['fonts' => ['F2' => 'SHREE-DEV-0714'], 'picture' => 'none']), 'estimate.pdf')->getRealPath());
        $oldTitle = 'BT /F2 9 Tf 1 0 0 1 40 810 Tm (Old font title) Tj ET ';

        // A title in the old font, then the heading in /F1 inside q ... Q. Q gives the old font back, and the rows,
        // which select no font of their own, are drawn in it.
        $restored = $read($oldTitle.'q '.$this->tableCells([$rows[0]]).'Q '.$this->tableCells([$rows[1], $rows[2]], 742, ['', '']));
        $this->assertFalse($restored->ok());
        $this->assertTrue($restored->scanned);
        $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $restored->error);

        // The rows shown with ' and ", which move to the next line and draw in the font selected last.
        foreach (["(1 Excavation for foundation 10 350 cu.m 3500) ' (2 Providing and fixing flush door 3 4000 door 12000) '", '0 0 (1 Excavation for foundation 10 350 cu.m 3500) " 0 0 (2 Providing and fixing flush door 3 4000 door 12000) "'] as $shown) {
            $quoted = $read($this->tableCells([$rows[0]]).'BT /F2 9 Tf 18 TL 1 0 0 1 40 760 Tm '.$shown.' ET');
            $this->assertFalse($quoted->ok());
            $this->assertSame([], $quoted->grid);
        }

        // The other way round: the old font is selected inside q ... Q for a title, and Q gives /F1 back to a
        // table that selects no font of its own. That table is typed text.
        $typed = $read('BT /F1 9 Tf ET q '.$oldTitle.'Q '.$this->tableCells($rows, 760, ['', '', '']));
        $this->assertTrue($typed->ok(), (string) $typed->error);
        $this->assertSame($grid, $typed->grid);

        // Where pdfparser and the walk of the stream do not count the same text, no piece can be paired with its
        // font. On a page where an old font draws text, every piece is then taken as drawn in it. Text shown outside
        // BT ... ET is one that pdfparser leaves out.
        $unpaired = $read('BT /F2 9 Tf ET (Old font text) Tj BT /F1 9 Tf ET '.$this->tableCells($rows));
        $this->assertFalse($unpaired->ok());
        $this->assertStringContainsString('uses an old Marathi or Hindi font', (string) $unpaired->error);
        $this->assertTrue($read('BT /F1 9 Tf ET (Typed text) Tj '.$this->tableCells($rows))->ok());
    }

    public function test_the_reader_for_scans_is_named_only_where_it_is_installed(): void
    {
        $project = $this->makeProject($this->org);
        $scan = $this->handPdf($this->pagePicture().$this->textLines(self::SCANNED_LINES, 3));
        $this->assertFalse(BoqPdfReader::hasScanReader());

        $this->uploadAs($this->pm, $project, $this->upload($scan, 'scan.pdf'));
        $this->assertStringNotContainsString('Read it as a scan', session('boq_import.errors.0.message'));

        // The module that reads scans brings this route. Until it is there, nobody is sent to it.
        Route::get('/projects/{project}/boq-scans/create', fn () => '')->name('boq-scans.create');
        Route::getRoutes()->refreshNameLookups();

        // Nor while the readers are off for the company: the same test as its consent switch (AiSpend::readersOn).
        $this->actingAs($this->pm);
        $this->assertFalse(BoqPdfReader::hasScanReader());
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000', 'anthropic_api_key' => 'test-key-value']);
        $this->assertFalse(BoqPdfReader::hasScanReader(), 'No BOQ reader kind is registered.');
        config(['modules.boq_scan.read_kinds' => [FakeKind::class]]);
        $this->assertTrue(BoqPdfReader::hasScanReader());

        $this->uploadAs($this->pm, $project, $this->upload($scan, 'scan.pdf'));
        $this->assertSame('The text in this PDF was added by a scanner or uses an old Marathi or Hindi font, so its figures cannot be read as typed text. Read it as a scan, or upload the Excel file of the estimate instead.', session('boq_import.errors.0.message'));

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));
        $this->confirm($project, ['printed_language' => 'mr'])->assertSessionHasErrors(['printed_language' => "This PDF's text does not read as Marathi or Hindi. Read it as a scan."]);
        $this->nothingWritten($project);

        // The key taken away: the readers are off, and the scan is not offered again.
        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        $this->assertFalse(BoqPdfReader::hasScanReader());
        $this->uploadAs($this->pm, $project, $this->upload($scan, 'scan.pdf'));
        $this->assertStringNotContainsString('Read it as a scan', session('boq_import.errors.0.message'));
    }

    public function test_the_scan_module_can_put_its_offer_and_the_pages_of_the_pdf_on_these_screens(): void
    {
        $project = $this->makeProject($this->org);

        // Without that module there is no offer, and the person is told to open the PDF themselves.
        $this->actingAs($this->pm)->get(route('boq.excel.index', $project))->assertOk()->assertDontSee('scan-offer');
        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate(), [], 'tower-estimate.pdf'));
        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('Open the PDF beside this page and compare each row with it.')->assertDontSee('pdf-pages');

        $views = storage_path('framework/testing/boq-scan-views-'.uniqid());
        File::ensureDirectoryExists($views.'/modules/boq_scan');
        File::put($views.'/modules/boq_scan/_offer.blade.php', '<p id="scan-offer" data-project="{{ $project->id }}" data-scanned="{{ empty($import[\'scanned\']) ? \'no\' : \'yes\' }}"></p>');
        File::put($views.'/modules/boq_scan/_pdf_pages.blade.php', '<aside id="pdf-pages" data-project="{{ $project->id }}" data-file="{{ $preview[\'file\'] }}" data-pages="{{ $preview[\'pages\'] }}"></aside>');
        View::addLocation($views);

        try {
            // The pages are drawn beside the rows, and the line about opening the PDF by hand goes.
            $this->get(route('boq.pdf.preview', $project))->assertOk()
                ->assertSee('<aside id="pdf-pages" data-project="'.$project->id.'" data-file="tower-estimate.pdf" data-pages="1"></aside>', false)
                ->assertDontSee('Open the PDF beside this page')
                ->assertSee('I compared these rows with the pages of the PDF');

            // The offer is on the import page always, and knows when a PDF has just been refused as a scan.
            // The upload form and its file box keep the names that module's script looks for.
            $this->get(route('boq.excel.index', $project))->assertOk()
                ->assertSee('<p id="scan-offer" data-project="'.$project->id.'" data-scanned="no"></p>', false)
                ->assertSee('<form id="boq-import-form" method="POST" action="'.route('boq.excel.import', $project).'"', false)
                ->assertSee('<input id="file" name="file" type="file"', false);
            $this->uploadAs($this->pm, $project, $this->textlessPdf(1));
            $this->get(route('boq.excel.index', $project))->assertOk()->assertSee('Nothing was imported')->assertSee('<p id="scan-offer" data-project="'.$project->id.'" data-scanned="yes"></p>', false);
        } finally {
            File::deleteDirectory($views);
        }
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

        // A colleague's answers do not make the token theirs.
        $this->actingAs($colleague)->post(route('boq.pdf.confirm', $project), ['token' => $token, 'printed_language' => 'en', 'pages_checked' => '1'])->assertRedirect(route('boq.excel.index', $project));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        // The rightful owner can still go ahead.
        $this->actingAs($this->pm);
        $this->confirm($project, [], $token)->assertSessionHas('success');
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

    public function test_an_excel_estimate_goes_to_the_column_check_not_the_pdf_preview(): void
    {
        $project = $this->makeProject($this->org);

        // An estimate is not in the template's layout, so it is read by its own headings and shown first.
        $this->uploadAs($this->pm, $project, $this->workbook($this->estimate(), 'estimate.xlsx', 'Sheet1'))
            ->assertRedirect(route('boq.sheet.preview', $project));

        $this->assertNull($this->token());
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        // The elevator row has no unit, so the person names one for such lines before importing.
        $this->get(route('boq.sheet.preview', $project))->assertOk()->assertDontSee('Import these 8 lines');
        $this->post(route('boq.sheet.choose', $project), ['unit' => 'nos'])->assertRedirect(route('boq.sheet.preview', $project));
        $version = $this->get(route('boq.sheet.preview', $project))->assertOk()->assertSee('Import these 8 lines')->viewData('preview')['version'];
        $this->post(route('boq.sheet.confirm', $project), ['version' => $version])->assertSessionHas('success');

        // The two "Add 6%" rows are adjustments, shown and not imported. The flush door has no number of its own: it gets
        // one from its row, never the teakwood door's number above it.
        $this->assertSame(['1', '2', '3a', '3b', '4', 'R10', '5', '6'], BoqItem::where('project_id', $project->id)->orderBy('sort')->pluck('code')->all());
    }

    public function test_the_preview_reads_in_hindi(): void
    {
        $project = $this->makeProject($this->org);
        $this->pm->update(['locale' => 'hi']);

        $this->uploadAs($this->pm, $project, $this->pdf($this->estimate()));

        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('इम्पोर्ट से पहले PDF जाँचें')->assertSee('ये 10 पंक्तियाँ इम्पोर्ट करें')
            ->assertSee('इम्पोर्ट करने से पहले')
            ->assertSee('PDF किस भाषा में छपी है')
            ->assertSee('मैंने ये पंक्तियाँ PDF के पन्नों से मिलाकर देखी हैं');

        // A refusal is said in the person's language too.
        $this->post(route('boq.pdf.confirm', $project), ['token' => $this->token()])->assertRedirect(route('boq.pdf.preview', $project))
            ->assertSessionHasErrors(['printed_language' => 'वह भाषा चुनें जिसमें PDF छपी है।']);

        $this->pm->update(['locale' => 'mr']);
        $this->get(route('boq.pdf.preview', $project))->assertOk()->assertSee('इम्पोर्ट करण्यापूर्वी')->assertSee('मी या ओळी PDF च्या पानांशी ताडून पाहिल्या आहेत');
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
