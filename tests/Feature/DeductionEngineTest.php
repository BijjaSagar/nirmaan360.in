<?php

namespace Tests\Feature;

use App\Services\DeductionEngine;
use Tests\TestCase;

class DeductionEngineTest extends TestCase
{
    private array $terms = [
        'retention_pct' => '5',
        'tds_pct' => '2',
        'gst_pct' => '18',
        'advance_balance' => '100000',
        'advance_recovery_pct' => '10',
        'material_recovery' => '0',
        'order' => ['retention', 'advance', 'material', 'tds'],
        'basis' => 'gross',
    ];

    public function test_percentages_apply_to_gross_by_default(): void
    {
        $result = (new DeductionEngine)->compute('250000', $this->terms);

        $this->assertSame(['retention', 'advance', 'tds'], array_column($result['deductions'], 'type'));
        $this->assertSame(['12500.00', '25000.00', '5000.00'], array_column($result['deductions'], 'amount'));
        $this->assertSame('42500.00', $result['deduction_total']);
        $this->assertSame('45000.00', $result['gst_amount']);
        $this->assertSame('252500.00', $result['net_payable']);
    }

    public function test_running_basis_applies_each_percentage_to_the_remaining_balance(): void
    {
        $result = (new DeductionEngine)->compute('250000', [...$this->terms, 'basis' => 'running']);

        // Retention 5% of 2,50,000 = 12,500. Advance 10% of 2,37,500 = 23,750. TDS 2% of 2,13,750 = 4,275.
        $this->assertSame(['12500.00', '23750.00', '4275.00'], array_column($result['deductions'], 'amount'));
        $this->assertSame('254475.00', $result['net_payable']);
    }

    public function test_configured_order_changes_a_running_calculation(): void
    {
        $result = (new DeductionEngine)->compute('250000', [
            ...$this->terms,
            'basis' => 'running',
            'order' => ['tds', 'retention', 'advance', 'material'],
        ]);

        // TDS 2% of 2,50,000 = 5,000. Retention 5% of 2,45,000 = 12,250. Advance 10% of 2,32,750 = 23,275.
        $this->assertSame(['5000.00', '12250.00', '23275.00'], array_column($result['deductions'], 'amount'));
    }

    public function test_advance_recovery_stops_at_the_outstanding_balance(): void
    {
        $result = (new DeductionEngine)->compute('250000', [...$this->terms, 'advance_balance' => '7000']);

        $this->assertSame('7000.00', $result['deductions'][1]['amount']);
    }

    public function test_nothing_is_recovered_once_the_advance_is_cleared(): void
    {
        $result = (new DeductionEngine)->compute('250000', [...$this->terms, 'advance_balance' => '0']);

        $this->assertSame(['retention', 'tds'], array_column($result['deductions'], 'type'));
    }

    public function test_material_recovery_is_a_fixed_amount(): void
    {
        $result = (new DeductionEngine)->compute('250000', [...$this->terms, 'material_recovery' => '1500.50']);

        $this->assertSame('1500.50', $result['deductions'][2]['amount']);
        $this->assertSame('44000.50', $result['deduction_total']);
    }
}
