<?php

namespace Tests\Feature\Procurement;

use App\Models\MaterialIssue;
use App\Models\MaterialReturn;
use App\Models\StockLedgerEntry;
use App\Models\StockTransfer;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\MaterialReturnService;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function returnToStore(MaterialIssue $issue, string $qty, array $overrides = []): MaterialReturn
    {
        return app(MaterialReturnService::class)->returnToStore($issue, [
            'return_date' => '2026-11-04',
            'items' => [['line_id' => $issue->items()->firstOrFail()->id, 'qty' => $qty]],
            ...$overrides,
        ], $this->owner);
    }

    private function dispatch(string $qty, $to, array $overrides = []): StockTransfer
    {
        return app(StockTransferService::class)->dispatch($this->project, [
            'from_store_id' => $this->store->id,
            'to_store_id' => $to->id,
            'dispatch_date' => '2026-11-03',
            'items' => [['material_id' => $this->cement->id, 'qty' => $qty]],
            ...$overrides,
        ], $this->owner);
    }

    public function test_an_issue_is_in_the_chosen_unit_and_leaves_stock_in_base_units(): void
    {
        $this->stockIn($this->cement, '1000');
        $boq = $this->makeBoqItem($this->project);

        $issue = $this->issue($this->cement, '10', ['unit_id' => $this->bag->id, 'boq_item_id' => $boq->id, 'activity' => null]);

        $this->assertSame('500.000', $this->balanceOf($this->cement));
        $this->assertSame('500.000', $issue->items()->firstOrFail()->base_qty);
        $this->assertSame($boq->id, StockLedgerEntry::where('movement_type', 'issue')->firstOrFail()->boq_item_id);
    }

    public function test_a_line_must_name_a_boq_item_or_an_activity(): void
    {
        $this->stockIn($this->cement, '10');
        $rules = app(MaterialIssueService::class)->rules($this->project);

        $validator = validator([
            'store_id' => $this->store->id,
            'issue_date' => '2026-11-02',
            'items' => [['material_id' => $this->cement->id, 'qty' => '1']],
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('items.0.boq_item_id', $validator->errors()->toArray());
    }

    public function test_repeating_an_issue_with_the_same_uuid_takes_stock_once(): void
    {
        $this->stockIn($this->cement, '100');
        $uuid = (string) Str::uuid();

        $first = $this->issue($this->cement, '40', [], ['client_uuid' => $uuid]);
        $second = $this->issue($this->cement, '40', [], ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('60.000', $this->balanceOf($this->cement));
    }

    public function test_worked_example_return_to_store_cannot_exceed_what_was_issued(): void
    {
        // 100 kg in, 40 kg issued, so 60 kg is left.
        $this->stockIn($this->cement, '100');
        $issue = $this->issue($this->cement, '40');

        // 15 kg comes back unused: 75 kg in stock and 25 kg still out.
        $this->returnToStore($issue, '15');
        $this->assertSame('75.000', $this->balanceOf($this->cement));

        // Only 25 kg can still come back.
        try {
            $this->returnToStore($issue, '25.001');
            $this->fail('A return larger than the balance must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('over_return', $exception->errors());
        }

        $this->returnToStore($issue, '25');
        $this->assertSame('100.000', $this->balanceOf($this->cement));
    }

    public function test_repeating_a_return_with_the_same_uuid_adds_stock_once(): void
    {
        $this->stockIn($this->cement, '100');
        $issue = $this->issue($this->cement, '40');
        $uuid = (string) Str::uuid();

        $first = $this->returnToStore($issue, '5', ['client_uuid' => $uuid]);
        $second = $this->returnToStore($issue, '5', ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('65.000', $this->balanceOf($this->cement));
    }

    public function test_an_issue_with_returns_must_have_them_reversed_first(): void
    {
        $this->stockIn($this->cement, '100');
        $issue = $this->issue($this->cement, '40');
        $return = $this->returnToStore($issue, '10');
        $service = app(MaterialIssueService::class);

        try {
            $service->reverse($issue, 'Mistake', $this->owner);
            $this->fail('The returns must go first.');
        } catch (ValidationException) {
            $this->assertSame('POSTED', $issue->fresh()->status);
        }

        app(MaterialReturnService::class)->reverse($return, 'Wrong qty', $this->owner);
        $service->reverse($issue->fresh(), 'Mistake', $this->owner);

        $this->assertSame('100.000', $this->balanceOf($this->cement));
        $this->assertSame('REVERSED', $issue->fresh()->status);
    }

    public function test_a_reversed_issue_cannot_take_a_return(): void
    {
        $this->stockIn($this->cement, '100');
        $issue = $this->issue($this->cement, '40');
        app(MaterialIssueService::class)->reverse($issue, 'Mistake', $this->owner);

        $this->expectException(ValidationException::class);
        $this->returnToStore($issue->fresh(), '1');
    }

    public function test_worked_example_return_to_vendor_makes_the_quantity_due_again(): void
    {
        // 100 bags ordered, 60 received = 3000 kg.
        $order = $this->issuedOrder();
        $grn = app(GrnService::class)->create($this->project, [
            'purchase_order_id' => $order->id,
            'grn_date' => '2026-11-03',
            'items' => [['purchase_order_item_id' => $order->items[0]->id, 'qty' => '60']],
        ], $this->owner);

        // 10 bags (500 kg) go back: 2500 kg in stock, net received 2500 of 5000.
        $return = app(MaterialReturnService::class)->returnToVendor($grn, [
            'return_date' => '2026-11-04',
            'items' => [['line_id' => $grn->items()->firstOrFail()->id, 'qty' => '10']],
        ], $this->owner);

        $this->assertSame('2500.000', $this->balanceOf($this->cement));
        $this->assertSame('2500.000', app(PurchaseOrderService::class)->netReceived($order)[$order->items[0]->id]);
        $this->assertSame('PARTIAL', $order->fresh()->status);

        // A GRN with a posted vendor return cannot be reversed on its own.
        try {
            app(GrnService::class)->reverse($grn, 'Mistake', $this->owner);
            $this->fail('The vendor return must go first.');
        } catch (ValidationException) {
            $this->assertSame('POSTED', $grn->fresh()->status);
        }

        app(MaterialReturnService::class)->reverse($return, 'Vendor kept it', $this->owner);
        $this->assertSame('3000.000', $this->balanceOf($this->cement));
        $this->assertSame('3000.000', app(PurchaseOrderService::class)->netReceived($order)[$order->items[0]->id]);
    }

    public function test_return_to_vendor_cannot_take_stock_negative(): void
    {
        $order = $this->issuedOrder();
        $grn = app(GrnService::class)->create($this->project, [
            'purchase_order_id' => $order->id,
            'grn_date' => '2026-11-03',
            'items' => [['purchase_order_item_id' => $order->items[0]->id, 'qty' => '60']],
        ], $this->owner);
        $this->issue($this->cement, '2900');

        try {
            app(MaterialReturnService::class)->returnToVendor($grn, [
                'return_date' => '2026-11-04',
                'items' => [['line_id' => $grn->items()->firstOrFail()->id, 'qty' => '10']],
            ], $this->owner);
            $this->fail('Only 100 kg remains in the store.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $this->assertSame('100.000', $this->balanceOf($this->cement));
        $this->assertSame(0, MaterialReturn::count());
    }

    public function test_worked_example_transfer_with_a_shortage(): void
    {
        $site2 = $this->makeStore($this->makeProject($this->organization, ['name' => 'Site 2']), 'Site 2 store');
        $this->stockIn($this->cement, '100');
        $service = app(StockTransferService::class);

        // 30 kg leaves: 70 kg here, nothing there yet, 30 kg in transit.
        $transfer = $this->dispatch('30', $site2);
        $this->assertSame('70.000', $this->balanceOf($this->cement));
        $this->assertSame('0.000', $this->balanceOf($this->cement, $site2));
        $this->assertSame('DISPATCHED', $transfer->status);

        // 28 kg arrives: 2 kg is recorded as short.
        $line = $transfer->items()->firstOrFail();
        $service->receive($transfer, '2026-11-04', [$line->id => '28'], $this->owner);

        $this->assertSame('28.000', $this->balanceOf($this->cement, $site2));
        $this->assertSame('2.000', $line->fresh()->shortQty());
        $this->assertSame('RECEIVED', $transfer->fresh()->status);
        $this->assertSame($site2->project_id, StockLedgerEntry::where('movement_type', 'transfer_in')->firstOrFail()->project_id);
    }

    public function test_a_transfer_defaults_to_receiving_everything(): void
    {
        $other = $this->makeStore($this->project, 'Yard');
        $this->stockIn($this->cement, '100');
        $transfer = $this->dispatch('30', $other);

        app(StockTransferService::class)->receive($transfer, '2026-11-04', [], $this->owner);

        $this->assertSame('30.000', $this->balanceOf($this->cement, $other));
    }

    public function test_a_transfer_cannot_be_received_twice_or_over_the_dispatched_quantity(): void
    {
        $other = $this->makeStore($this->project, 'Yard');
        $this->stockIn($this->cement, '100');
        $transfer = $this->dispatch('30', $other);
        $service = app(StockTransferService::class);
        $line = $transfer->items()->firstOrFail();

        try {
            $service->receive($transfer, '2026-11-04', [$line->id => '31'], $this->owner);
            $this->fail('More cannot arrive than was sent.');
        } catch (ValidationException) {
            $this->assertSame('0.000', $this->balanceOf($this->cement, $other));
        }

        $service->receive($transfer->fresh(), '2026-11-04', [], $this->owner);

        $this->expectException(ValidationException::class);
        $service->receive($transfer->fresh(), '2026-11-04', [], $this->owner);
    }

    public function test_a_transfer_cannot_send_more_than_the_store_holds(): void
    {
        $other = $this->makeStore($this->project, 'Yard');
        $this->stockIn($this->cement, '10');

        $this->expectException(ValidationException::class);
        $this->dispatch('10.001', $other);
    }

    public function test_reversing_a_transfer_in_transit_puts_stock_back(): void
    {
        $other = $this->makeStore($this->project, 'Yard');
        $this->stockIn($this->cement, '100');
        $transfer = $this->dispatch('30', $other);

        app(StockTransferService::class)->reverse($transfer, 'Truck cancelled', $this->owner);

        $this->assertSame('100.000', $this->balanceOf($this->cement));
        $this->assertSame('REVERSED', $transfer->fresh()->status);
    }

    public function test_reversing_a_received_transfer_needs_the_stock_still_at_the_destination(): void
    {
        $other = $this->makeStore($this->project, 'Yard');
        $this->stockIn($this->cement, '100');
        $transfer = $this->dispatch('30', $other);
        app(StockTransferService::class)->receive($transfer, '2026-11-04', [], $this->owner);
        app(MaterialIssueService::class)->create($this->project, [
            'store_id' => $other->id,
            'issue_date' => '2026-11-05',
            'items' => [['material_id' => $this->cement->id, 'qty' => '25', 'activity' => 'Slab']],
        ], $this->owner);

        try {
            app(StockTransferService::class)->reverse($transfer->fresh(), 'Mistake', $this->owner);
            $this->fail('Only 5 kg remains at the destination.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $this->assertSame('RECEIVED', $transfer->fresh()->status);
        $this->assertSame('70.000', $this->balanceOf($this->cement));
        $this->assertSame('5.000', $this->balanceOf($this->cement, $other));
    }

    public function test_repeating_a_dispatch_with_the_same_uuid_moves_stock_once(): void
    {
        $other = $this->makeStore($this->project, 'Yard');
        $this->stockIn($this->cement, '100');
        $uuid = (string) Str::uuid();

        $first = $this->dispatch('30', $other, ['client_uuid' => $uuid]);
        $second = $this->dispatch('30', $other, ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('70.000', $this->balanceOf($this->cement));
    }
}
