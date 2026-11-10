<?php

namespace Tests\Feature\Crm;

use App\Services\Crm\FinancialYear;
use App\Services\Crm\ReasonService;
use App\Services\Crm\WinLossReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\BuildsTenant;
use Tests\TestCase;

class WinLossReportTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function reasonId(int $organizationId, string $kind, string $label): int
    {
        return app(ReasonService::class)->options($organizationId, $kind)->firstWhere('label', $label)->id;
    }

    public function test_the_financial_year_runs_april_to_march(): void
    {
        $this->assertSame(2026, FinancialYear::startYearOf(new \DateTimeImmutable('2026-04-01')));
        $this->assertSame(2025, FinancialYear::startYearOf(new \DateTimeImmutable('2026-03-31')));
        $this->assertSame(2026, FinancialYear::startYearOf(new \DateTimeImmutable('2027-03-31')));
        $this->assertSame('FY 2026-27', FinancialYear::label(2026));
        $this->assertSame('FY 2099-00', FinancialYear::label(2099));
        [$from, $to] = FinancialYear::range(2026);
        $this->assertSame('2026-04-01', $from->toDateString());
        $this->assertSame('2027-03-31', $to->toDateString());
    }

    public function test_counts_values_and_rates_for_a_year(): void
    {
        $user = $this->crmUser();
        $id = $user->organization_id;
        $price = $this->reasonId($id, 'lost', 'Price too high');
        $eligibility = $this->reasonId($id, 'lost', 'Did not meet eligibility');
        $relationship = $this->reasonId($id, 'won', 'Good relationship with the client');

        $this->makeTender($user->organization, ['title' => 'W1', 'stage' => 'won', 'closed_on' => '2026-05-10', 'awarded_value' => '1000.10', 'outcome_reason_id' => $relationship]);
        $this->makeTender($user->organization, ['title' => 'W2', 'stage' => 'won', 'closed_on' => '2027-03-31', 'awarded_value' => '2000.20']);
        $this->makeTender($user->organization, ['title' => 'L1', 'stage' => 'lost', 'closed_on' => '2026-06-01', 'bid_value' => '3000.00', 'winning_value' => '2800.00', 'outcome_reason_id' => $price]);
        $this->makeTender($user->organization, ['title' => 'L2', 'stage' => 'lost', 'closed_on' => '2026-07-01', 'bid_value' => '900.00', 'winning_value' => '1000.00', 'outcome_reason_id' => $price]);
        $this->makeTender($user->organization, ['title' => 'L3', 'stage' => 'lost', 'closed_on' => '2026-08-01', 'estimated_value' => '4000.00', 'outcome_reason_id' => $eligibility]);
        $this->makeTender($user->organization, ['title' => 'N1', 'stage' => 'no_bid', 'closed_on' => '2026-09-01']);
        $this->makeTender($user->organization, ['title' => 'Waiting', 'stage' => 'submitted', 'bid_value' => '700.00']);

        $report = app(WinLossReport::class)->build($id, 2026);

        $this->assertSame(['won' => 2, 'lost' => 3, 'no_bid' => 1], $report['counts']);
        $this->assertSame('3000.30', $report['values']['won']);
        $this->assertSame('7900.00', $report['values']['lost']);
        $this->assertSame('40.0', $report['win_rate_count']);
        $this->assertSame('27.5', $report['win_rate_value']);
        $this->assertSame(['count' => 1, 'value' => '700.00'], $report['awaiting']);
        $this->assertSame('FY 2026-27', $report['label']);

        // L1 was 7.142857% dearer, L2 10% cheaper: the average is -1.4 (one decimal), from two tenders.
        $this->assertSame(2, $report['price_gap']['tenders']);
        $this->assertSame('-1.4', $report['price_gap']['percent']);

        $lost = collect($report['reasons']['lost']);
        $this->assertSame('Price too high', $lost[0]['label']);
        $this->assertSame(2, $lost[0]['count']);
        $this->assertSame('3900.00', $lost[0]['value']);
        $this->assertSame('66.7', $lost[0]['share']);
        $this->assertSame(1, $lost->firstWhere('label', 'Did not meet eligibility')['count']);
        $won = collect($report['reasons']['won']);
        $this->assertSame(1, $won->firstWhere('label', 'Good relationship with the client')['count']);
        $this->assertSame(1, $won->firstWhere('label', 'No reason recorded')['count']);
        $this->assertSame('50.0', $won->firstWhere('label', 'No reason recorded')['share']);
        $this->assertCount(6, $report['tenders']);
    }

    public function test_the_year_boundaries_are_the_first_of_april_and_the_last_of_march(): void
    {
        // The list of years always has the current one too, so the day is pinned inside FY 2026-27.
        // On the real clock this test would start failing on 1 April 2028.
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'Asia/Kolkata'));
        $user = $this->crmUser();
        $this->makeTender($user->organization, ['title' => 'Last day of FY25', 'stage' => 'won', 'closed_on' => '2026-03-31', 'awarded_value' => '1.00']);
        $this->makeTender($user->organization, ['title' => 'First day of FY26', 'stage' => 'won', 'closed_on' => '2026-04-01', 'awarded_value' => '2.00']);
        $this->makeTender($user->organization, ['title' => 'First day of FY27', 'stage' => 'won', 'closed_on' => '2027-04-01', 'awarded_value' => '4.00']);

        $report = app(WinLossReport::class);

        $this->assertSame('2.00', $report->build($user->organization_id, 2026)['values']['won']);
        $this->assertSame('1.00', $report->build($user->organization_id, 2025)['values']['won']);
        $this->assertSame('4.00', $report->build($user->organization_id, 2027)['values']['won']);
        $this->assertSame([2027, 2026, 2025], $report->years($user->organization_id));
    }

    public function test_an_empty_year_has_no_rates_and_the_page_says_so(): void
    {
        $user = $this->crmUser();

        $report = app(WinLossReport::class)->build($user->organization_id, 2020);

        $this->assertNull($report['win_rate_count']);
        $this->assertNull($report['win_rate_value']);
        $this->assertNull($report['price_gap']['percent']);
        $this->get(route('crm.reports.win-loss', ['fy' => 2020]))->assertOk()->assertSee('No closed tenders in this year');
    }

    public function test_the_page_shows_reasons_authorities_and_the_closed_list(): void
    {
        $user = $this->crmUser();
        $price = $this->reasonId($user->organization_id, 'lost', 'Price too high');
        $this->makeTender($user->organization, ['title' => 'Lost canal', 'authority' => 'Irrigation Dept', 'stage' => 'lost', 'closed_on' => today(), 'bid_value' => '1000.00', 'winning_value' => '800.00', 'outcome_reason_id' => $price]);
        $this->makeTender($user->organization, ['title' => 'Won road', 'authority' => 'irrigation dept', 'stage' => 'won', 'closed_on' => today(), 'awarded_value' => '500.00']);

        $fy = FinancialYear::current();
        $this->get(route('crm.reports.win-loss', ['fy' => $fy]))->assertOk()
            ->assertSee('Price too high')->assertSee('Lost canal')->assertSee('Won road')->assertSee('By authority')
            ->assertSee('25.0% above the winner', false)->assertSee('50.0%');
    }

    public function test_an_odd_year_in_the_address_falls_back_to_the_current_one(): void
    {
        $this->crmUser();

        $this->get(route('crm.reports.win-loss', ['fy' => 'abc']))->assertOk()->assertSee(FinancialYear::label(FinancialYear::current()));
        $this->get(route('crm.reports.win-loss', ['fy' => 99999999]))->assertOk()->assertSee(FinancialYear::label(FinancialYear::current()));
    }

    public function test_another_companys_tenders_do_not_count(): void
    {
        $user = $this->crmUser();
        $other = $this->tenant('Other Infra');
        $this->makeTender($other, ['stage' => 'won', 'closed_on' => today(), 'awarded_value' => '999.00']);

        $report = app(WinLossReport::class)->build($user->organization_id, FinancialYear::current());

        $this->assertSame(0, $report['counts']['won']);
        $this->assertSame('0.00', $report['values']['won']);
    }

    public function test_reasons_can_be_added_renamed_and_switched_off_without_losing_history(): void
    {
        $user = $this->crmUser();
        $id = $user->organization_id;
        $old = $this->reasonId($id, 'lost', 'Missed the deadline');
        $tender = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today(), 'outcome_reason_id' => $old]);

        $this->post(route('crm.reasons.store'), ['kind' => 'lost', 'label' => 'Rates changed after pre-bid'])->assertSessionHasNoErrors();
        $this->patch(route('crm.reasons.update', $old), ['label' => 'Missed the deadline']);

        $options = app(ReasonService::class)->options($id, 'lost');
        $this->assertTrue($options->contains('label', 'Rates changed after pre-bid'));
        $this->assertFalse($options->contains('id', $old));
        $this->assertSame($old, $tender->fresh()->outcome_reason_id);
        $this->assertSame('Missed the deadline', app(WinLossReport::class)->build($id, FinancialYear::current())['reasons']['lost'][0]['label']);

        $this->post(route('crm.reasons.store'), ['kind' => 'weather', 'label' => 'Rain'])->assertSessionHasErrors('kind');
    }

    public function test_a_switched_off_reason_can_no_longer_be_chosen(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'submitted']);
        $reason = $this->reasonId($user->organization_id, 'lost', 'Price too high');
        $this->patch(route('crm.reasons.update', $reason), ['label' => 'Price too high']);

        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'lost', 'outcome_reason_id' => $reason])->assertSessionHasErrors('outcome_reason_id');
    }
}
