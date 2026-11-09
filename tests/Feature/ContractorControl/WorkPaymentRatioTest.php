<?php

namespace Tests\Feature\ContractorControl;

use App\Models\ContractorMilestone;
use App\Models\RaBill;
use App\Models\VendorPayment;
use App\Services\ContractorControl\ContractorLedgerService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\CostVarianceService;
use App\Services\ContractorControl\DeadlineViewService;
use App\Services\ContractorControl\Figures;
use App\Services\ContractorControl\MilestoneService;
use App\Services\ContractorControl\WorkPaymentRatio;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Every worked example in sections 7 and 13 of the build document, in rupees. */
class WorkPaymentRatioTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site();
    }

    private function ratio(): WorkPaymentRatio
    {
        return app(WorkPaymentRatio::class);
    }

    public function test_example_1_paid_90_percent_work_70_percent_is_red(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        $row = $this->ratio()->forContract($order);

        $this->assertSame('100000.00', $row['contract_value']);
        $this->assertSame('90000.00', $row['paid']);
        $this->assertSame('90.00', $row['paid_pct']);
        $this->assertSame('70.00', $row['work_pct']);
        $this->assertSame('20.00', $row['gap_pct']);
        $this->assertSame('red', $row['status']);
        $this->assertTrue($row['over_limit']);
        $this->assertSame('Paid 90%, Work 70%', $row['label']);
    }

    public function test_example_1_balance_work_against_balance_money(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        $row = $this->ratio()->forContract($order);

        $this->assertSame('30.00', $row['balance_work_pct']);
        $this->assertSame('30000.00', $row['balance_work_value']);
        $this->assertSame('10000.00', $row['balance_money']);
        $this->assertSame('20000.00', $row['short_by']);
    }

    public function test_balance_work_is_exact_when_the_percent_had_to_be_rounded(): void
    {
        $order = $this->contract();
        $this->addLine($order, $this->makeBoqItem($this->project, '40.000', '500.00'), '40.000', '500.00');
        $this->workDone($this->item, '70');

        $row = $this->ratio()->forContract($order);

        $this->assertSame('58.33', $row['work_pct']);
        $this->assertSame('50000.00', $row['balance_work_value']);
    }

    public function test_it_turns_red_the_moment_payment_crosses_work(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');

        $this->paid($order, '70000');
        $this->assertSame('green', $this->ratio()->forContract($order)['status']);

        $this->paid($order, '100');
        $this->assertSame('red', $this->ratio()->forContract($order)['status']);
    }

    public function test_paying_exactly_as_much_as_the_work_is_green(): void
    {
        $this->assertSame('green', $this->ratio()->compare('100000', '50000', '50.00', '5')['status']);
    }

    public function test_being_ahead_within_the_limit_is_red_but_not_over_the_limit(): void
    {
        $row = $this->ratio()->compare('100000', '73000', '70.00', '5');

        $this->assertSame('red', $row['status']);
        $this->assertFalse($row['over_limit']);
    }

    public function test_the_limit_comes_from_the_project(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '73000');

        $this->assertFalse($this->ratio()->forContract($order)['over_limit']);

        $this->project->update(['payment_gap_alert_pct' => '2.00']);
        $order->refresh();

        $this->assertTrue($this->ratio()->forContract($order)['over_limit']);
    }

    public function test_a_gap_exactly_at_the_limit_is_not_over_it(): void
    {
        $row = $this->ratio()->compare('100000', '75000', '70.00', '5');

        $this->assertSame('red', $row['status']);
        $this->assertFalse($row['over_limit']);
    }

    public function test_paid_percent_formula(): void
    {
        $this->assertSame('90.00', $this->ratio()->compare('100000', '90000', '0.00', '5')['paid_pct']);
    }

    public function test_section_13_work_done_percent_for_an_item(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '50');

        $this->assertSame('50.00', $this->ratio()->lines($order)['lines'][0]['work_pct']);
    }

    public function test_a_contract_with_no_value_is_not_shown_as_green(): void
    {
        $order = $this->contract(null, '0', '500');
        $this->paid($order, '5000');

        $row = $this->ratio()->forContract($order);

        $this->assertSame('none', $row['status']);
        $this->assertFalse($row['over_limit']);
    }

    public function test_only_approved_payments_count(): void
    {
        $order = $this->contract();
        $waiting = $this->raise($order, '40000');

        $this->assertSame('0.00', $this->ratio()->forContract($order)['paid']);

        $this->approve($waiting);

        $this->assertSame('40000.00', $this->ratio()->forContract($order)['paid']);
    }

    public function test_a_reversal_takes_the_money_back_out(): void
    {
        $order = $this->contract();
        $payment = $this->paid($order, '40000', ['is_advance' => true]);

        app(ContractorPaymentService::class)->reverse($payment, 'Entered against the wrong contractor', $this->manager);

        $row = $this->ratio()->forContract($order);
        $this->assertSame('0.00', $row['paid']);
        $this->assertSame('0.00', $row['advance']);
    }

    public function test_a_payment_can_only_be_reversed_once(): void
    {
        $payment = $this->paid($this->contract(), '100');
        $service = app(ContractorPaymentService::class);
        $service->reverse($payment, 'first', $this->manager);

        $this->expectException(ValidationException::class);
        $service->reverse($payment, 'second', $this->manager);
    }

    public function test_example_2_the_flooring_item_shows_red_even_when_the_contract_looks_fine(): void
    {
        $flooring = $this->makeBoqItem($this->project, '100.000', '1000.00');
        $plaster = $this->makeBoqItem($this->project, '100.000', '1000.00');
        $order = $this->contract($flooring);
        $this->addLine($order, $plaster, '100.000', '1000.00');

        $this->workDone($flooring, '20');
        $this->workDone($plaster, '80');
        $this->paid($order, '60000', ['boq_item_id' => $flooring->id]);
        $this->paid($order, '20000', ['boq_item_id' => $plaster->id]);

        $this->assertSame('green', $this->ratio()->forContract($order)['status']);

        $lines = collect($this->ratio()->lines($order)['lines'])->keyBy(fn ($line) => $line['boq_item']->id);

        $this->assertSame('red', $lines[$flooring->id]['status']);
        $this->assertSame('60.00', $lines[$flooring->id]['paid_pct']);
        $this->assertSame('20.00', $lines[$flooring->id]['work_pct']);
        $this->assertSame('green', $lines[$plaster->id]['status']);
    }

    public function test_a_line_shared_with_another_contract_counts_only_this_contracts_work(): void
    {
        $first = $this->contract($this->item, '60.000');
        $second = $this->contract($this->item, '40.000');

        $this->workDone($this->item, '30', $first);
        $this->workDone($this->item, '40', $second);

        $this->assertSame('50.00', app(WorkProgress::class)->workOrderLinePercents($first)[$first->items()->first()->id]);
        $this->assertSame('100.00', app(WorkProgress::class)->workOrderLinePercents($second)[$second->items()->first()->id]);
    }

    public function test_payments_tied_to_no_item_are_reported_separately(): void
    {
        $order = $this->contract();
        $this->paid($order, '10000', ['boq_item_id' => $this->item->id]);
        $this->paid($order, '2500');

        $this->assertSame('2500.00', $this->ratio()->lines($order)['unallocated']);
        $this->assertSame('12500.00', $this->ratio()->forContract($order)['paid']);
    }

    public function test_example_3_excavation_is_ten_thousand_over_the_boq(): void
    {
        $excavation = $this->makeBoqItem($this->project, '100.000', '350.00');
        $order = $this->contract($excavation, '100.000', '350.00');
        $this->workDone($excavation, '100');
        $this->paid($order, '45000', ['boq_item_id' => $excavation->id]);

        $row = app(CostVarianceService::class)->forProject($this->project)['rows'][0];

        $this->assertSame('35000.00', $row['boq_amount']);
        $this->assertSame('45000.00', $row['actual']);
        $this->assertSame('10000.00', $row['variance']);
        $this->assertSame('28.57', $row['variance_pct']);
        $this->assertSame('+₹10,000.00', Figures::signedInr($row['variance']));
    }

    public function test_an_item_nobody_has_paid_against_shows_no_variance(): void
    {
        $this->contract();

        $this->assertNull(app(CostVarianceService::class)->forProject($this->project)['rows'][0]['variance']);
    }

    public function test_a_waiting_payment_is_not_part_of_the_variance(): void
    {
        $order = $this->contract();
        $this->raise($order, '45000', ['boq_item_id' => $this->item->id]);

        $this->assertNull(app(CostVarianceService::class)->forProject($this->project)['rows'][0]['variance']);
    }

    public function test_lakshmi_puja_deadline_shows_work_left_against_days_left(): void
    {
        $order = $this->contract();
        $order->update(['start_date' => '2026-10-01', 'end_date' => '2026-11-30', 'deadline_label' => 'Before Lakshmi Puja']);
        $this->workDone($this->item, '60');

        $view = app(DeadlineViewService::class)->forProject($this->project, Carbon::parse('2026-11-20'));
        $row = $view['contracts'][0];

        $this->assertSame('40.00', $row['work_left_pct']);
        $this->assertSame(10, $row['days_left']);
        $this->assertSame('4.00', $row['needed_per_day']);
        $this->assertSame('Before Lakshmi Puja', $row['deadline_label']);
        $this->assertSame('behind', $row['state']);
    }

    public function test_a_missed_deadline_is_overdue(): void
    {
        $order = $this->contract();
        $order->update(['end_date' => '2026-11-01']);

        $row = app(DeadlineViewService::class)->forProject($this->project, Carbon::parse('2026-11-20'))['contracts'][0];

        $this->assertSame(-19, $row['days_left']);
        $this->assertSame('overdue', $row['state']);
        $this->assertNull($row['needed_per_day']);
    }

    public function test_a_contract_without_its_own_date_uses_the_project_deadline(): void
    {
        $this->contract();
        $this->project->update(['deadline_date' => '2026-12-05']);

        $row = app(DeadlineViewService::class)->forProject($this->project->refresh(), Carbon::parse('2026-11-20'))['contracts'][0];

        $this->assertSame(15, $row['days_left']);
    }

    public function test_a_contract_with_no_date_says_so(): void
    {
        $this->contract();

        $this->assertSame('no_date', app(DeadlineViewService::class)->forProject($this->project, Carbon::parse('2026-11-20'))['contracts'][0]['state']);
    }

    public function test_finished_work_is_done_whatever_the_date(): void
    {
        $order = $this->contract();
        $order->update(['end_date' => '2026-11-01']);
        $this->workDone($this->item, '100');

        $this->assertSame('done', app(DeadlineViewService::class)->forProject($this->project, Carbon::parse('2026-11-20'))['contracts'][0]['state']);
    }

    public function test_milestone_parts_track_what_was_paid_against_each(): void
    {
        $order = $this->contract();
        $service = app(MilestoneService::class);
        $plinth = $service->add($order, ['title' => 'Plinth', 'amount' => '25000'], $this->manager);
        $slab = $service->add($order, ['title' => 'Slab', 'amount' => '30000'], $this->manager);
        $service->toggleDone($plinth, $this->manager);

        $this->paid($order, '25000', ['contractor_milestone_id' => $plinth->id]);
        $this->paid($order, '40000', ['contractor_milestone_id' => $slab->id]);

        $track = $service->track($order);

        $this->assertSame('55000.00', $track['total']);
        $this->assertSame('45000.00', $track['unallocated']);
        $this->assertSame('paid', $track['parts'][0]['state']);
        $this->assertFalse($track['parts'][0]['paid_before_done']);
        $this->assertSame('over', $track['parts'][1]['state']);
        $this->assertSame('-10000.00', $track['parts'][1]['balance']);
        $this->assertTrue($track['parts'][1]['paid_before_done']);
    }

    public function test_parts_cannot_add_up_to_more_than_the_contract(): void
    {
        $order = $this->contract();
        $service = app(MilestoneService::class);
        $service->add($order, ['title' => 'First', 'amount' => '60000'], $this->manager);

        $this->expectException(ValidationException::class);
        $service->add($order, ['title' => 'Second', 'amount' => '50000'], $this->manager);
    }

    public function test_a_part_with_a_payment_cannot_be_removed(): void
    {
        $order = $this->contract();
        $part = app(MilestoneService::class)->add($order, ['title' => 'Plinth', 'amount' => '25000'], $this->manager);
        $this->raise($order, '100', ['contractor_milestone_id' => $part->id]);

        $this->expectException(ValidationException::class);
        app(MilestoneService::class)->remove($part);
    }

    public function test_a_part_with_no_payment_can_be_removed(): void
    {
        $part = app(MilestoneService::class)->add($this->contract(), ['title' => 'Plinth', 'amount' => '25000'], $this->manager);

        app(MilestoneService::class)->remove($part);

        $this->assertDatabaseCount((new ContractorMilestone)->getTable(), 0);
    }

    public function test_contractor_ledger_shows_contract_paid_advance_balance_and_history(): void
    {
        $party = $this->contractorNamed('Flooring contractor');
        $order = $this->contract(null, '100.000', '1000.00', $party);
        $this->paid($order, '20000', ['is_advance' => true, 'paid_on' => '2026-10-01']);
        $this->paid($order, '30000', ['paid_on' => '2026-10-15']);
        $this->raise($order, '5000');

        $ledger = app(ContractorLedgerService::class)->forContractor($party);

        $this->assertSame('100000.00', $ledger['summary']['contract_value']);
        $this->assertSame('50000.00', $ledger['summary']['paid']);
        $this->assertSame('20000.00', $ledger['summary']['advance']);
        $this->assertSame('50000.00', $ledger['summary']['balance_money']);
        $this->assertSame('80000.00', $ledger['history'][0]['balance']);
        $this->assertSame('50000.00', $ledger['history'][1]['balance']);
        $this->assertCount(1, $ledger['not_counted']);
    }

    public function test_a_contractors_totals_add_up_across_contracts(): void
    {
        $party = $this->contractorNamed('Two contracts');
        $big = $this->contract($this->makeBoqItem($this->project, '100.000', '1000.00'), '100.000', '1000.00', $party);
        $small = $this->contract($this->makeBoqItem($this->project, '100.000', '500.00'), '100.000', '500.00', $party);
        $this->workDone($big->items()->first()->boqItem, '50', $big);
        $this->workDone($small->items()->first()->boqItem, '100', $small);
        $this->paid($small, '50000');
        $this->paid($big, '60000');

        $rollup = app(ContractorLedgerService::class)->forContractor($party)['summary'];

        $this->assertSame('150000.00', $rollup['contract_value']);
        $this->assertSame('110000.00', $rollup['paid']);
        $this->assertSame('66.67', $rollup['work_pct']);
        $this->assertSame('73.33', $rollup['paid_pct']);
        $this->assertSame(1, $rollup['contracts_ahead']);
    }

    /** The first contractor left at 70% and the line was given to a second contract. The 70% is one piece of work, not two. */
    public function test_a_re_let_line_does_not_count_the_same_work_on_both_contracts(): void
    {
        $party = $this->contractorNamed('Came back under a new contract');
        $first = $this->contract($this->item, '100.000', '1000.00', $party);
        $this->workDone($this->item, '70');
        $this->paid($first, '70000');
        $first->update(['status' => 'CLOSED']);
        $second = $this->contract($this->item, '100.000', '1000.00', $party);

        $rows = $this->ratio()->forWorkOrders(collect([$first->refresh(), $second]))->keyBy(fn (array $row) => $row['work_order']->id);
        $rollup = app(ContractorLedgerService::class)->forContractor($party)['summary'];

        $this->assertSame('70.00', $rows[$second->id]['work_pct']);
        $this->assertSame('0.00', $rows[$first->id]['work_pct']);
        $this->assertSame('70000.00', Dec::money(Dec::of($rows[$first->id]['earned'])->plus(Dec::of($rows[$second->id]['earned']))));
        $this->assertSame('35.00', $rollup['work_pct']);
        $this->assertSame('35.00', $rollup['paid_pct']);
    }

    public function test_the_board_and_the_company_ledger_do_not_run_a_query_for_every_contract(): void
    {
        $build = function (int $count): void {
            foreach (range(1, $count) as $i) {
                $item = $this->makeBoqItem($this->project, '100.000', '1000.00');
                $order = $this->contract($item);
                $this->workDone($item, '50');
                $this->paid($order, '20000');
            }
        };

        $count = function (callable $run): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $run();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $build(2);
        $boardForTwo = $count(fn () => $this->ratio()->forProject($this->project));
        $ledgerForTwo = $count(fn () => app(ContractorLedgerService::class)->contractors());

        $build(6);
        $boardForEight = $count(fn () => $this->ratio()->forProject($this->project));
        $ledgerForEight = $count(fn () => app(ContractorLedgerService::class)->contractors());

        $this->assertSame($boardForTwo, $boardForEight, "Board ran {$boardForTwo} queries for 2 contracts and {$boardForEight} for 8.");
        $this->assertSame($ledgerForTwo, $ledgerForEight, "Ledger ran {$ledgerForTwo} queries for 2 contracts and {$ledgerForEight} for 8.");
    }

    public function test_ra_bill_payments_are_reported_but_never_counted(): void
    {
        $order = $this->contract();
        $this->paid($order, '10000');

        $bill = RaBill::forceCreate([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id,
            'party_id' => $order->party_id, 'work_order_id' => $order->id, 'type' => 'subcontractor', 'bill_no' => 1, 'number' => 'SUB-1',
            'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => 'APPROVED',
        ]);

        VendorPayment::forceCreate([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'party_id' => $order->party_id,
            'ra_bill_id' => $bill->id, 'amount' => '70000', 'paid_on' => '2026-11-02', 'mode' => 'bank', 'status' => 'POSTED',
        ]);

        $ledger = app(ContractorLedgerService::class)->forContract($order->refresh());

        $this->assertSame('10000.00', $ledger['ratio']['paid']);
        $this->assertSame('10.00', $ledger['ratio']['paid_pct']);
        $this->assertSame('70000.00', $ledger['finance_paid']);
    }

    public function test_figures_are_written_as_on_the_screens(): void
    {
        $this->assertSame('90', Figures::pct('90.00'));
        $this->assertSame('70.5', Figures::pct('70.50'));
        $this->assertSame('100', Figures::pct('100.00'));
        $this->assertSame('0', Figures::pct('0.00'));
        $this->assertSame('+10', Figures::signedPct('10.00'));
        $this->assertSame('−10', Figures::signedPct('-10.00'));
        $this->assertSame('−₹10,000.00', Figures::signedInr('-10000'));
        $this->assertSame('₹0.00', Figures::signedInr(Dec::of(0)));
    }
}
