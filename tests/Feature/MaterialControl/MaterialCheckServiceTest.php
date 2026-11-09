<?php

namespace Tests\Feature\MaterialControl;

use App\Exceptions\ImmutableRecordException;
use App\Models\MaterialExtraWork;
use App\Models\MaterialFlagReason;
use App\Models\MaterialIssue;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLedgerEntry;
use App\Notifications\ProcurementAlert;
use App\Services\MaterialControl\ConsumptionService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\ExtraWorkService;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\MaterialControl\ShortDeliveryService;
use App\Services\MaterialControl\StockCheckService;
use App\Services\MaterialControl\SupplierRecordService;
use App\Services\Procurement\MaterialIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Every worked example from section 8, and the section 13 formulas behind them. */
class MaterialCheckServiceTest extends TestCase
{
    use BuildsMaterialControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControl();
    }

    public function test_cement_over_use_105_against_100_flags_5_bags_and_asks_why(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '105');
        $this->useOn($slab, $cement, '105');

        $row = $this->rowFor($cement);

        $this->assertSame('100.000', $row['estimate']);
        $this->assertSame('105.000', $row['received']);
        $this->assertSame('105.000', $row['used']);
        // Section 13: variance 105 - 100 = +5 bags, wastage 5 / 100 = 5%.
        $this->assertSame('5.000', $row['variance']);
        $this->assertSame('5.00', $row['wastage_pct']);
        $this->assertSame('5.00', $this->wastagePercent());

        $flag = $this->flagOf($row, MaterialCheckService::OVER_USED);
        $this->assertSame('5.000', $flag['gap']);
        $this->assertTrue($flag['open']);
        $this->assertStringContainsString('5 bag', $flag['message']);
        $this->assertSame(1, app(MaterialCheckService::class)->check($this->project)['open_flags']);
    }

    public function test_a_written_reason_answers_a_gap_but_a_bigger_gap_asks_again(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '200', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '106');
        $this->useOn($slab, $cement, '105');

        $service = app(MaterialCheckService::class);
        $service->addReason($this->project, ['material_id' => $cement->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'Formwork leak on grid C'], $this->owner);

        $flag = $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED);
        $this->assertFalse($flag['open']);
        $this->assertSame('Formwork leak on grid C', $flag['reason']);
        $this->assertSame(0, $service->check($this->project)['open_flags']);

        $this->useOn($slab, $cement, '1');

        $flag = $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED);
        $this->assertSame('6.000', $flag['gap']);
        $this->assertTrue($flag['open'], 'A gap that grows needs a new reason.');
    }

    public function test_no_reason_can_be_written_when_there_is_no_gap(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '100');
        $this->useOn($slab, $cement, '100');

        $this->expectException(ValidationException::class);

        app(MaterialCheckService::class)->addReason($this->project, ['material_id' => $cement->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'Nothing to explain'], $this->owner);
    }

    public function test_cement_missing_50_received_45_used_shows_5_bags_unaccounted(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '50', '1');
        $this->workDone($slab, '45');
        $this->deliver($cement, '50');
        $this->useOn($slab, $cement, '45');

        // Before anyone counts, the book says 5 should be in stock.
        $row = $this->rowFor($cement);
        $this->assertSame('5.000', $row['should_be_in_stock']);
        $this->assertSame('5.000', $row['stock_on_site']);
        $this->assertSame('0.000', $row['unaccounted']);

        $this->countStock($cement, '0');

        // Section 13: 50 - 45 - 0 = 5 bags.
        $row = $this->rowFor($cement);
        $this->assertSame('0.000', $row['stock_on_site']);
        $this->assertSame('5.000', $row['unaccounted']);
        $this->assertSame('2026-11-06', $row['last_counted']);

        $flag = $this->flagOf($row, MaterialCheckService::UNACCOUNTED);
        $this->assertStringContainsString('5 bag unaccounted', $flag['message']);
        $this->assertTrue($flag['open']);

        // The missing 5 are gone from the book, so they cannot be issued to hide the loss.
        $this->assertSame('0.000', $this->balanceOf($cement));
        $this->expectException(ValidationException::class);
        $this->useOn($slab, $cement, '5');
    }

    public function test_steel_110_tons_against_100_shows_10_percent_wastage(): void
    {
        $steel = $this->bulk('STL', 'TMT steel', 'ton');
        $rcc = $this->boqFor($steel, '100', '1');
        $this->workDone($rcc, '100');
        $this->deliver($steel, '110');
        $this->useOn($rcc, $steel, '110');

        $row = $this->rowFor($steel);

        $this->assertSame('10.000', $row['variance']);
        $this->assertSame('10.00', $row['wastage_pct']);
        $this->assertSame('10.00', app(MaterialCheckService::class)->check($this->project)['wastage_pct']);
        $this->assertSame('10.000', $this->flagOf($row, MaterialCheckService::OVER_USED)['gap']);
    }

    public function test_extra_work_is_recorded_so_its_material_is_not_counted_as_wastage(): void
    {
        $steel = $this->bulk('STL', 'TMT steel', 'ton');
        $rcc = $this->boqFor($steel, '100', '1');
        $this->workDone($rcc, '100');
        $this->deliver($steel, '110');
        $this->useOn($rcc, $steel, '110');

        app(ExtraWorkService::class)->create($this->project, [
            'material_id' => $steel->id, 'qty' => '10', 'description' => 'Compound wall added by the client', 'work_date' => '2026-11-07',
        ], $this->owner);

        $row = $this->rowFor($steel);

        // The variance is still shown, but the 10 tons no longer count as waste.
        $this->assertSame('10.000', $row['variance']);
        $this->assertSame('10.000', $row['extra_work']);
        $this->assertSame('0.00', $row['wastage_pct']);
        $this->assertNull($this->flagOf($row, MaterialCheckService::OVER_USED));
    }

    public function test_extra_work_entered_with_the_use_counts_only_while_that_issue_stands(): void
    {
        $steel = $this->bulk('STL', 'TMT steel', 'ton');
        $rcc = $this->boqFor($steel, '100', '1');
        $this->workDone($rcc, '100');
        $this->deliver($steel, '110');
        $this->useOn($rcc, $steel, '100');
        $issue = $this->useOn($rcc, $steel, '10', ['extra_work' => 'Staircase railing posts', 'stage' => null]);

        $this->assertSame(1, MaterialExtraWork::where('material_issue_id', $issue->id)->count());
        $row = $this->rowFor($steel);
        $this->assertSame('0.00', $row['wastage_pct']);

        app(MaterialIssueService::class)->reverse($issue, 'Wrong entry', $this->owner);

        // With the issue gone, so is the extra work it carried.
        $this->assertSame('0.000', $this->rowFor($steel)['extra_work']);
    }

    public function test_copper_wire_going_faster_than_the_boq_raises_a_question(): void
    {
        $wire = $this->bulk('CU', 'Copper wire', 'bundle');
        // 3 bundles per flat, 8 flats in the BOQ, 4 flats wired so far.
        $wiring = $this->boqFor($wire, '8', '3');
        $this->workDone($wiring, '4');
        $this->deliver($wire, '20');
        $this->useOn($wiring, $wire, '14');

        $row = $this->rowFor($wire);

        $this->assertSame('12.000', $row['estimate']);
        $this->assertSame('2.000', $row['variance']);
        $this->assertSame('16.67', $row['wastage_pct']);
        $this->assertStringContainsString('Where is it going, and why?', $this->flagOf($row, MaterialCheckService::OVER_USED)['message']);
    }

    public function test_copper_wire_used_less_than_the_boq_is_also_a_gap_to_explain(): void
    {
        $wire = $this->bulk('CU', 'Copper wire', 'bundle');
        $wiring = $this->boqFor($wire, '8', '3');
        $this->workDone($wiring, '4');
        $this->deliver($wire, '10');
        $this->useOn($wiring, $wire, '10');

        $row = $this->rowFor($wire);

        // Offcuts reused: less than estimated is expected, but it still gets a written note.
        $this->assertSame('-2.000', $row['variance']);
        $this->assertSame('-16.67', $row['wastage_pct']);
        $flag = $this->flagOf($row, MaterialCheckService::UNDER_USED);
        $this->assertSame('-2.000', $flag['gap']);
        $this->assertTrue($flag['open']);
    }

    public function test_sand_short_by_1_brass_found_at_the_gate_counts_against_the_supplier(): void
    {
        $sand = $this->bulk('SND', 'River sand', 'brass');
        $supplier = $this->makeVendor('Patil Sand Supply');
        $pour = $this->boqFor($sand, '4', '1');
        $this->workDone($pour, '4');

        // The challan says 4 brass, but only 3 are on the truck.
        $challan = $this->deliver($sand, '3', $supplier, ['challan_qty' => '4']);

        $this->assertSame('3.000', $this->balanceOf($sand), 'Only what was counted enters stock.');

        // The extra brass arrives, the pour uses 4, and the numbers tie out.
        $this->deliver($sand, '1', $supplier);
        $this->useOn($pour, $sand, '4');

        $row = $this->rowFor($sand);
        $this->assertSame('4.000', $row['received']);
        $this->assertSame('4.000', $row['used']);
        $this->assertSame('0.000', $row['variance']);
        $this->assertSame('0.00', $row['wastage_pct']);
        $this->assertSame('1.000', $row['short_delivered']);
        $this->assertStringContainsString('1 brass short', $this->flagOf($row, MaterialCheckService::SHORT_DELIVERY)['message']);

        $this->assertSame(1, app(SupplierRecordService::class)->summary($supplier)['short_deliveries']);
        $this->assertSame(4, app(SupplierRecordService::class)->summary($supplier)['rating']);
        $this->assertSame(1, $challan->shortDeliveries()->count());
    }

    public function test_sand_shortage_found_during_the_pour_corrects_the_ledger(): void
    {
        $sand = $this->bulk('SND', 'River sand', 'brass');
        $supplier = $this->makeVendor('Patil Sand Supply');
        $pour = $this->boqFor($sand, '4', '1');
        $this->workDone($pour, '4');

        // Accepted at 4 on paper; the pour used 3 and the truck is found to have been a brass short.
        $challan = $this->deliver($sand, '4', $supplier);
        $this->useOn($pour, $sand, '3');

        $short = app(ShortDeliveryService::class)->report($challan, ['short_qty' => '1', 'found_on' => '2026-11-05', 'note' => 'Sand ran out mid-pour'], $this->owner);

        $this->assertTrue($short->ledger_posted);
        $this->assertSame('0.000', $this->balanceOf($sand));

        $this->deliver($sand, '1', $supplier);
        $this->useOn($pour, $sand, '1');

        $row = $this->rowFor($sand);
        $this->assertSame('4.000', $row['received']);
        $this->assertSame('4.000', $row['used']);
        $this->assertSame('0.000', $row['unaccounted']);
        $this->assertSame('0.00', $row['wastage_pct']);

        // A wrong report can be undone, and the stock comes back.
        $this->deliver($sand, '1', $supplier);
        app(ShortDeliveryService::class)->reverse($short, 'Reported in error', $this->owner);
        $this->assertSame('2.000', $this->balanceOf($sand));
        $this->assertSame(0, app(SupplierRecordService::class)->summary($supplier)['short_deliveries']);
    }

    public function test_a_shortage_cannot_be_more_than_the_challan(): void
    {
        $sand = $this->bulk('SND', 'River sand', 'brass');
        $challan = $this->deliver($sand, '4');

        $this->expectException(ValidationException::class);

        app(ShortDeliveryService::class)->report($challan, ['short_qty' => '5', 'found_on' => '2026-11-05'], $this->owner);
    }

    public function test_short_twice_marks_the_supplier_and_warns_when_chosen_again(): void
    {
        Notification::fake();
        $sand = $this->bulk('SND', 'River sand', 'brass');
        $supplier = $this->makeVendor('Patil Sand Supply');
        $records = app(SupplierRecordService::class);

        $this->deliver($sand, '3', $supplier, ['challan_qty' => '4']);
        $this->assertFalse($records->summary($supplier)['warn']);

        $this->deliver($sand, '3', $supplier, ['challan_qty' => '4']);

        $summary = $records->summary($supplier);
        $this->assertSame(2, $summary['short_deliveries']);
        $this->assertSame(3, $summary['rating']);
        $this->assertTrue($summary['warn']);
        $this->assertStringContainsString('short 2 times', $summary['warning']);

        $option = collect($records->options($this->organization->id))->firstWhere('party_id', $supplier->id);
        $this->assertTrue($option['warn']);

        Notification::assertSentTo($this->owner, ProcurementAlert::class);
    }

    public function test_a_manager_can_rate_and_mark_a_supplier_by_hand(): void
    {
        $supplier = $this->makeVendor('New Traders');
        $records = app(SupplierRecordService::class);

        $records->save($supplier, ['rating' => 2, 'marked' => true, 'marked_reason' => 'Billed for 40 bags, sent 38'], $this->owner);

        $summary = $records->summary($supplier);
        $this->assertSame(2, $summary['rating']);
        $this->assertTrue($summary['rating_by_hand']);
        $this->assertTrue($summary['warn']);
        $this->assertStringContainsString('Billed for 40 bags', $summary['warning']);

        $records->save($supplier, ['rating' => null, 'marked' => false], $this->owner);
        $this->assertFalse($records->summary($supplier)['warn']);
    }

    public function test_received_beyond_the_whole_boq_is_a_flag_but_not_early_stock(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');

        // 100 bags waiting for work that has not started: nothing wrong yet.
        $this->deliver($cement, '100');
        $this->assertNull($this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_RECEIVED));

        $this->deliver($cement, '20');
        $this->assertSame('20.000', $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_RECEIVED)['gap']);
    }

    public function test_use_with_no_boq_norm_is_flagged_and_has_no_wastage_figure(): void
    {
        $paint = $this->bulk('PNT', 'Primer', 'litre');
        $wall = $this->makeBoqItem($this->project);
        $this->deliver($paint, '30');
        $this->useOn($wall, $paint, '30');

        $row = $this->rowFor($paint);

        $this->assertNull($row['wastage_pct']);
        $this->assertStringContainsString('no BOQ estimate', $this->flagOf($row, MaterialCheckService::OVER_USED)['message']);
        $this->assertNull($this->wastagePercent());
    }

    public function test_project_wastage_is_valued_when_rates_are_known(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $steel = $this->bulk('STL', 'TMT steel', 'ton');
        $slab = $this->boqFor($cement, '100', '1');
        $rcc = $this->boqFor($steel, '100', '1');
        $this->workDone($slab, '100');
        $this->workDone($rcc, '100');
        $this->deliver($cement, '110');
        $this->deliver($steel, '102');
        $this->useOn($slab, $cement, '110');
        $this->useOn($rcc, $steel, '102');

        // Cement 10 over at Rs 400 and steel 2 over at Rs 60,000. Value: (4,000 + 1,20,000) / (40,000 + 60,00,000).
        $this->orderedAt($cement, '100', '40000.00');
        $this->orderedAt($steel, '100', '6000000.00');

        $this->assertSame('2.05', $this->wastagePercent());

        $money = $this->rowFor($steel, withMoney: true);
        $this->assertSame('60000.00', $money['rate']);
        $this->assertSame('120000.00', $money['wastage_value']);

        $this->assertArrayNotHasKey('rate', $this->rowFor($steel));
        $this->assertArrayNotHasKey('wastage_value', $this->rowFor($steel));
    }

    public function test_a_challan_posts_to_the_stock_ledger_and_a_repeat_adds_nothing(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $uuid = (string) Str::uuid();

        $challan = $this->deliver($cement, '50', null, ['client_uuid' => $uuid]);
        $repeat = app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $challan->party_id, 'material_id' => $cement->id, 'qty' => '50', 'challan_date' => '2026-11-03', 'client_uuid' => $uuid,
        ], $this->owner);

        $this->assertSame($challan->id, $repeat->id);
        $this->assertFalse($repeat->wasRecentlyCreated);
        $this->assertSame('50.000', $this->balanceOf($cement));

        $entry = StockLedgerEntry::where('source_id', $challan->id)->where('source_type', $challan->getMorphClass())->sole();
        $this->assertSame('grn', $entry->movement_type);
        $this->assertSame('50.000', $entry->qty);
        $this->assertNotNull($challan->challan_no ?? $challan->number);
        $this->assertTrue($challan->billPending());
        $this->assertSame('MH12AB1234', $challan->vehicle_no);
    }

    public function test_a_reversed_challan_takes_its_stock_back_and_cannot_be_reversed_twice(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $challan = $this->deliver($cement, '50');
        $service = app(DeliveryChallanService::class);

        $service->reverse($challan, 'Duplicate entry', $this->owner);

        $this->assertSame('0.000', $this->balanceOf($cement));
        $this->assertSame('0.000', $this->rowFor($cement)['received']);

        $this->expectException(ValidationException::class);
        $service->reverse($challan->fresh(), 'Again', $this->owner);
    }

    public function test_a_used_challan_cannot_be_reversed(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->makeBoqItem($this->project);
        $challan = $this->deliver($cement, '50');
        $this->useOn($slab, $cement, '50');

        $this->expectException(ValidationException::class);

        app(DeliveryChallanService::class)->reverse($challan, 'Duplicate entry', $this->owner);
    }

    public function test_attaching_a_bill_ends_bill_pending(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $challan = $this->deliver($cement, '50');
        $this->assertTrue($challan->billPending());

        $attached = app(DeliveryChallanService::class)->attachBill($challan, ['bill_no' => 'INV-77', 'bill_amount' => '20000.00'], $this->owner);

        $this->assertFalse($attached->billPending());
        $this->assertSame('20000.00', $attached->bill_amount);
        $this->assertSame($this->owner->id, $attached->bill_attached_by);

        // The stock side stays locked even though the bill could be added.
        $this->expectException(ImmutableRecordException::class);
        $attached->update(['qty' => '60']);
    }

    public function test_consumption_posts_a_material_issue_against_the_boq_item_and_stage(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->makeBoqItem($this->project);
        $this->deliver($cement, '50');
        $uuid = (string) Str::uuid();

        $issue = $this->useOn($slab, $cement, '20', ['stage' => 'Slab 2', 'client_uuid' => $uuid]);
        $repeat = app(ConsumptionService::class)->create($this->project, ['material_id' => $cement->id, 'qty' => '20', 'boq_item_id' => $slab->id, 'used_on' => '2026-11-05', 'client_uuid' => $uuid], $this->owner);

        $this->assertInstanceOf(MaterialIssue::class, $issue);
        $this->assertSame($issue->id, $repeat->id);
        $this->assertSame('30.000', $this->balanceOf($cement));

        $line = $issue->items()->sole();
        $this->assertSame($slab->id, $line->boq_item_id);
        $this->assertSame('Slab 2', $line->activity);
    }

    public function test_consumption_cannot_exceed_stock(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->makeBoqItem($this->project);
        $this->deliver($cement, '10');

        $this->expectException(ValidationException::class);

        $this->useOn($slab, $cement, '11');
    }

    public function test_a_physical_count_posts_an_adjustment_that_can_be_reversed(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $this->deliver($cement, '50');

        $check = $this->countStock($cement, '46');

        $this->assertSame('-4.000', $check->variance_qty);
        $this->assertSame('46.000', $this->balanceOf($cement));
        $entry = StockLedgerEntry::where('source_type', $check->getMorphClass())->where('source_id', $check->id)->sole();
        $this->assertSame('adjustment', $entry->movement_type);
        $this->assertSame('4.000', $this->rowFor($cement)['unaccounted']);

        app(StockCheckService::class)->reverse($check, 'Counted the wrong stack', $this->owner);

        $this->assertSame('50.000', $this->balanceOf($cement));
        $this->assertSame('0.000', $this->rowFor($cement)['unaccounted']);
    }

    public function test_a_count_that_matches_the_book_posts_no_movement(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $this->deliver($cement, '50');

        $check = $this->countStock($cement, '50');

        $this->assertSame('0.000', $check->variance_qty);
        $this->assertSame(0, StockLedgerEntry::where('source_type', $check->getMorphClass())->where('source_id', $check->id)->count());
    }

    public function test_more_found_on_site_than_recorded_is_flagged_as_a_missing_challan(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $this->deliver($cement, '10');

        $this->countStock($cement, '12');

        $row = $this->rowFor($cement);
        $this->assertSame('-2.000', $row['unaccounted']);
        $this->assertStringContainsString('Is a challan missing?', $this->flagOf($row, MaterialCheckService::UNACCOUNTED)['message']);
    }

    public function test_reasons_are_append_only_history(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '106');
        $this->useOn($slab, $cement, '105');
        $service = app(MaterialCheckService::class);

        $service->addReason($this->project, ['material_id' => $cement->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'First answer'], $this->owner);
        $this->useOn($slab, $cement, '1');
        $service->addReason($this->project, ['material_id' => $cement->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'Second answer'], $this->owner);

        $this->assertSame(2, MaterialFlagReason::where('project_id', $this->project->id)->count());
        $this->assertSame('Second answer', $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED)['reason']);
    }

    private function orderedAt($material, string $baseQty, string $amount): void
    {
        $order = PurchaseOrder::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'party_id' => $this->makeVendor('Rate Vendor '.Str::random(4))->id,
            'store_id' => $this->store->id, 'number' => 'PO-'.Str::upper(Str::random(6)), 'po_date' => '2026-11-01', 'status' => 'ISSUED',
        ]);

        PurchaseOrderItem::create([
            'organization_id' => $this->organization->id, 'purchase_order_id' => $order->id, 'material_id' => $material->id,
            'qty' => $baseQty, 'unit_id' => $material->base_unit_id, 'base_qty' => $baseQty, 'rate' => '1.00', 'amount' => $amount,
        ]);
    }
}
