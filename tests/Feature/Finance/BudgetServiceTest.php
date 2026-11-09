<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceBudgetLine;
use App\Services\Finance\BudgetService;
use App\Services\Finance\VendorBillService;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

class BudgetServiceTest extends FinanceTestCase
{
    private function report(): array
    {
        return app(BudgetService::class)->report($this->project);
    }

    private function row(string $head): array
    {
        return (new Collection($this->report()['rows']))->firstWhere('head', $head);
    }

    public function test_budget_versus_actual_matches_a_worked_example(): void
    {
        app(BudgetService::class)->save($this->project, ['Materials' => '150000', 'Subcontractors' => '50000', 'Site expenses' => '2000']);
        $this->approvedVendorBill();
        $this->approvedSubBill();
        $wallet = $this->wallet();
        $this->petty($wallet, 'topup', '9000');
        $this->petty($wallet, 'expense', '2500.25', 'Food');
        $this->petty($wallet, 'expense', '1000', 'Fuel');
        $this->petty($wallet, 'expense', '500', 'Materials');

        $materials = $this->row('Materials');
        $this->assertSame('100500.00', $materials['actual']);
        $this->assertSame('49500.00', $materials['variance']);
        $this->assertSame('67.0', $materials['used_pct']);

        $subs = $this->row('Subcontractors');
        $this->assertSame('60000.00', $subs['actual']);
        $this->assertSame('-10000.00', $subs['variance']);

        $this->assertSame('2500.25', $this->row('Site expenses')['actual']);
        $this->assertSame('-500.25', $this->row('Site expenses')['variance']);
        $this->assertSame('1000.00', $this->row('Plant & machinery')['actual']);
        $this->assertNull($this->row('Plant & machinery')['used_pct']);

        $report = $this->report();
        $this->assertSame('202000.00', $report['total_budget']);
        $this->assertSame('164000.25', $report['total_actual']);
        $this->assertSame('37999.75', $report['total_variance']);
    }

    public function test_totals_equal_the_sum_of_the_rows(): void
    {
        app(BudgetService::class)->save($this->project, ['Materials' => '1000.10', 'Labour' => '2000.20']);
        $this->approvedVendorBill(['taxable_amount' => '333.33']);
        $this->petty($this->wallet(), 'expense', '0.07', 'Labour');

        $report = $this->report();
        $sum = fn (string $key) => Dec::money(array_reduce($report['rows'], fn (BigDecimal $total, array $row) => $total->plus(Dec::of($row[$key])), BigDecimal::zero()));

        $this->assertSame($sum('budget'), $report['total_budget']);
        $this->assertSame($sum('actual'), $report['total_actual']);
        $this->assertSame($sum('variance'), $report['total_variance']);
    }

    public function test_reversed_bills_and_drafts_do_not_count(): void
    {
        $this->draftVendorBill(['bill_no' => 'DRAFT-1']);
        app(VendorBillService::class)->reverse($this->approvedVendorBill(), 'Wrong', $this->owner);

        $this->assertSame('0.00', $this->row('Materials')['actual']);
    }

    public function test_margin_compares_billed_value_with_cost(): void
    {
        $this->approvedClientBill();
        $this->approvedVendorBill();

        $report = $this->report();

        $this->assertSame('250000.00', $report['billed']);
        $this->assertSame('150000.00', $report['margin']);
    }

    public function test_saving_updates_and_blank_removes_a_line(): void
    {
        app(BudgetService::class)->save($this->project, ['Materials' => '100', 'Labour' => '200']);
        app(BudgetService::class)->save($this->project, ['Materials' => '150', 'Labour' => '']);

        $this->assertSame('150.00', FinanceBudgetLine::where('cost_head', 'Materials')->first()->amount);
        $this->assertNull(FinanceBudgetLine::where('cost_head', 'Labour')->first());
        $this->assertSame(1, FinanceBudgetLine::count());
    }
}
