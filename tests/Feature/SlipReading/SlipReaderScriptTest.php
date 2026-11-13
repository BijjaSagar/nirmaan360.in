<?php

namespace Tests\Feature\SlipReading;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\SlipRead;
use App\Models\User;
use App\Services\MaterialControl\DeliveryChallanService;
use Dom\HTMLDocument;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The readers' page scripts, run in a pretend browser (reader-harness.mjs) with the server's own
 * answers, and what they send posted back to the server: the form and the server agree on what the
 * photo filled, a form the server sent back keeps its reading, and a reading taken back leaves
 * nothing of itself in the form.
 */
class SlipReaderScriptTest extends SlipReadingTestCase
{
    private User $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->member($this->organization, 'store');
    }

    /** @return array{script: string, xdata: string} The reader's script and its x-data, as the page draws them. */
    private function reader(TestResponse $page, string $factory): array
    {
        $document = HTMLDocument::createFromString((string) $page->getContent(), LIBXML_NOERROR);
        $script = null;

        foreach ($document->querySelectorAll('script:not([src])') as $candidate) {
            if (str_contains((string) $candidate->textContent, "function {$factory}(")) {
                $script = (string) $candidate->textContent;
            }
        }

        $holder = $document->querySelector('[x-data^="'.$factory.'("]');
        $this->assertNotNull($script, "The page carries {$factory}.");
        $this->assertNotNull($holder, "The page draws {$factory}.");

        return ['script' => $script, 'xdata' => (string) $holder->getAttribute('x-data')];
    }

    private function challanPage(): TestResponse
    {
        return $this->actingAs($this->store)->get(route('material_control.challans.create', $this->project))->assertOk();
    }

    /** The challan form's own list of materials, as its controller gives it. */
    private function materials(): array
    {
        return Material::with('baseUnit', 'conversions.unit')->where('organization_id', $this->organization->id)->orderBy('name')->get()
            ->map(fn (Material $material) => [
                'id' => $material->id,
                'label' => $material->label(),
                'base_unit' => $material->baseUnit->code,
                'units' => $material->conversions->map(fn ($conversion) => ['id' => $conversion->unit_id, 'code' => $conversion->unit->code])->values()->all(),
            ])->all();
    }

    /** A new challan form with the reader, its fields as given. */
    private function challanScenario(string $name, array $steps, array $responses, array $inputs = [], array $parent = [], ?TestResponse $page = null): array
    {
        return [
            'name' => $name,
            'factory' => 'slipChallanReader',
            ...$this->reader($page ?? $this->challanPage(), 'slipChallanReader'),
            'parent' => ['materials' => $this->materials(), ...$parent],
            'inputs' => ['qty' => '', 'challan_qty' => '', 'challan_date' => '2026-11-13', 'vehicle_no' => '', 'challan_no' => '', 'supplier_name' => '', 'client_uuid' => (string) Str::uuid(), ...$inputs],
            'files' => ['photo'],
            'responses' => $responses,
            'steps' => $steps,
        ];
    }

    /** What the server answers about a reading, to its person. */
    private function shown(SlipRead $slip, ?User $by = null): array
    {
        return $this->actingAs($by ?? $this->store)->getJson("/slip-reads/{$slip->id}")->assertOk()->json();
    }

    /** The answers for one reading that is ready at the first look. */
    private function answers(SlipRead $slip, ?User $by = null): array
    {
        return ['store' => [['status' => 202, 'body' => ['id' => $slip->id, 'status' => 'reading']]], 'show' => [['body' => $this->shown($slip, $by)]]];
    }

    /**
     * @param  list<array<string, mixed>>  $scenarios
     * @return array<string, list<array<string, mixed>>> Scenario name => its submits and snapshots.
     */
    private function browse(array $scenarios): array
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed, so the page scripts cannot be run here.');
        }

        $file = tempnam(sys_get_temp_dir(), 'n360-slip-');
        file_put_contents($file, json_encode($scenarios, JSON_THROW_ON_ERROR));

        try {
            $process = new Process([$node, __DIR__.'/reader-harness.mjs', $file]);
            $process->setTimeout(60)->run();
            $this->assertTrue($process->isSuccessful(), 'The harness ran: '.$process->getErrorOutput());
        } finally {
            @unlink($file);
        }

        $results = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->keyBy('name');

        foreach ($results as $name => $result) {
            $this->assertArrayNotHasKey('error', $result, "{$name}: ".($result['error'] ?? ''));
        }

        return $results->map(fn (array $result) => $result['events'])->all();
    }

    /** Posts what the page would send. */
    private function save(array $payload): TestResponse
    {
        return $this->actingAs($this->store)->from(route('material_control.challans.create', $this->project))
            ->post(route('material_control.challans.store', $this->project), array_filter($payload, fn ($value) => $value !== ''));
    }

    public function test_save_waits_for_each_filled_field_and_a_form_the_server_sent_back_keeps_its_reading(): void
    {
        $slip = $this->readyReading($this->store);

        $first = $this->browse([$this->challanScenario('first', [
            ['call', 'slipRead'], ['flush'],
            ['pick', 'material', (string) $this->cement->id], ['flush'],
            ['call', 'slipUseSupplier'], ['type', 'qty', '118'],
            ['submit'],
        ], $this->answers($slip))])['first'];

        // Nothing was confirmed: the page does not send the form, and says why.
        $this->assertTrue($first[0]['prevented']);
        $this->assertSame('Confirm each field filled from the photo, or change it, and tick each warning, before saving.', $first[0]['blocked']);
        $this->assertSame(['120.000', 'DC-0457', 'MH12AB1234'], [number_format((float) $first[0]['payload']['challan_qty'], 3, '.', ''), $first[0]['payload']['challan_no'], $first[0]['payload']['vehicle_no']]);
        $this->assertSame((string) $this->bag->id, $first[0]['payload']['unit_id']);

        // Sent anyway, from a page without the script, the server refuses it the same way.
        $sent = $first[0]['payload'];
        $this->save($sent)->assertSessionHasErrors(['challan_no' => 'Confirm this against the photo.', 'challan_qty' => 'Confirm this against the photo.', 'unit_id' => 'Confirm this against the photo.']);
        $this->assertSame(0, DeliveryChallan::count());

        // The page comes back with what was typed, and the reading with it.
        $back = $this->actingAs($this->store)->from(route('material_control.challans.create', $this->project))->followingRedirects()
            ->post(route('material_control.challans.store', $this->project), array_filter($sent, fn ($value) => $value !== ''))
            ->assertOk()->assertSee('Confirm this against the photo.');

        $again = $this->browse([$this->challanScenario('again', [
            ['snap'], ['submit'], ['confirmNeeded'], ['submit'],
        ], ['show' => [['body' => $this->shown($slip)]]], [
            'qty' => $sent['qty'], 'challan_qty' => $sent['challan_qty'], 'challan_date' => $sent['challan_date'], 'vehicle_no' => $sent['vehicle_no'], 'challan_no' => $sent['challan_no'], 'client_uuid' => $sent['client_uuid'],
        ], ['party' => $sent['party_id'], 'material' => $sent['material_id'], 'unit' => $sent['unit_id']], $back)])['again'];

        $this->assertSame('ready', $again[0]['state']);
        $this->assertSame($slip->id, $again[0]['payload']['read_id']);
        $this->assertEqualsCanonicalizing(['challan_no', 'challan_date', 'vehicle_no', 'challan_qty', 'unit_id'], $again[0]['filled']);
        $this->assertContains('party_id', $again[0]['payload']['read_confirmed'], 'The confirmation sent the first time stays.');
        $this->assertTrue($again[1]['prevented']);
        $this->assertFalse($again[2]['prevented']);

        $this->save($again[2]['payload'])->assertSessionHasNoErrors();
        $challan = DeliveryChallan::sole();
        $this->assertSame(['120.000', $this->bag->id], [$challan->challan_qty, $challan->unit_id]);
        $this->assertSame(SlipRead::USED, $slip->refresh()->status);
        $this->assertEqualsCanonicalizing(['party_id', 'material_id', 'challan_no', 'challan_date', 'vehicle_no', 'challan_qty', 'unit_id'], $slip->confirmed['fields']);
    }

    public function test_the_page_asks_about_exactly_what_the_server_counts_as_filled(): void
    {
        $weighbridge = $this->readyReading($this->store, $this->challanForm([
            'doc_kind' => 'weighbridge_slip', 'lines' => [],
            'gross_weight' => $this->cell('25,000'), 'tare_weight' => $this->cell('10,000'), 'net_weight' => $this->cell('14,000'), 'weight_unit' => $this->cell('kg'),
        ]));
        $oneLine = $this->readyReading($this->store);
        $twoLines = $this->readyReading($this->store, $this->challanForm(['lines' => [
            ['description' => $this->cell('OPC 53 Grade Cement'), 'qty' => $this->cell('120'), 'unit' => $this->cell('Bags')],
            ['description' => $this->cell('PPC Cement'), 'qty' => $this->cell('40'), 'unit' => $this->cell('Bags')],
        ]]));

        $results = $this->browse([
            // The weights do not add up, so neither a quantity nor a unit is filled; steel is kept in kg, the slip's own unit.
            $this->challanScenario('weighbridge', [
                ['call', 'slipRead'], ['flush'], ['pick', 'material', (string) $this->steel->id], ['flush'], ['call', 'slipUseSupplier'],
                ['type', 'challan_qty', '14000'], ['type', 'qty', '14000'], ['snap'], ['confirmNeeded'], ['submit'],
            ], $this->answers($weighbridge)),
            // The material was picked from the list before the photo was read, and it is one the reading suggests.
            $this->challanScenario('picked first', [
                ['pick', 'material', (string) $this->cement->id], ['flush'], ['call', 'slipRead'], ['flush'], ['call', 'slipUseSupplier'],
                ['type', 'qty', '118'], ['snap'], ['submit'], ['confirmNeeded'], ['submit'],
            ], $this->answers($oneLine)),
            // Two lines and none picked: the quantity typed is the person's own, though it is one of the lines'.
            $this->challanScenario('two lines', [
                ['call', 'slipRead'], ['flush'], ['pick', 'material', (string) $this->cement->id], ['flush'], ['call', 'slipUseSupplier'],
                ['type', 'challan_qty', '120'], ['type', 'qty', '118'], ['snap'], ['confirmNeeded'], ['submit'],
                ['call', 'slipUseLine', 1], ['snap'],
            ], $this->answers($twoLines)),
        ]);

        [$snap, $submit] = $results['weighbridge'];
        $this->assertSame([], array_values(array_intersect(['challan_qty', 'unit_id', 'material_id'], $snap['needs'])));
        $this->assertFalse($submit['prevented']);
        $this->save($submit['payload'])->assertSessionHasNoErrors();
        $this->assertSame(SlipRead::USED, $weighbridge->refresh()->status);

        [$snap, $early, $submit] = $results['picked first'];
        $this->assertContains('material_id', $snap['needs']);
        $this->assertTrue($early['prevented']);
        $this->assertFalse($submit['prevented']);
        $this->save($submit['payload'])->assertSessionHasNoErrors();
        $this->assertContains('material_id', $oneLine->refresh()->confirmed['fields']);

        [$snap, $submit, $picked] = $results['two lines'];
        $this->assertSame([], array_values(array_intersect(['challan_qty', 'unit_id'], $snap['needs'])));
        $this->assertSame('', $submit['payload']['read_line']);
        $this->assertSame(['40.000', 1, (string) $this->bag->id], [number_format((float) $picked['payload']['challan_qty'], 3, '.', ''), $picked['payload']['read_line'], $picked['payload']['unit_id']]);
        $this->assertContains('challan_qty', $picked['needs']);
        $this->save($submit['payload'])->assertSessionHasNoErrors();
        $this->assertSame(3, DeliveryChallan::count());
    }

    public function test_reading_again_or_dropping_the_reading_takes_its_values_back_out_of_the_form(): void
    {
        $weights = ['gross_weight' => $this->cell('18,450'), 'tare_weight' => $this->cell('6,200'), 'net_weight' => $this->cell('12,350'), 'weight_unit' => $this->cell('kg')];
        $slip = $this->readyReading($this->store, $this->challanForm($weights));
        $first = $this->shown($slip);
        $this->assertTrue($first['can_recheck']);

        // The stronger model reads the line's quantity and unit differently, so neither is offered any more.
        $this->willAnswer($this->reply($this->challanForm([...$weights, 'lines' => [['description' => $this->cell('OPC 53 Grade Cement'), 'qty' => $this->cell('125'), 'unit' => $this->cell('Kg')]]]), 'claude-sonnet-5-5'));
        $reading = $this->actingAs($this->store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(202)->json();
        $this->runPages($slip);
        $second = $this->shown($slip);
        $this->assertSame([null, null], [$second['lines'][0]['qty']['value'], $second['lines'][0]['unit']['unit_id']]);

        $results = $this->browse([
            $this->challanScenario('read again', [
                ['pick', 'material', (string) $this->cement->id], ['flush'], ['call', 'slipRead'], ['flush'], ['snap'],
                ['call', 'slipRecheck'], ['flush'], ['snap'], ['call', 'slipUseSupplier'], ['type', 'qty', '118'], ['confirmNeeded'], ['submit'],
            ], ['store' => [['status' => 202, 'body' => ['id' => $slip->id, 'status' => 'reading']]], 'show' => [['body' => $first], ['body' => $second]], 'recheck' => [['status' => 202, 'body' => $reading]]]),
            $this->challanScenario('dropped', [
                ['pick', 'material', (string) $this->cement->id], ['flush'], ['call', 'slipRead'], ['flush'], ['snap'],
                ['type', 'challan_no', 'DC-9999'], ['call', 'slipDiscard'], ['snap'],
            ], [...$this->answers($slip), 'show' => [['body' => $first]], 'discard' => [['body' => ['status' => 'discarded']]]], ['vehicle_no' => 'MH01ZZ0001']),
        ]);

        [$filled, $again, $submit] = $results['read again'];
        $this->assertSame(['120.000', (string) $this->bag->id], [$filled['payload']['challan_qty'], $filled['payload']['unit_id']]);
        // The first reading's quantity and unit are gone; what both readings agree on is filled again.
        $this->assertSame(['', ''], [$again['payload']['challan_qty'], $again['payload']['unit_id']]);
        $this->assertSame('DC-0457', $again['payload']['challan_no']);
        $this->assertSame([], array_values(array_intersect(['challan_qty', 'unit_id'], $again['filled'])));
        $this->save($submit['payload'])->assertSessionHasNoErrors();
        // Saved in cement's own unit, with no paper quantity: the person types those.
        $this->assertSame([null, $this->kg->id], [DeliveryChallan::sole()->challan_qty, DeliveryChallan::sole()->unit_id]);

        [$filled, $dropped] = $results['dropped'];
        $this->assertSame('MH12AB1234', $filled['payload']['vehicle_no']);
        // What the person typed stays theirs; everything else the reading put in goes back to what was there.
        $this->assertSame(['DC-9999', 'MH01ZZ0001', '2026-11-13', '', ''], [$dropped['payload']['challan_no'], $dropped['payload']['vehicle_no'], $dropped['payload']['challan_date'], $dropped['payload']['challan_qty'], $dropped['payload']['unit_id']]);
        $this->assertArrayNotHasKey('read_id', $dropped['payload']);
        $this->assertSame([], $dropped['filled']);
    }

    public function test_dropping_a_bill_reading_puts_back_the_figures_the_bill_form_had(): void
    {
        $this->withBillReader();
        $challan = app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '10', 'challan_date' => '2026-11-11',
        ], $this->owner);
        $challan->forceFill(['bill_no' => 'OLD/7'])->save();
        $slip = $this->readyBill($this->owner);
        $page = $this->actingAs($this->owner)->get(route('material_control.challans.show', $challan))->assertOk();

        $events = $this->browse([[
            'name' => 'bill',
            'factory' => 'slipBillReader',
            ...$this->reader($page, 'slipBillReader'),
            'inputs' => ['bill_no' => 'OLD/7', 'bill_date' => '', 'bill_amount' => ''],
            'files' => ['bill'],
            'responses' => [...$this->answers($slip, $this->owner), 'discard' => [['body' => ['status' => 'discarded']]]],
            'steps' => [['call', 'read'], ['flush'], ['snap'], ['call', 'discard'], ['snap']],
        ]])['bill'];

        $this->assertSame(['INV/0912', '2026-11-10', '70800.00'], [$events[0]['payload']['bill_no'], $events[0]['payload']['bill_date'], $events[0]['payload']['bill_amount']]);
        $this->assertSame(['OLD/7', '', ''], [$events[1]['payload']['bill_no'], $events[1]['payload']['bill_date'], $events[1]['payload']['bill_amount']]);
        $this->assertArrayNotHasKey('read_id', $events[1]['payload']);
    }

    public function test_the_marks_on_what_the_form_fills_are_shown_beside_it(): void
    {
        $unsure = $this->readyReading($this->store, $this->challanForm([
            'supplier_name' => $this->cell('Godavari Cement Traders', 'unclear'),
            'lines' => [['description' => $this->cell('OPC 53 Grade Cement'), 'qty' => $this->cell('२.५', 'unclear'), 'unit' => $this->cell('Bags', 'unclear')]],
            'gross_weight' => $this->cell('25,000'), 'tare_weight' => $this->cell('10,000'), 'net_weight' => $this->cell('14,000'), 'weight_unit' => $this->cell('kg'),
        ]));
        $body = $this->shown($unsure);
        $elsewhere = [...$body, 'warnings' => [['code' => 'possible_duplicate', 'params' => [], 'tick' => true], ['code' => 'same_delivery', 'params' => ['number' => 'DC-0012'], 'tick' => true]]];

        $results = $this->browse([
            $this->challanScenario('unsure', [['pick', 'material', (string) $this->cement->id], ['flush'], ['call', 'slipRead'], ['flush'], ['snap']], $this->answers($unsure)),
            $this->challanScenario('elsewhere', [['call', 'slipRead'], ['flush'], ['snap']], [...$this->answers($unsure), 'show' => [['body' => $elsewhere]]]),
        ]);

        $unsureSnap = $results['unsure'][0];
        $this->assertSame('2.500', $unsureSnap['payload']['challan_qty']);

        foreach (['challan_qty', 'unit_id', 'supplier_name'] as $field) {
            $this->assertContains('The reader was not sure of this value.', $unsureSnap['warnings'][$field], $field);
        }

        $this->assertContains('kg: Gross less tare is not the net weight on this slip, so no weight is filled.', $unsureSnap['other']);

        $this->assertSame([
            'A posted challan from this supplier has the same number, in a project you do not have access to.',
            'A posted challan of this project has the same vehicle, date and quantity: DC-0012.',
        ], $results['elsewhere'][0]['texts']);
    }
}
