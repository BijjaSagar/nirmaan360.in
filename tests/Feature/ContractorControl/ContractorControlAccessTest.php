<?php

namespace Tests\Feature\ContractorControl;

use App\Models\ContractorMilestone;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Services\Access\TeamAccessService;
use App\Services\ContractorControl\ContractorTenderService;
use App\Services\ContractorControl\MilestoneService;
use App\Support\Dec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Who can open what, across companies, projects and roles. */
class ContractorControlAccessTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->site();
    }

    private function moneyPages($order, $tender, $party): array
    {
        return [
            route('contractor-control.tenders.index', $this->project),
            route('contractor-control.tenders.create', $this->project),
            route('contractor-control.tenders.show', $tender),
            route('contractor-control.variance', $this->project),
            route('contractor-control.contractors.index'),
            route('contractor-control.contractors.show', $party),
            route('contractor-control.contractors.pdf', $party),
        ];
    }

    private function tender(): ContractorTender
    {
        return app(ContractorTenderService::class)->create($this->project, ['title' => 'Flooring', 'boq_item_ids' => [$this->item->id]], $this->owner);
    }

    public function test_money_pages_refuse_site_staff(): void
    {
        $order = $this->contract();
        $tender = $this->tender();

        foreach ($this->moneyPages($order, $tender, $order->party) as $page) {
            $this->actingAs($this->supervisor)->get($page)->assertForbidden();
        }
    }

    public function test_money_pages_open_for_the_manager(): void
    {
        $order = $this->contract();
        $this->paid($order, '1000');
        $tender = $this->tender();

        foreach ($this->moneyPages($order, $tender, $order->party) as $page) {
            $this->actingAs($this->manager)->get($page)->assertOk();
        }
    }

    public function test_the_board_shows_site_staff_work_and_nothing_else(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        $response = $this->actingAs($this->supervisor)->get(route('contractor-control.board', $this->project))->assertOk();

        $response->assertSee('70%');
        $response->assertDontSee('90%');
        $response->assertDontSee('90,000');
        $response->assertDontSee('1,00,000');
        $response->assertDontSee('Paid ahead');
        $response->assertDontSee('Balance money');
    }

    public function test_the_contract_page_shows_site_staff_work_and_nothing_else(): void
    {
        $order = $this->contract();
        app(MilestoneService::class)->add($order, ['title' => 'Plinth', 'amount' => '25000'], $this->manager);
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        $response = $this->actingAs($this->supervisor)->get(route('contractor-control.contracts.show', $order))->assertOk();

        $response->assertSee('70%')->assertSee('Plinth');
        $response->assertDontSee('90,000');
        $response->assertDontSee('25,000');
        $response->assertDontSee('1,000.00');
        $response->assertDontSee('Payment history');
    }

    public function test_the_request_form_shows_site_staff_work_and_nothing_else(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        $response = $this->actingAs($this->supervisor)->get(route('contractor-control.payments.create', $order))->assertOk();

        $response->assertSee('70%')->assertDontSee('90,000')->assertDontSee('Paid 90%');
    }

    public function test_the_deadline_page_is_open_to_site_staff(): void
    {
        $order = $this->contract();
        $order->update(['end_date' => today()->addDays(5)]);

        $this->actingAs($this->supervisor)->get(route('contractor-control.deadlines', $this->project))->assertOk()->assertDontSee('₹');
    }

    public function test_the_manager_sees_the_example_on_the_board(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        $this->actingAs($this->manager)
            ->get(route('contractor-control.board', $this->project))
            ->assertOk()
            ->assertSee('Paid 90%, Work 70%')
            ->assertSee('Over limit');
    }

    public function test_store_staff_are_kept_out(): void
    {
        $store = $this->member($this->organization, 'store');

        $this->actingAs($store)->get(route('contractor-control.board', $this->project))->assertForbidden();
    }

    public function test_a_viewer_cannot_request_a_payment(): void
    {
        $viewer = $this->member($this->organization, 'viewer');

        $this->actingAs($viewer)->post(route('contractor-control.payments.store', $this->contract()), $this->paymentData())->assertForbidden();
    }

    public function test_a_supervisor_cannot_change_the_limit_or_the_parts(): void
    {
        $order = $this->contract();

        $this->actingAs($this->supervisor)->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => 50])->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('contractor-control.milestones.store', $order), ['title' => 'X', 'amount' => '1'])->assertForbidden();
        $this->actingAs($this->supervisor)->patch(route('contractor-control.contracts.deadline', $order), ['end_date' => '2027-01-01'])->assertForbidden();
    }

    public function test_the_manager_sets_the_deadline_and_only_the_owner_the_limit(): void
    {
        // The limit is also where approving a contractor payment needs the owner, so the manager cannot move it.
        $this->actingAs($this->manager)
            ->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => '7.5', 'deadline_date' => '2027-03-31'])
            ->assertSessionHasErrors('payment_gap_alert_pct');
        $this->assertSame('5.00', Dec::money($this->project->refresh()->payment_gap_alert_pct));

        // The deadline alone, or with the limit sent back unchanged as a page opened earlier would, is his to set.
        $this->actingAs($this->manager)
            ->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => '5', 'deadline_date' => '2027-03-31'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame('2027-03-31', $this->project->refresh()->deadline_date->toDateString());
        $this->actingAs($this->manager)->patch(route('contractor-control.settings', $this->project), ['deadline_date' => '2027-04-30'])->assertSessionHasNoErrors();
        $this->assertSame('2027-04-30', $this->project->refresh()->deadline_date->toDateString());

        $this->actingAs($this->owner)
            ->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => '7.5', 'deadline_date' => '2027-03-31'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame('7.50', Dec::money($this->project->refresh()->payment_gap_alert_pct));
    }

    public function test_the_limit_must_be_a_percentage(): void
    {
        $this->actingAs($this->owner)->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => 101])->assertSessionHasErrors('payment_gap_alert_pct');
        $this->actingAs($this->owner)->patch(route('contractor-control.settings', $this->project), [])->assertSessionHasErrors('payment_gap_alert_pct');
    }

    public function test_another_company_cannot_reach_anything(): void
    {
        $order = $this->contract();
        $tender = $this->tender();
        $part = app(MilestoneService::class)->add($order, ['title' => 'Plinth', 'amount' => '25000'], $this->manager);
        $payment = $this->raise($order, '100');

        $stranger = $this->member($this->tenant('Other Co'), 'owner');

        foreach ([
            route('contractor-control.board', $this->project),
            route('contractor-control.deadlines', $this->project),
            route('contractor-control.variance', $this->project),
            route('contractor-control.contracts.show', $order),
            route('contractor-control.payments.index', $this->project),
            route('contractor-control.payments.show', $payment),
            route('contractor-control.tenders.show', $tender),
            route('contractor-control.contractors.show', $order->party),
        ] as $page) {
            $this->actingAs($stranger)->get($page)->assertNotFound();
        }

        $this->actingAs($stranger)->delete(route('contractor-control.milestones.destroy', $part))->assertNotFound();
        $this->assertDatabaseCount((new ContractorMilestone)->getTable(), 1);
    }

    public function test_a_party_from_another_company_cannot_quote(): void
    {
        $tender = $this->tender();
        $other = $this->tenant('Other Co');
        $party = Party::create(['organization_id' => $other->id, 'type' => 'subcontractor', 'name' => 'Outsider']);

        $this->actingAs($this->manager)
            ->post(route('contractor-control.tenders.bids.store', $tender), ['party_id' => $party->id, 'quoted_amount' => '100'])
            ->assertSessionHasErrors('party_id');
    }

    public function test_a_boq_item_from_another_project_cannot_be_tendered(): void
    {
        $other = $this->makeProject($this->organization);
        $elsewhere = $this->makeBoqItem($other);

        $this->actingAs($this->manager)
            ->post(route('contractor-control.tenders.store', $this->project), ['title' => 'X', 'boq_item_ids' => [$elsewhere->id]])
            ->assertSessionHasErrors('boq_item_ids.0');
    }

    public function test_someone_limited_to_another_project_cannot_reach_this_one(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '100');
        $tender = $this->tender();

        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$this->makeProject($this->organization)->id]);
        $limited->refresh();

        foreach ([
            route('contractor-control.board', $this->project),
            route('contractor-control.contracts.show', $order),
            route('contractor-control.payments.show', $payment),
            route('contractor-control.tenders.show', $tender),
        ] as $page) {
            $this->actingAs($limited)->get($page)->assertNotFound();
        }

        $this->actingAs($limited)->get(route('contractor-control.contractors.index'))->assertOk()->assertDontSee($order->party->name);
    }

    public function test_the_free_plan_does_not_have_this_module(): void
    {
        $free = $this->tenant('Small', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);

        $this->actingAs($owner)->get(route('contractor-control.board', $project))->assertForbidden();
    }

    public function test_the_menu_shows_money_pages_only_to_money_people(): void
    {
        $this->actingAs($this->manager)->get(route('contractor-control.board', $this->project))->assertSee('Contractor tenders')->assertSee('Cost variance');
        $this->actingAs($this->supervisor)->get(route('contractor-control.board', $this->project))->assertDontSee('Contractor tenders')->assertDontSee('Cost variance');
    }
}
