<?php

namespace Tests\Feature\BillingExtras;

use App\Models\Party;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** FR-BIL-01: item rate, lump sum and labour-only work orders each bill correctly. */
class WorkOrderRateTypeTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    public function test_item_rate_pays_for_everything_measured_even_beyond_the_order_quantity(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['rate_type' => 'item_rate'], qty: '40', rate: '1200');
        $this->measure($contract, '50', $workOrder);

        $bill = $this->subBill($contract, $workOrder);

        // 50 × 1,200 = 60,000. Retention 10% = 6,000. TDS 1% = 600. Net 53,400.
        $this->assertSame('60000.00', $bill->gross_amount);
        $this->assertSame('53400.00', $bill->net_payable);
        $this->assertSame('item_rate', $bill->terms['rate_type']);
    }

    public function test_item_rate_can_carry_a_material_recovery(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract);
        $this->measure($contract, '10', $workOrder);

        $bill = $this->subBill($contract, $workOrder, '500.50');

        // 10 × 1,200 = 12,000. Retention 1,200 + material 500.50 + TDS 120 = 1,820.50. Net 10,179.50.
        $this->assertSame('1820.50', $bill->deduction_total);
        $this->assertSame('10179.50', $bill->net_payable);
    }

    public function test_lump_sum_pays_measured_progress_up_to_the_fixed_value_across_bills(): void
    {
        $contract = $this->clientContract();
        // The line is worth 40 × 1,200 = 48,000 however much is measured.
        $workOrder = $this->workOrder($contract, ['rate_type' => 'lump_sum'], qty: '40', rate: '1200');

        $this->measure($contract, '30', $workOrder);
        $first = $this->subBill($contract, $workOrder);
        $this->assertSame('36000.00', $first->gross_amount);

        // 50 measured would earn 60,000, but the line stops at 48,000: 48,000 − 36,000 = 12,000.
        $this->measure($contract, '20', $workOrder);
        $second = $this->subBill($contract, $workOrder);
        $line = $second->items()->first();
        $this->assertSame('12000.00', $second->gross_amount);
        $this->assertSame('36000.00', $line->prev_amount);
        $this->assertSame('12000.00', $line->current_amount);
        $this->assertSame('48000.00', $line->cumulative_amount);
        $this->assertSame('20.000', $line->current_qty);
        $this->assertSame('50.000', $line->cumulative_qty);

        // Further measurement earns nothing.
        $this->measure($contract, '5', $workOrder);
        $third = $this->subBill($contract, $workOrder);
        $this->assertSame('0.00', $third->gross_amount);
        $this->assertSame('48000.00', $third->items()->first()->cumulative_amount);
    }

    public function test_lump_sum_gives_money_back_only_when_the_reduced_quantity_falls_below_its_value(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['rate_type' => 'lump_sum'], qty: '40', rate: '1200');
        $this->measure($contract, '55', $workOrder);
        $this->subBill($contract, $workOrder);

        // 55 − 15 = 40 measured, which still earns the full 48,000.
        $this->measure($contract, '-15', $workOrder);
        $this->assertSame('0.00', $this->subBill($contract, $workOrder)->gross_amount);

        // 40 − 10 = 30 measured earns 36,000: 36,000 − 48,000 = −12,000.
        $this->measure($contract, '-10', $workOrder);
        $this->assertSame('-12000.00', $this->subBill($contract, $workOrder)->gross_amount);
    }

    public function test_a_lump_sum_line_with_no_quantity_is_refused_rather_than_billed_at_nothing(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['rate_type' => 'lump_sum'], qty: '0', rate: '1200');
        $this->measure($contract, '10', $workOrder);

        $this->expectException(ValidationException::class);
        $this->subBill($contract, $workOrder);
    }

    public function test_labour_only_bills_at_the_labour_rate_with_no_material_recovery(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['rate_type' => 'labour_only'], qty: '100', rate: '350.50');
        $this->measure($contract, '20.5', $workOrder);

        $bill = $this->subBill($contract, $workOrder);

        // 20.5 × 350.50 = 7,185.25. Retention 718.525 → 718.53, TDS 71.8525 → 71.85. Net 6,394.87.
        $this->assertSame('7185.25', $bill->gross_amount);
        $this->assertSame('6394.87', $bill->net_payable);
    }

    public function test_labour_only_refuses_a_material_recovery(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['rate_type' => 'labour_only']);
        $this->measure($contract, '10', $workOrder);

        try {
            $this->subBill($contract, $workOrder, '100');
            $this->fail('A labour-only bill took a material recovery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('material_recovery', $exception->errors());
        }
    }

    public function test_the_form_needs_a_quantity_on_every_lump_sum_line(): void
    {
        $this->withoutVite();
        $contract = $this->clientContract();
        $party = Party::create(['organization_id' => $contract->organization->id, 'type' => 'subcontractor', 'name' => 'Asha Works']);
        $this->actingAs($contract->owner);

        $form = [
            'party_id' => $party->id, 'title' => 'Waterproofing', 'retention_pct' => 5, 'tds_pct' => 1, 'gst_pct' => 18,
            'advance_amount' => 0, 'advance_recovery_pct' => 0, 'deduction_basis' => 'gross', 'deduction_order' => array_key_first(config('buildos.deduction_orders')),
        ];

        $this->post(route('work-orders.store', $contract->project), [...$form, 'rate_type' => 'lump_sum', 'items' => [$contract->item->id => ['qty' => '', 'rate' => '900']]])
            ->assertSessionHasErrors('items');
        $this->assertSame(0, WorkOrder::count());

        $this->post(route('work-orders.store', $contract->project), [...$form, 'rate_type' => 'item_rate', 'items' => [$contract->item->id => ['qty' => '', 'rate' => '900']]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, WorkOrder::count());
    }
}
