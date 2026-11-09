<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTenderBoqItem;
use App\Models\CrmTenderDocument;
use App\Services\Crm\TenderBoqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\BuildsTenant;
use Tests\TestCase;

class TenderBoqAndDocumentsTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
    }

    private function csv(string $content, string $name = 'boq.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_a_boq_line_is_added_priced_exactly_and_totalled(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.boq.store', $tender), ['section' => 'Earthwork', 'code' => '1.1', 'description' => 'Excavation', 'unit' => 'cum', 'qty' => '33.333', 'rate' => '0.10'])->assertSessionHasNoErrors();
        $this->post(route('crm.boq.store', $tender), ['section' => 'Earthwork', 'code' => '1.2', 'description' => 'Backfill', 'unit' => 'cum', 'qty' => '10', 'rate' => '0.20'])->assertSessionHasNoErrors();

        $lines = $tender->boqItems()->get();
        $this->assertSame([1, 2], $lines->pluck('sort')->all());
        $this->assertSame('33.333', $lines[0]->qty);
        $this->assertSame('3.33', $lines[0]->amount());
        $this->assertSame('5.33', app(TenderBoqService::class)->total($tender));
        $this->assertNotEmpty($lines[0]->uuid);
        $this->get(route('crm.tenders.show', $tender))->assertSee('Excavation')->assertSee('5.33');
    }

    public function test_a_boq_line_needs_its_code_unit_and_non_negative_numbers(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.boq.store', $tender), ['code' => '', 'description' => '', 'unit' => '', 'qty' => '-1', 'rate' => 'x'])->assertSessionHasErrors(['code', 'description', 'unit', 'qty', 'rate']);
        $this->assertSame(0, CrmTenderBoqItem::count());
    }

    public function test_a_line_can_be_edited_and_removed(): void
    {
        $user = $this->crmUser();
        $line = $this->makeBoqLine($this->makeTender($user->organization));

        $this->patch(route('crm.boq.update', $line), ['section' => 'Roads', 'code' => '9.9', 'description' => 'Bitumen', 'unit' => 'sqm', 'qty' => '5', 'rate' => '10'])->assertSessionHasNoErrors();
        $this->assertSame('Bitumen', $line->fresh()->description);
        $this->assertSame('Roads', $line->fresh()->section);

        $this->delete(route('crm.boq.destroy', $line));
        $this->assertNull(CrmTenderBoqItem::find($line->id));
    }

    public function test_a_csv_adds_lines_and_updates_existing_codes(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);
        $this->makeBoqLine($tender, ['code' => '1.1', 'qty' => '1.000', 'rate' => '1.00']);

        $csv = "section,code,description,unit,qty,rate\nEarthwork,1.1,Excavation in all soils,cum,250.500,185.25\n,2.1,\"RCC M25, slab\",cum,\"1,120.000\",\"7,450.00\"\n";
        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv($csv)])->assertSessionHasNoErrors();

        $lines = $tender->boqItems()->get();
        $this->assertCount(2, $lines);
        $this->assertSame('250.500', $lines[0]->qty);
        $this->assertSame('185.25', $lines[0]->rate);
        $this->assertSame('RCC M25, slab', $lines[1]->description);
        $this->assertSame('1120.000', $lines[1]->qty);
        $this->assertSame('7450.00', $lines[1]->rate);
        $this->assertSame([1, 2], $lines->pluck('sort')->all());
    }

    public function test_one_bad_row_imports_nothing(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $csv = "section,code,description,unit,qty,rate\n,1.1,Good,cum,1,1\n,1.2,Bad quantity,cum,lots,1\n,,No code,cum,1,1\n";
        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv($csv)])->assertSessionHasErrors('file');

        $this->assertSame(0, CrmTenderBoqItem::count());
    }

    public function test_a_file_without_the_right_columns_or_rows_is_refused(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv("code,description\n1,x\n")])->assertSessionHasErrors('file');
        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv("section,code,description,unit,qty,rate\n")])->assertSessionHasErrors('file');
        $this->post(route('crm.boq.import', $tender), ['file' => UploadedFile::fake()->create('boq.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post(route('crm.boq.import', $tender), [])->assertSessionHasErrors('file');
    }

    public function test_an_excel_file_is_imported(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([
            ['section', 'code', 'description', 'unit', 'qty', 'rate'],
            ['Superstructure', '2.1', 'RCC M25 in slab', 'cum', 120.5, 7450.25],
            ['Superstructure', '2.2', 'Shuttering', 'sqm', 800, 310],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'boq').'.xlsx';
        (new Xlsx($sheet))->save($path);

        $this->post(route('crm.boq.import', $tender), ['file' => new UploadedFile($path, 'boq.xlsx', null, null, true)])->assertSessionHasNoErrors();
        unlink($path);

        $lines = $tender->boqItems()->get();
        $this->assertCount(2, $lines);
        $this->assertSame('120.500', $lines[0]->qty);
        $this->assertSame('7450.25', $lines[0]->rate);
        $this->assertSame('Superstructure', $lines[1]->section);
    }

    public function test_a_file_with_too_many_rows_is_refused_without_importing_any(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);
        // A small limit stands in for the real one, so the test does not have to build thousands of rows.
        $this->app->instance(TenderBoqService::class, tap(new TenderBoqService, fn ($service) => $service->maxImportRows = 5));

        $rows = [['section', 'code', 'description', 'unit', 'qty', 'rate']];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = ['Sec', 'C'.$i, 'Item '.$i, 'nos', 1, 1];
        }

        $csv = implode("\n", array_map(fn ($row) => implode(',', $row), $rows));
        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv($csv)])->assertSessionHasErrors('file');

        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'boq').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        $this->post(route('crm.boq.import', $tender), ['file' => new UploadedFile($path, 'big.xlsx', null, null, true)])->assertSessionHasErrors('file');
        unlink($path);
        $this->assertSame(0, CrmTenderBoqItem::count());

        // Five rows are fine.
        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv(implode("\n", array_map(fn ($row) => implode(',', $row), array_slice($rows, 0, 6))))])->assertSessionHasNoErrors();
        $this->assertSame(5, CrmTenderBoqItem::count());
    }

    public function test_a_broken_spreadsheet_is_reported_not_crashed_on(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.boq.import', $tender), ['file' => $this->csv('this is not a zip file', 'boq.xlsx')])->assertSessionHasErrors('file');
    }

    public function test_a_document_is_stored_privately_and_downloaded_as_an_attachment(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create('NIT 114.pdf', 120, 'application/pdf'), 'kind' => 'nit', 'title' => 'Tender notice'])->assertSessionHasNoErrors();

        $document = CrmTenderDocument::firstOrFail();
        $this->assertSame('Tender notice', $document->title);
        $this->assertSame('nit', $document->kind);
        $this->assertSame('NIT 114.pdf', $document->original_name);
        $this->assertSame($user->id, $document->uploaded_by);
        $this->assertNull($document->project_id);
        $this->assertStringStartsWith('org-'.$user->organization_id.'/crm-tenders/'.$tender->id.'/', $document->path);
        Storage::assertExists($document->path);

        $response = $this->get(route('crm.documents.download', $document))->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('NIT 114.pdf', $response->headers->get('content-disposition'));
    }

    public function test_a_document_without_a_title_takes_the_file_name(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create('corrigendum-2.pdf', 10), 'kind' => 'corrigendum']);

        $this->assertSame('corrigendum-2', CrmTenderDocument::firstOrFail()->title);
    }

    public function test_dangerous_or_oversized_files_are_refused(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        foreach (['payload.php', 'run.exe', 'page.html', 'script.js', 'logo.svg', 'trick.pdf.php'] as $name) {
            $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create($name, 5), 'kind' => 'other'])->assertSessionHasErrors('file');
        }
        $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create('huge.pdf', 21 * 1024), 'kind' => 'other'])->assertSessionHasErrors('file');
        $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create('ok.pdf', 5), 'kind' => 'virus'])->assertSessionHasErrors('kind');

        $this->assertSame(0, CrmTenderDocument::count());
    }

    public function test_a_document_added_after_conversion_goes_straight_to_the_project(): void
    {
        $user = $this->crmUser();
        $project = $this->makeProject($user->organization);
        $tender = $this->makeTender($user->organization, ['stage' => 'won', 'closed_on' => today(), 'project_id' => $project->id]);

        $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create('work-order.pdf', 10), 'kind' => 'other']);

        $this->assertSame($project->id, CrmTenderDocument::firstOrFail()->project_id);
        $this->get(route('crm.project-file', $project))->assertSee('work-order');
    }

    public function test_a_document_can_be_removed_but_a_missing_file_is_a_clean_404(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);
        $document = $this->makeTenderDocument($tender);

        $this->get(route('crm.documents.download', $document))->assertNotFound();
        $this->delete(route('crm.documents.destroy', $document))->assertRedirect();
        $this->assertNull(CrmTenderDocument::find($document->id));
    }

    public function test_viewers_of_the_crm_can_download_but_not_upload_or_remove(): void
    {
        $organization = $this->tenant();
        $tender = $this->makeTender($organization);
        $document = $this->makeTenderDocument($tender, ['path' => 'org-'.$organization->id.'/doc.pdf']);
        Storage::put($document->path, 'pdf-bytes');
        $this->actingAs($this->member($organization, 'qs'));

        $this->get(route('crm.documents.download', $document))->assertOk();
        $this->post(route('crm.documents.store', $tender), ['file' => UploadedFile::fake()->create('x.pdf', 5), 'kind' => 'other'])->assertForbidden();
        $this->delete(route('crm.documents.destroy', $document))->assertForbidden();
        $this->post(route('crm.boq.store', $tender), ['code' => '1', 'description' => 'd', 'unit' => 'u', 'qty' => 1, 'rate' => 1])->assertForbidden();
    }
}
