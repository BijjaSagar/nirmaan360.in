<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Imports\BoqSheetImport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\ReadsApiDocs;

/**
 * The BOQ column check from the field app: upload a file, see how it is read, change a column, confirm, or set it
 * aside. Every file here is made up in the test, with invented text and figures.
 */
class BoqImportApiTest extends ApiTestCase
{
    use ReadsApiDocs;

    private Organization $org;

    private Project $project;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->tenant('Acme Infra');
        $this->pm = $this->member($this->org, 'pm');
        $this->project = $this->makeProject($this->org);
        $this->signIn($this->pm);
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        parent::tearDown();
    }

    /** A sheet whose fifth column, headed "Figure", is not taken for the rate: the person has to say so. */
    private function unpricedRows(): array
    {
        return [
            ['BILL OF QUANTITIES'],
            ['Name of work: Example warehouse'],
            [],
            ['Sl. No.', 'Particulars', 'UOM', 'Qty', 'Figure'],
            ['1.1', 'Clearing the site of shrubs', 'Sqm', 400, 12],
            ['1.2', 'Earth filling in plinth with approved soil', 'Cum', 60, 340],
        ];
    }

    /** A priced sheet with a line whose amount is not quantity times rate, a total and a tax line. */
    private function pricedRows(): array
    {
        return [
            ['Sl. No.', 'Particulars', 'UOM', 'Qty', 'Unit Rate', 'Total Amount'],
            ['1.1', 'Clearing the site of shrubs', 'Sqm', 400, 12, 4800],
            ['1.2', 'Earth filling in plinth with approved soil', 'Cum', 60, 340, 20000],
            [null, 'Total', null, null, null, 24800],
            [null, 'Add GST 18%', null, null, null, 4464],
        ];
    }

    /** @param  list<array<int, mixed>>  $rows */
    private function sheet(array $rows, string $name = 'boq.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Sheet1');

        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                if ($value !== null && $value !== '') {
                    $book->getActiveSheet()->setCellValue([$c + 1, $r + 1], $value);
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'api').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'api');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A PDF with a text layer, as a browser would print a BOQ table. */
    private function textPdf(): UploadedFile
    {
        $rows = '';

        foreach ([['1', 'Clearing the site of shrubs', 'sqm', '400', '12', '4800'], ['2', 'Earth filling in plinth', 'cum', '60', '340', '20400']] as $row) {
            $rows .= '<tr>'.implode('', array_map(fn (string $cell) => '<td>'.e($cell).'</td>', $row)).'</tr>';
        }

        $html = '<html><body style="font-family:DejaVu Sans;font-size:9pt"><table style="border-collapse:collapse" border="1">'
            .'<tr><th>Sr. No.</th><th>Description</th><th>Unit</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>'.$rows.'</table></body></html>';

        return $this->file(Pdf::loadHTML($html)->setPaper('a4')->output(), 'estimate.pdf');
    }

    /** A PDF whose pages hold a picture and no text, as a scan does. */
    private function scannedPdf(string $name = 'scan.pdf'): UploadedFile
    {
        $content = '0.5 g 50 50 495 742 re f';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>',
            '<< /Length '.strlen($content).' >>'."\nstream\n".$content."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer << /Root 1 0 R /Size '.(count($objects) + 1)." >>\nstartxref\n".$xref."\n%%EOF\n";

        return $this->file($pdf, $name);
    }

    private function url(Project $project, ?string $token = null, string $tail = ''): string
    {
        return self::API."/projects/{$project->id}/boq-imports".($token === null ? '' : '/'.$token).$tail;
    }

    private function upload(UploadedFile|array $file, ?Project $project = null): TestResponse
    {
        return $this->post($this->url($project ?? $this->project), ['file' => $file], ['Accept' => 'application/json']);
    }

    /** Upload a file and expect it staged. Returns the check as the app gets it. */
    private function staged(UploadedFile $file, ?Project $project = null): array
    {
        return $this->upload($file, $project)->assertCreated()->json('data');
    }

    private function show(string $token, ?Project $project = null): TestResponse
    {
        return $this->getJson($this->url($project ?? $this->project, $token));
    }

    private function choose(string $token, array $input, ?Project $project = null): TestResponse
    {
        return $this->patchJson($this->url($project ?? $this->project, $token), $input);
    }

    private function confirm(string $token, int $version, ?Project $project = null): TestResponse
    {
        return $this->postJson($this->url($project ?? $this->project, $token, '/confirm'), ['version' => $version]);
    }

    private function discard(string $token, ?Project $project = null): TestResponse
    {
        return $this->deleteJson($this->url($project ?? $this->project, $token));
    }

    /** Someone holding exactly these permissions. */
    private function holding(array $permissions): User
    {
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);
        $user = $this->member($this->org, 'engineer');
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function column(array $preview, string $letter): array
    {
        return collect($preview['columns'])->firstWhere('letter', $letter);
    }

    /** @return array<string, BoqItem> */
    private function lines(): array
    {
        return BoqItem::with('section')->where('project_id', $this->project->id)->orderBy('sort')->get()->keyBy('code')->all();
    }

    public function test_upload_a_column_change_and_confirm_make_the_boq_lines(): void
    {
        $preview = $this->staged($this->sheet($this->unpricedRows(), 'warehouse.xlsx'));

        // Nothing is written on upload.
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $preview['token']);
        $this->assertSame(['sheet', 'warehouse.xlsx', 'xlsx', 1, true, true, false], [$preview['kind'], $preview['file'], $preview['format'], $preview['version'], $preview['money'], $preview['found'], $preview['template']]);
        $this->assertSame([4, 1], [$preview['heading_row'], $preview['span']]);
        $this->assertSame(['serial', 'description', 'unit', 'qty', null], array_column($preview['columns'], 'field'));
        $this->assertSame(['A', 'Sl. No.', ['1.1', '1.2']], [$preview['columns'][0]['letter'], $preview['columns'][0]['heading'], $preview['columns'][0]['samples']]);
        $this->assertSame(['key' => null, 'label' => 'Leave out'], $preview['fields'][0]);
        $this->assertContains(['key' => 'rate', 'label' => 'Rate'], $preview['fields']);
        $this->assertSame(['1.1', 'sqm', '400.000', '0.00', 'create'], [$preview['lines'][0]['code'], $preview['lines'][0]['unit'], $preview['lines'][0]['qty'], $preview['lines'][0]['rate'], $preview['lines'][0]['action']]);
        $this->assertSame(['lines' => 2, 'rows' => 2, 'create' => 2, 'update' => 0], array_intersect_key($preview['counts'], array_flip(['lines', 'rows', 'create', 'update'])));
        $this->assertTrue($preview['can_confirm']);
        $this->assertTrue(now()->addMinutes(29)->lessThan($preview['expires_at']));

        // The fifth column holds the rates: read it as the rate.
        $changed = $this->choose($preview['token'], ['fields' => ['5' => 'rate']])->assertOk()->json('data');

        $this->assertSame(2, $changed['version']);
        $this->assertSame(['rate', 'chosen'], [$this->column($changed, 'E')['field'], $this->column($changed, 'E')['source']]);
        $this->assertSame(['12.00', '340.00'], array_column($changed['lines'], 'rate'));
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());

        // The check again, as it now stands.
        $this->assertSame($changed, $this->show($preview['token'])->assertOk()->json('data'));

        $this->confirm($preview['token'], 2)->assertOk()
            ->assertJsonPath('message', '2 BOQ rows imported.')
            ->assertJsonPath('data.created', 2)->assertJsonPath('data.updated', 0)->assertJsonPath('data.unchanged', 0)
            ->assertJsonPath('data.rows', 2)->assertJsonPath('data.repeated', false);

        $lines = $this->lines();
        $this->assertSame(['1.1', '1.2'], array_map('strval', array_keys($lines)));
        $this->assertSame(['Clearing the site of shrubs', 'sqm', '400.000', '12.00'], [$lines['1.1']->description, $lines['1.1']->unit, $lines['1.1']->qty, $lines['1.1']->rate]);
        $this->assertSame(['cum', '60.000', '340.00'], [$lines['1.2']->unit, $lines['1.2']->qty, $lines['1.2']->rate]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'boq.sheet_import_confirmed', 'auditable_id' => $this->project->id]);
        $this->assertSame('Imported from warehouse.xlsx after checking its columns', BoqRevision::where('project_id', $this->project->id)->value('reason'));

        // The token is spent: the check is gone.
        $this->show($preview['token'])->assertNotFound()->assertJsonPath('code', 'import_not_found');
    }

    public function test_a_file_in_our_template_shape_is_checked_too_and_a_line_already_there_is_updated(): void
    {
        $existing = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'code' => '1.1', 'description' => 'Excavation in all soils', 'unit' => 'cum', 'qty' => '100', 'rate' => '150']);
        $csv = "section,code,description,unit,qty,rate\nSubstructure,1.1,Excavation in all soils,cum,250.5,185.25\nSubstructure,1.2,PCC 1:4:8 below footings,cum,12,5200\n";

        $preview = $this->staged($this->file($csv, 'boq-template.csv'));

        $this->assertTrue($preview['template']);
        $this->assertSame('csv', $preview['format']);
        $this->assertSame(['update', 'create'], array_column($preview['lines'], 'action'));
        $this->assertSame([1, 1], [$preview['counts']['update'], $preview['counts']['create']]);
        $this->assertSame(['100.000', '150.00'], [$existing->fresh()->qty, $existing->fresh()->rate]);
        $this->assertSame(1, BoqItem::where('project_id', $this->project->id)->count());

        $this->confirm($preview['token'], $preview['version'])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 1);

        $this->assertSame(['250.500', '185.25', 'Substructure'], [$existing->fresh()->qty, $existing->fresh()->rate, $existing->fresh()->section->name]);
        $this->assertSame(2, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_every_route_needs_boq_manage(): void
    {
        $token = $this->staged($this->sheet($this->unpricedRows()))['token'];
        $this->signIn($this->member($this->org, 'engineer'));

        $this->upload($this->sheet($this->unpricedRows()))->assertForbidden();
        $this->show($token)->assertForbidden();
        $this->choose($token, ['unit' => 'cum'])->assertForbidden();
        $this->confirm($token, 1)->assertForbidden();
        $this->discard($token)->assertForbidden();

        $this->signIn($this->pm);
        $this->show($token)->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_a_plan_without_the_any_layout_import_is_refused(): void
    {
        $free = $this->tenant('Small Works', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);
        $this->signIn($owner);

        $this->upload($this->sheet($this->unpricedRows()), $project)->assertForbidden()
            ->assertJsonPath('message', 'Your company\'s plan does not include this module. Your company\'s owner can check the plan.');
        $this->show(Str::random(40), $project)->assertForbidden();
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->count());
    }

    public function test_another_company_and_another_person_cannot_reach_the_check(): void
    {
        $token = $this->staged($this->sheet($this->unpricedRows()))['token'];

        // Another company's owner: the project itself is not theirs.
        $this->signInElsewhere();
        $this->upload($this->sheet($this->unpricedRows()))->assertNotFound();
        $this->show($token)->assertNotFound();
        $this->choose($token, ['unit' => 'cum'])->assertNotFound();
        $this->confirm($token, 1)->assertNotFound();
        $this->discard($token)->assertNotFound();

        // Someone else in the same company, who may manage the BOQ: the token is not theirs.
        $this->signIn($this->member($this->org, 'owner'));
        $gone = $this->show($token)->assertNotFound()->assertJsonPath('code', 'import_not_found')->json();
        $this->assertSame($gone, $this->choose($token, ['unit' => 'cum'])->assertNotFound()->json());
        $this->assertSame($gone, $this->confirm($token, 1)->assertNotFound()->json());
        $this->assertSame($gone, $this->discard($token)->assertNotFound()->json());

        // The uploader, on another project of the company: the token belongs to the first.
        $this->signIn($this->pm);
        $other = $this->makeProject($this->org, ['name' => 'Other site']);
        $this->assertSame($gone, $this->show($token, $other)->assertNotFound()->json());
        $this->assertSame($gone, $this->confirm($token, 1, $other)->assertNotFound()->json());

        // A person limited to other projects does not reach this one.
        $limited = $this->member($this->org, 'pm');
        $limited->forceFill(['limited_to_projects' => true])->save();
        $limited->assignedProjects()->attach($other->id, ['organization_id' => $this->org->id]);
        $this->signIn($limited->fresh());
        $this->upload($this->sheet($this->unpricedRows()))->assertNotFound();

        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());
        $this->signIn($this->pm);
        $this->show($token)->assertOk();
    }

    public function test_an_unknown_or_expired_token_answers_404_alike(): void
    {
        $unknown = $this->show(Str::random(40))->assertNotFound()->json();

        $this->assertSame('import_not_found', $unknown['code']);
        $this->assertSame($unknown, $this->show('not-a-token')->assertNotFound()->json());
        $this->assertSame($unknown, $this->confirm(Str::random(40), 1)->assertNotFound()->json());
        $this->assertSame($unknown, $this->choose(Str::random(40), ['unit' => 'cum'])->assertNotFound()->json());
        $this->assertSame($unknown, $this->discard(Str::random(40))->assertNotFound()->json());

        $token = $this->staged($this->sheet($this->unpricedRows()))['token'];
        $this->travel(BoqSheetImport::TTL_MINUTES + 1)->minutes();

        $this->assertSame($unknown, $this->show($token)->assertNotFound()->json());
        $this->assertSame($unknown, $this->choose($token, ['unit' => 'cum'])->assertNotFound()->json());
        $this->assertSame($unknown, $this->confirm($token, 1)->assertNotFound()->json());
        $this->assertSame($unknown, $this->discard($token)->assertNotFound()->json());
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_a_change_keeps_the_check_another_thirty_minutes(): void
    {
        $preview = $this->staged($this->sheet($this->unpricedRows()));

        $this->travel(20)->minutes();
        $changed = $this->choose($preview['token'], ['unit' => 'cum'])->assertOk()->json('data');
        $this->assertTrue(now()->addMinutes(29)->lessThan($changed['expires_at']));

        $this->travel(20)->minutes();
        $this->show($preview['token'])->assertOk()->assertJsonPath('data.expires_at', $changed['expires_at']);
    }

    public function test_a_changed_version_answers_409_with_the_check_as_it_is_now(): void
    {
        $preview = $this->staged($this->sheet($this->unpricedRows()));
        $this->choose($preview['token'], ['fields' => ['5' => 'rate']])->assertOk();

        $this->confirm($preview['token'], 1)->assertConflict()
            ->assertJsonPath('code', 'version_changed')
            ->assertJsonPath('message', 'How the file is read was changed in another window. Check the lines again and confirm.')
            ->assertJsonPath('version', 2)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.lines.0.rate', '12.00');
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());

        $this->confirm($preview['token'], 2)->assertOk()->assertJsonPath('data.created', 2);
    }

    public function test_a_refused_import_answers_422_with_its_problems_and_writes_nothing(): void
    {
        // 50 cum of the slab is measured and approved, and the file cuts the line to 10.
        $slab = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'code' => '2.1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '100', 'rate' => '5000']);
        $this->approveEntry($this->draftEntry($this->project, $slab, $this->pm), $this->pm, $this->member($this->org, 'owner'));
        $rows = [
            ['Item code', 'Description', 'Unit', 'Qty', 'Rate'],
            ['2.1', 'RCC M25 in slab', 'cum', 10, 5000],
            ['2.2', 'Plaster 12 mm thick in cement mortar 1:4', 'sqm', 300, 240],
        ];
        $preview = $this->staged($this->sheet($rows));
        $problem = 'Quantity cannot be lower than the approved quantity (50.000) for item 2.1.';

        $this->assertFalse($preview['can_confirm']);
        $this->assertSame(1, $preview['counts']['errors']);
        $this->assertSame(['row' => 2, 'field' => 'qty', 'message' => $problem], $preview['errors'][0]);

        $this->confirm($preview['token'], $preview['version'])->assertUnprocessable()
            ->assertJsonPath('code', 'import_refused')
            ->assertJsonPath('message', 'This file cannot be imported as it is')
            ->assertJsonPath('errors.import.0', 'Row 2: '.$problem)
            ->assertJsonPath('data.errors.0.row', 2)
            ->assertJsonPath('data.version', 1);
        $this->assertSame(['100.000', 1], [$slab->fresh()->qty, BoqItem::where('project_id', $this->project->id)->count()]);
        $this->assertSame(0, AuditLog::where('action', 'boq.sheet_import_confirmed')->count());

        // Leave that row out: the rest can come in.
        $left = $this->choose($preview['token'], ['skip' => 2])->assertOk()->json('data');
        $this->assertTrue($left['can_confirm']);
        $this->assertSame([2], $left['excluded_rows']);
        $this->assertSame(['row' => 2, 'kind' => 'excluded', 'can_put_back' => true], array_intersect_key(collect($left['other_rows'])->firstWhere('row', 2), array_flip(['row', 'kind', 'can_put_back'])));

        // Put it back, and the problem is back.
        $back = $this->choose($preview['token'], ['keep' => 2])->assertOk()->json('data');
        $this->assertSame([], $back['excluded_rows']);
        $this->assertFalse($back['can_confirm']);

        // Left out again, the file imports without touching the slab.
        $version = $this->choose($preview['token'], ['skip' => 2])->assertOk()->json('data.version');
        $this->confirm($preview['token'], $version)->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame('100.000', $slab->fresh()->qty);
    }

    public function test_money_is_left_out_for_someone_who_may_not_see_it(): void
    {
        $shown = $this->staged($this->sheet($this->pricedRows()));

        $this->assertTrue($shown['money']);
        $this->assertSame(['12.00', '4800.00'], [$shown['lines'][0]['rate'], $shown['lines'][0]['amount']]);
        $this->assertSame(['row' => 3, 'computed' => '20400.00', 'amount' => '20000.00'], array_intersect_key($shown['mismatches'][0], array_flip(['row', 'computed', 'amount'])));
        $this->assertSame('25200.00', $shown['totals']['lines']);
        $this->assertSame('24800.00', $shown['totals']['file_subtotal']);
        $this->assertNotNull($shown['adjustments'][0]['effect']);
        $this->assertStringContainsString('₹', json_encode($shown['notes'], JSON_UNESCAPED_UNICODE));

        $site = $this->holding(['project.view', 'boq.view', 'boq.manage']);
        $this->signIn($site);
        $hidden = $this->staged($this->sheet($this->pricedRows()));

        $this->assertFalse($hidden['money']);
        $this->assertSame(['400.000', null, null, null], [$hidden['lines'][0]['qty'], $hidden['lines'][0]['rate'], $hidden['lines'][0]['amount'], $hidden['lines'][0]['file_rate']]);
        $this->assertNull($hidden['totals']);
        $this->assertNull($hidden['mismatches']);
        $this->assertSame(['label' => 'Add GST 18%', 'effect' => null, 'amount' => null, 'shows' => null], array_intersect_key($hidden['adjustments'][0], array_flip(['label', 'effect', 'amount', 'shows'])));
        // The lines' total against the sheet's is a note that quotes both figures: it is left out, with every other rupee figure.
        $this->assertStringNotContainsString('₹', json_encode($hidden, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('25200', json_encode($hidden));
        $this->assertSame($shown['counts']['lines'], $hidden['counts']['lines']);

        // The same after a change, and on the result.
        $changed = $this->choose($hidden['token'], ['unit' => 'cum'])->assertOk()->json('data');
        $this->assertNull($changed['totals']);
        $this->assertNull($changed['lines'][1]['rate']);
    }

    public function test_without_money_view_the_files_rates_are_written_as_the_website_writes_them(): void
    {
        // The website's column check takes the rates in the file from anyone who may manage the BOQ; so does the app.
        $site = $this->holding(['project.view', 'boq.view', 'boq.manage']);
        $this->signIn($site);
        $preview = $this->staged($this->sheet($this->pricedRows()));

        $this->confirm($preview['token'], $preview['version'])->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(['12.00', '340.00'], [$this->lines()['1.1']->rate, $this->lines()['1.2']->rate]);
    }

    public function test_a_file_that_cannot_be_read_is_refused_with_the_readers_words(): void
    {
        $this->upload($this->file(random_bytes(2000), 'boq.xlsx'))->assertUnprocessable()
            ->assertJsonPath('code', 'unreadable')
            ->assertJsonPath('message', 'This file is not a spreadsheet that can be read. Upload an Excel workbook (.xlsx or .xls), an OpenDocument sheet (.ods) or a CSV file.')
            ->assertJsonPath('errors.file.0', 'This file is not a spreadsheet that can be read. Upload an Excel workbook (.xlsx or .xls), an OpenDocument sheet (.ods) or a CSV file.');

        $this->upload($this->file("just one line\n", 'notes.csv'))->assertUnprocessable()->assertJsonPath('code', 'unreadable');

        // The website's rules on what is sent: one file, of a sheet's kind, within its size.
        $this->upload($this->file('hello', 'letter.docx'))->assertUnprocessable()->assertJsonValidationErrors('file')->assertJsonMissingPath('code');
        $this->upload(UploadedFile::fake()->create('big.xlsx', 6000))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload(UploadedFile::fake()->create('huge.pdf', 11000))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload([$this->sheet($this->unpricedRows()), $this->sheet($this->unpricedRows())])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post($this->url($this->project), [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_a_scanned_pdf_says_so_for_the_app_to_send_the_person_to_the_scan_reader(): void
    {
        $this->upload($this->scannedPdf())->assertUnprocessable()
            ->assertJsonPath('code', 'pdf_scanned')
            ->assertJsonPath('scan_reader', false)
            ->assertJsonPath('errors.file.0', 'This PDF has no text in it, so it is a scanned copy or a picture. Its figures cannot be read. Please upload the Excel file of the estimate instead.');

        // Told by its content, whatever it is called.
        $this->upload($this->scannedPdf('scan'))->assertUnprocessable()->assertJsonPath('code', 'pdf_scanned');
    }

    public function test_a_pdf_with_text_is_sent_to_the_website_for_now(): void
    {
        $this->upload($this->textPdf())->assertUnprocessable()
            ->assertJsonPath('code', 'pdf_on_website')
            ->assertJsonPath('errors.file.0', 'A BOQ in a PDF is imported on the website for now. Upload this PDF on the project\'s BOQ import page there, or upload the Excel or CSV file here.')
            ->assertJsonMissingPath('scan_reader');

        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_a_second_confirm_of_the_same_token_imports_nothing(): void
    {
        $preview = $this->staged($this->sheet($this->pricedRows()));

        $first = $this->confirm($preview['token'], $preview['version'])->assertOk()->json();
        $revisions = BoqRevision::where('project_id', $this->project->id)->count();

        $again = $this->confirm($preview['token'], $preview['version'])->assertOk()->json();

        $this->assertFalse($first['data']['repeated']);
        $this->assertTrue($again['data']['repeated']);
        $this->assertSame(array_diff_key($first['data'], ['repeated' => true]), array_diff_key($again['data'], ['repeated' => true]));
        $this->assertSame(2, BoqItem::where('project_id', $this->project->id)->count());
        $this->assertSame($revisions, BoqRevision::where('project_id', $this->project->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'boq.sheet_import_confirmed')->count());

        // Only for the person who imported it, on that project.
        $this->signIn($this->member($this->org, 'owner'));
        $this->confirm($preview['token'], $preview['version'])->assertNotFound();
    }

    public function test_a_confirm_while_another_is_running_writes_nothing(): void
    {
        $preview = $this->staged($this->sheet($this->pricedRows()));
        $running = Cache::lock('boq-import-app:confirming:'.$preview['token'], 60);
        $this->assertTrue($running->get());

        $this->confirm($preview['token'], $preview['version'])->assertConflict()->assertJsonPath('code', 'confirming');
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());

        $running->release();
        $this->confirm($preview['token'], $preview['version'])->assertOk()->assertJsonPath('data.repeated', false);
    }

    public function test_a_change_takes_the_websites_rules(): void
    {
        $token = $this->staged($this->sheet($this->unpricedRows()))['token'];

        $this->choose($token, ['span' => 4])->assertUnprocessable()->assertJsonValidationErrors('span');
        $this->choose($token, ['sheet' => 51])->assertUnprocessable()->assertJsonValidationErrors('sheet');
        $this->choose($token, ['row' => 0])->assertUnprocessable()->assertJsonValidationErrors('row');
        $this->choose($token, ['fields' => 'rate'])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->choose($token, ['fields' => ['5' => str_repeat('r', 21)]])->assertUnprocessable()->assertJsonValidationErrors('fields.5');
        $this->choose($token, ['unit' => str_repeat('m', 17)])->assertUnprocessable()->assertJsonValidationErrors('unit');
        $this->choose($token, ['skip' => 'two'])->assertUnprocessable()->assertJsonValidationErrors('skip');
        $this->confirm($token, 0)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($this->url($this->project, $token, '/confirm'))->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->show($token)->assertOk()->assertJsonPath('data.version', 1);
    }

    public function test_the_heading_row_and_the_unit_can_be_changed(): void
    {
        $preview = $this->staged($this->sheet($this->unpricedRows()));
        $this->assertContains(['row' => 4, 'text' => 'Sl. No. | Particulars | UOM | Qty | Figure'], $preview['heading_candidates']);

        $moved = $this->choose($preview['token'], ['row' => 2])->assertOk()->json('data');
        $this->assertSame(2, $moved['heading_row']);
        $this->assertFalse($moved['can_confirm']);

        $back = $this->choose($preview['token'], ['row_typed' => 4])->assertOk()->json('data');
        $this->assertSame(4, $back['heading_row']);
        $this->assertTrue($back['can_confirm']);

        $unit = $this->choose($preview['token'], ['unit' => 'cum'])->assertOk()->json('data');
        $this->assertSame('cum', $unit['unit']);
        $this->assertSame(4, $unit['version']);
    }

    public function test_a_file_set_aside_is_gone(): void
    {
        $token = $this->staged($this->sheet($this->unpricedRows()))['token'];

        $this->discard($token)->assertNoContent();
        $this->show($token)->assertNotFound()->assertJsonPath('code', 'import_not_found');
        $this->discard($token)->assertNotFound();
        $this->confirm($token, 1)->assertNotFound();
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_the_routes_keep_the_websites_limits_on_counters_of_their_own(): void
    {
        $limits = [
            'api.boq-imports.store' => 'throttle:20,1,boq-import-upload',
            'api.boq-imports.update' => 'throttle:60,1,boq-import-choose',
            'api.boq-imports.confirm' => 'throttle:20,1,boq-import-confirm',
            'api.boq-imports.destroy' => 'throttle:20,1,boq-import-discard',
        ];

        foreach (['api.boq-imports.store', 'api.boq-imports.show', 'api.boq-imports.update', 'api.boq-imports.confirm', 'api.boq-imports.destroy'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, $name);
            $this->assertContains('feature:reports', $middleware, $name);
            $this->assertContains('can:boq.manage', $middleware, $name);

            if (isset($limits[$name])) {
                $this->assertContains($limits[$name], $middleware, $name);
            }
        }
    }

    public function test_the_words_come_in_the_persons_language(): void
    {
        $this->pm->forceFill(['locale' => 'mr'])->save();
        $this->signIn($this->pm->fresh());

        $this->upload($this->textPdf())->assertUnprocessable()
            ->assertJsonPath('message', 'PDF मधील BOQ सध्या वेबसाइटवरच इम्पोर्ट होतो. ही PDF तिथे प्रकल्पाच्या BOQ इम्पोर्ट पानावर अपलोड करा, किंवा इथे Excel किंवा CSV फाइल अपलोड करा.');

        $preview = $this->staged($this->sheet($this->unpricedRows()));
        $this->assertSame('वगळा', $preview['fields'][0]['label']);
        $this->show(Str::random(40))->assertNotFound()->assertJsonPath('message', __('This preview has expired or belongs to someone else. Previews are kept for :minutes minutes. Please upload the file again.', ['minutes' => 30], 'mr'));
    }

    public function test_docs_name_every_key_the_routes_send(): void
    {
        $section = $this->apiDocSection('BOQ file import');
        $preview = $this->upload($this->sheet($this->pricedRows()))->assertCreated()->json();
        $this->assertDocumentsKeys($section, $preview, 'upload');

        $token = $preview['data']['token'];
        $this->assertDocumentsKeys($section, $this->show(Str::random(40))->json(), 'not found');
        $this->assertDocumentsKeys($section, $this->upload($this->scannedPdf())->json(), 'scanned');
        $this->assertDocumentsKeys($section, $this->confirm($token, $preview['data']['version'])->json(), 'confirm');

        foreach (['/boq-imports', '/boq-imports/{token}', '/boq-imports/{token}/confirm', '`version_changed`', '`import_refused`', '`confirming`', '`pdf_on_website`', '`unreadable`'] as $named) {
            $this->assertStringContainsString($named, $section);
        }
    }
}
