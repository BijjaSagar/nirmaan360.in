<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\ImmutableRecordException;
use App\Models\Grn;
use App\Models\GrnItem;
use App\Models\PurchaseOrder;
use App\Models\StockLedgerEntry;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GrnServiceTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->order = $this->issuedOrder();
    }

    private function receive(string $qty, ?int $unitId = null, array $overrides = []): Grn
    {
        return app(GrnService::class)->create($this->project, [
            'purchase_order_id' => $this->order->id,
            'grn_date' => '2026-11-03',
            'challan_no' => 'CH-101',
            'items' => [[
                'purchase_order_item_id' => $this->order->items[0]->id,
                'qty' => $qty,
                'unit_id' => $unitId ?? $this->bag->id,
            ]],
            ...$overrides,
        ], $this->owner);
    }

    public function test_worked_example_partial_receipts_cannot_exceed_the_po(): void
    {
        // Ordered 100 bags = 5000 kg. 60 bags arrive: PARTIAL with 3000 kg in stock.
        $this->receive('60');
        $this->assertSame('PARTIAL', $this->order->fresh()->status);
        $this->assertSame('3000.000', $this->balanceOf($this->cement));

        // 41 more would be 101 bags, so it is refused and nothing moves.
        try {
            $this->receive('41');
            $this->fail('A receipt over the PO must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('over_receipt', $exception->errors());
        }

        $this->assertSame('3000.000', $this->balanceOf($this->cement));
        $this->assertSame(1, Grn::count());

        // The last 40 bags complete it: RECEIVED with 5000 kg in stock.
        $this->receive('40');
        $this->assertSame('RECEIVED', $this->order->fresh()->status);
        $this->assertSame('5000.000', $this->balanceOf($this->cement));
    }

    public function test_worked_example_receipts_in_another_unit_count_against_the_same_po(): void
    {
        // 2000 kg is 40 bags of the 100 ordered.
        $this->receive('2000', $this->kg->id);

        $this->assertSame('2000.000', app(PurchaseOrderService::class)->netReceived($this->order)[$this->order->items[0]->id]);

        // 60 bags = 3000 kg fits exactly.
        $this->receive('60');
        $this->assertSame('RECEIVED', $this->order->fresh()->status);
    }

    public function test_one_kilo_over_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->receive('5000.001', $this->kg->id);
    }

    public function test_two_lines_for_one_po_item_are_added_together(): void
    {
        $line = ['purchase_order_item_id' => $this->order->items[0]->id, 'qty' => '60', 'unit_id' => $this->bag->id];

        $this->expectException(ValidationException::class);
        $this->receive('0', null, ['items' => [$line, $line]]);
    }

    public function test_rejected_quantity_is_recorded_but_adds_no_stock(): void
    {
        $grn = $this->receive('60', null, ['items' => [[
            'purchase_order_item_id' => $this->order->items[0]->id,
            'qty' => '60',
            'unit_id' => $this->bag->id,
            'rejected_qty' => '5',
        ]]]);

        $this->assertSame('5.000', $grn->items()->firstOrFail()->rejected_qty);
        $this->assertSame('3000.000', $this->balanceOf($this->cement));
    }

    public function test_repeating_a_receipt_with_the_same_uuid_adds_no_stock(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->receive('60', null, ['client_uuid' => $uuid]);
        $second = $this->receive('60', null, ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('3000.000', $this->balanceOf($this->cement));
        $this->assertSame(1, Grn::count());
    }

    public function test_receipts_only_work_on_an_issued_po(): void
    {
        $this->order->forceFill(['status' => 'DRAFT'])->saveQuietly();

        $this->expectException(ValidationException::class);
        $this->receive('1');
    }

    public function test_a_closed_po_refuses_more_receipts_and_stays_closed_after_a_reversal(): void
    {
        $grn = $this->receive('60');
        $service = app(PurchaseOrderService::class);

        $this->assertSame('CLOSED', $service->close($this->order)->status);

        try {
            $this->receive('1');
            $this->fail('A closed PO must refuse receipts.');
        } catch (ValidationException) {
            $this->assertSame(1, Grn::count());
        }

        app(GrnService::class)->reverse($grn, 'Wrong challan', $this->owner);
        $this->assertSame('CLOSED', $this->order->fresh()->status);
    }

    public function test_worked_example_reversing_a_receipt_makes_it_due_again(): void
    {
        $grn = $this->receive('60');
        app(GrnService::class)->reverse($grn, 'Entered against the wrong PO', $this->owner);

        $this->assertSame('REVERSED', $grn->fresh()->status);
        $this->assertSame('ISSUED', $this->order->fresh()->status);
        $this->assertSame('0.000', $this->balanceOf($this->cement));
        $this->assertSame(2, StockLedgerEntry::count());

        // All 100 bags are due again.
        $this->receive('100');
        $this->assertSame('RECEIVED', $this->order->fresh()->status);
    }

    public function test_a_receipt_cannot_be_reversed_once_its_stock_is_used(): void
    {
        $grn = $this->receive('60');
        $this->issue($this->cement, '2500');

        try {
            app(GrnService::class)->reverse($grn, 'Mistake', $this->owner);
            $this->fail('The reversal would take the store negative.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $this->assertSame('POSTED', $grn->fresh()->status);
        $this->assertSame('500.000', $this->balanceOf($this->cement));
    }

    public function test_a_receipt_cannot_be_reversed_twice(): void
    {
        $grn = $this->receive('60');
        app(GrnService::class)->reverse($grn, 'Mistake', $this->owner);

        $this->expectException(ValidationException::class);
        app(GrnService::class)->reverse($grn->fresh(), 'Again', $this->owner);
    }

    public function test_a_posted_receipt_cannot_be_edited_or_deleted(): void
    {
        $grn = $this->receive('60');

        try {
            $grn->fresh()->update(['challan_no' => 'CH-999']);
            $this->fail('A posted receipt must not change.');
        } catch (ImmutableRecordException) {
            $this->assertSame('CH-101', $grn->fresh()->challan_no);
        }

        $this->expectException(ImmutableRecordException::class);
        GrnItem::firstOrFail()->update(['qty' => '1']);
    }
}
