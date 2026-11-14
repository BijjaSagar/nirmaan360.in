<?php

namespace Tests\Feature\SlipReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\MaterialUnitConversion;
use App\Models\Party;
use App\Models\Project;
use App\Models\SlipRead;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadFiles;
use App\Services\SlipReading\BillReadKind;
use App\Services\SlipReading\SlipChallanOffer;
use App\Services\SlipReading\SlipReadKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DocumentReading\PageReadingTestCase;

/**
 * A company that uses the readers, with the challan reader registered as its module registers it,
 * a project with a store, cement counted in kg and bought in bags, steel in kg and MT, and a
 * supplier with a GSTIN. The provider is faked; page jobs are run by hand.
 *
 * The clock stands on 13 November 2026, so the dates on the photos below are a fixed number of days old.
 * Every company, person, number and GSTIN here is invented.
 */
abstract class SlipReadingTestCase extends PageReadingTestCase
{
    /** An invented GSTIN that passes the check: state 27, PAN ABCDE1234F. */
    protected const GSTIN = '27ABCDE1234F1Z0';

    /** The invented company the bills below are made out to: state 27 too. */
    protected const BUYER_GSTIN = '27PQRST6789K1ZW';

    protected Project $project;

    protected Project $otherProject;

    protected MaterialUnit $kg;

    protected MaterialUnit $bag;

    protected MaterialUnit $mt;

    protected Material $cement;

    protected Material $steel;

    protected Party $vendor;

    /** @var list<mixed> The provider's next replies, in turn; the last one repeats. */
    protected array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-13 11:00:00');

        // The stand-ins of the reader tests are set aside; the challan reader is registered as its module will register it.
        config([
            'modules.reader_stand_ins.read_kinds' => [],
            'modules.slip_reading.read_kinds' => [SlipReadKind::class],
            'modules.slip_reading.reader_offers' => ['slip_challan' => SlipChallanOffer::class],
        ]);

        $this->setCredits($this->organization, '500');

        // One fake for the whole test, answering from $answers, so a test can change what the provider says next.
        $this->fakeReader([fn () => count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? $this->reply($this->challanForm()))]);

        $this->project = $this->makeProject($this->organization);
        $this->otherProject = $this->makeProject($this->organization, ['name' => 'Lakeview Villas']);

        $this->kg = MaterialUnit::create(['organization_id' => $this->organization->id, 'code' => 'kg']);
        $this->bag = MaterialUnit::create(['organization_id' => $this->organization->id, 'code' => 'bag']);
        $this->mt = MaterialUnit::create(['organization_id' => $this->organization->id, 'code' => 'MT']);

        $this->cement = Material::create(['organization_id' => $this->organization->id, 'code' => 'CEM-53', 'name' => 'OPC 53 cement', 'base_unit_id' => $this->kg->id]);
        $this->steel = Material::create(['organization_id' => $this->organization->id, 'code' => 'TMT-12', 'name' => 'TMT bar 12 mm', 'base_unit_id' => $this->kg->id]);
        MaterialUnitConversion::create(['organization_id' => $this->organization->id, 'material_id' => $this->cement->id, 'unit_id' => $this->bag->id, 'factor' => '50']);
        MaterialUnitConversion::create(['organization_id' => $this->organization->id, 'material_id' => $this->steel->id, 'unit_id' => $this->mt->id, 'factor' => '1000']);

        $this->vendor = Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => 'Godavari Cement Traders', 'gstin' => self::GSTIN, 'pan' => 'ABCDE1234F']);
    }

    /** @return array{raw: string, state: string} */
    protected function cell(string $raw, string $state = 'read'): array
    {
        return ['raw' => $raw, 'state' => $raw === '' ? 'absent' : $state];
    }

    /** What the reader returns for a delivery challan of 120 bags of cement, with overrides. */
    protected function challanForm(array $overrides = []): array
    {
        return [
            'doc_kind' => 'delivery_challan',
            'supplier_name' => $this->cell('Godavari Cement Traders'),
            'supplier_gstin' => $this->cell(self::GSTIN),
            'challan_no' => $this->cell('DC-0457'),
            'challan_date' => $this->cell('11/11/2026'),
            'vehicle_no' => $this->cell('MH 12 AB 1234'),
            'lines' => [['description' => $this->cell('OPC 53 Grade Cement'), 'qty' => $this->cell('120'), 'unit' => $this->cell('Bags')]],
            'gross_weight' => $this->cell(''),
            'tare_weight' => $this->cell(''),
            'net_weight' => $this->cell(''),
            'weight_unit' => $this->cell(''),
            ...$overrides,
        ];
    }

    /**
     * What the reader returns for a tax invoice of 200 bags of cement at 300 rupees, taxed within the
     * state at 18 (CGST 5,400 and SGST 5,400), with overrides.
     */
    protected function billForm(array $overrides = []): array
    {
        return [
            'doc_kind' => 'tax_invoice',
            'supplier_name' => $this->cell('Godavari Cement Traders'),
            'supplier_gstin' => $this->cell(self::GSTIN),
            'buyer_gstin' => $this->cell(self::BUYER_GSTIN),
            'bill_no' => $this->cell('INV/0912'),
            'bill_date' => $this->cell('10-11-2026'),
            'lines' => [$this->billLine('OPC 53 Grade Cement', '200', '300.00', '60,000.00')],
            'taxable_total' => $this->cell('60,000.00'),
            'cgst' => $this->cell('5,400.00'),
            'sgst' => $this->cell('5,400.00'),
            'igst' => $this->cell(''),
            'round_off' => $this->cell(''),
            'grand_total' => $this->cell('70,800.00'),
            'eway_bill_no' => $this->cell(''),
            ...$overrides,
        ];
    }

    /** @return array<string, array{raw: string, state: string}> */
    protected function billLine(string $description, string $qty, string $rate, string $amount, string $unit = 'Bags', string $hsn = '2523'): array
    {
        return [
            'description' => $this->cell($description), 'hsn' => $this->cell($hsn), 'qty' => $this->cell($qty),
            'unit' => $this->cell($unit), 'rate' => $this->cell($rate), 'amount' => $this->cell($amount),
        ];
    }

    /** The bill reader registered beside the challan reader, as the module will register it. */
    protected function withBillReader(): void
    {
        config(['modules.slip_reading.read_kinds' => [SlipReadKind::class, BillReadKind::class]]);
    }

    /** Sends a photo of a bill from a bill form, as the form's script does. */
    protected function readBill(User $by, ?Project $project = null, array $data = []): TestResponse
    {
        return $this->readPhoto($by, $project, ['kind' => 'bill', ...$data]);
    }

    /** Reads a bill photo through the routes and runs its page job with this reply. */
    protected function readyBill(User $by, ?array $form = null, ?Project $project = null): SlipRead
    {
        $this->willAnswer($this->reply($form ?? $this->billForm(), 'claude-sonnet-5-5'));
        $id = $this->readBill($by, $project)->assertStatus(202)->json('id');
        $slip = SlipRead::withoutGlobalScopes()->findOrFail($id);

        $this->runPages($slip);

        return $slip->refresh();
    }

    /** What the provider answers next, in turn; the last one repeats. */
    protected function willAnswer(mixed ...$replies): void
    {
        $this->answers = $replies;
    }

    /** A provider reply carrying this form. */
    protected function reply(array $form, string $model = 'claude-haiku-5-5', int $input = 3000, int $output = 600): array
    {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => json_encode($form, JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
        ];
    }

    protected function photo(int $width = 1200, int $height = 900): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('challan.jpg', $this->jpeg($width, $height));
    }

    /** Sends a photo from the challan form, as the form's script does. */
    protected function readPhoto(User $by, ?Project $project = null, array $data = []): TestResponse
    {
        return $this->actingAs($by)->post('/projects/'.($project ?? $this->project)->id.'/slip-reads', [
            'client_uuid' => (string) Str::uuid(),
            'kind' => 'challan',
            'image' => $this->photo(),
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    /** The same from the field app, with its token. */
    protected function readPhotoFromPhone(User $by, ?Project $project = null, array $data = []): TestResponse
    {
        Sanctum::actingAs($by);

        return $this->post('/api/v1/projects/'.($project ?? $this->project)->id.'/slip-reads', [
            'client_uuid' => (string) Str::uuid(),
            'kind' => 'challan',
            'image' => $this->photo(),
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    /** Reads a photo through the routes and runs its page job with this reply. */
    protected function readyReading(User $by, ?array $form = null, ?Project $project = null): SlipRead
    {
        $this->willAnswer($this->reply($form ?? $this->challanForm()));
        $id = $this->readPhoto($by, $project)->assertStatus(202)->json('id');
        $slip = SlipRead::withoutGlobalScopes()->findOrFail($id);

        $this->runPages($slip);

        return $slip->refresh();
    }

    /** Runs every page job of the reading's read that is queued, as a worker would. */
    protected function runPages(SlipRead $slip): void
    {
        DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $slip->document_read_id)->where('status', DocumentReadPage::QUEUED)
            ->pluck('id')->each(fn (int $id) => $this->readPage($id));
    }

    /** Limits a person to the given projects. */
    protected function limitTo(User $user, Project ...$projects): void
    {
        $user->forceFill(['limited_to_projects' => true])->save();

        foreach ($projects as $project) {
            DB::table('user_project_access')->insert(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function readOf(SlipRead $slip): DocumentRead
    {
        return DocumentRead::withoutGlobalScopes()->findOrFail($slip->document_read_id);
    }

    /**
     * A finished reading made straight in the tables, for the save paths: what the code made of a
     * photo, without going through the reader.
     */
    protected function storedReading(User $by, array $attributes = [], ?Project $project = null, string $kind = SlipRead::CHALLAN): SlipRead
    {
        $project ??= $this->project;
        $read = DocumentRead::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'kind' => SlipRead::READ_KINDS[$kind],
            'status' => DocumentRead::READY,
            'user_id' => $by->id,
            'pages_total' => 1,
            'consent_version' => AiDocumentConsent::VERSION,
        ]);

        $path = ReadFiles::page($read, 1, 'main');
        Storage::put($path, $this->jpeg(800, 600));
        DocumentReadPage::withoutGlobalScopes()->create([
            'document_read_id' => $read->id, 'page_no' => 1, 'pass' => 'main', 'model' => 'claude-haiku-5-5',
            'status' => DocumentReadPage::READ, 'image_path' => $path, 'image_sha256' => hash('sha256', (string) Storage::get($path)),
            'image_width' => 800, 'image_height' => 600,
        ]);

        $offered = fn (string $raw, ?string $value) => ['raw' => $raw, 'parsed' => $value, 'value' => $value, 'state' => 'read', 'warnings' => []];

        return SlipRead::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'document_read_id' => $read->id,
            'kind' => $kind,
            'status' => SlipRead::READY,
            'doc_kind' => $kind === SlipRead::BILL ? 'tax_invoice' : 'delivery_challan',
            'fields' => $kind === SlipRead::BILL ? [
                'bill_no' => $offered('INV/0912', 'INV/0912'),
                'bill_date' => $offered('10-11-2026', '2026-11-10'),
                'taxable_total' => $offered('60,000.00', '60000.00'),
                'gst_pct' => [...$offered('', '18'), 'from' => ['taxable_total', 'cgst', 'sgst', 'igst'], 'worked_out' => true],
                'supply_type' => [...$offered('', 'intra'), 'from' => ['cgst', 'sgst', 'igst'], 'worked_out' => true],
                'grand_total' => $offered('70,800.00', '70800.00'),
            ] : [
                'challan_no' => $offered('DC-0457', 'DC-0457'),
                'challan_date' => $offered('11/11/2026', '2026-11-11'),
                'vehicle_no' => $offered('MH 12 AB 1234', 'MH12AB1234'),
                'supplier_name' => $offered('Godavari Cement Traders', 'Godavari Cement Traders'),
                'supplier_gstin' => $offered(self::GSTIN, self::GSTIN),
                'net_weight' => $offered('', null),
                'weight_unit' => [...$offered('', null), 'known' => false, 'unit_id' => null],
            ],
            'lines' => $kind === SlipRead::BILL ? [] : [[
                'description' => $offered('OPC 53 Grade Cement', 'OPC 53 Grade Cement'),
                'qty' => [...$offered('120', '120.000'), 'had_comma' => false, 'script' => 'ascii'],
                'unit' => [...$offered('Bags', 'bag'), 'known' => true, 'unit_id' => $this->bag->id],
            ]],
            'warnings' => [],
            'suggestions' => ['party' => ['id' => $this->vendor->id, 'name' => $this->vendor->name, 'by' => 'gstin'], 'materials' => [['id' => $this->cement->id, 'name' => $this->cement->name, 'line' => 0]]],
            'image_sha256' => hash('sha256', 'photo '.Str::random(8)),
            'user_id' => $by->id,
            'client_uuid' => (string) Str::uuid(),
            ...$attributes,
        ])->refresh();
    }
}
