<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\BoqCurrentRate;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\ProgressEntry;
use App\Models\ProjectPermit;
use App\Services\ProjectSetup\BoqBudget;

class BoqBudgetTest extends ProjectSetupTestCase
{
    private BoqItem $rcc;

    private BoqItem $brick;

    private BoqItem $basin;

    protected function setUp(): void
    {
        parent::setUp();

        // 5,00,000 + 4,00,000 + 30,000 = 9,30,000.
        $this->rcc = $this->line('100.000', '5000.00', ['code' => 'R1', 'category' => 'rcc', 'description' => 'RCC M25 in slab']);
        $this->brick = $this->line('200.000', '2000.00', ['code' => 'B1', 'category' => 'brick_work', 'description' => 'Brick masonry']);
        $this->basin = $this->line('10.000', '3000.00', ['code' => 'S1', 'category' => 'plumbing', 'unit' => 'nos', 'description' => 'Jaguar wash basin']);
        $this->project->update(['budget_amount' => '1000000.00', 'budget_margin_pct' => '5.00']);
    }

    private function summary(): array
    {
        return app(BoqBudget::class)->summary($this->project->fresh());
    }

    private function rates(array $rates, string $date = '2026-11-05', ?string $source = 'Dealer quote'): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.budget.rates', $this->project), ['rates' => $rates, 'noted_on' => $date, 'source' => $source])->assertSessionHasNoErrors();
    }

    public function test_the_budget_the_boq_and_the_variance_between_them(): void
    {
        $summary = $this->summary();

        $this->assertSame('1000000.00', $summary['budget']);
        $this->assertSame('5.00', $summary['margin_pct']);
        $this->assertSame('1050000.00', $summary['ceiling']);
        $this->assertSame('930000.00', $summary['boq']);
        $this->assertSame('-70000.00', $summary['variance']);
        $this->assertSame('-7.00', $summary['variance_pct']);
        $this->assertSame('within', $summary['status']);
    }

    public function test_over_budget_but_inside_the_margin_and_beyond_it(): void
    {
        $this->project->update(['budget_amount' => '900000.00']);
        $summary = $this->summary();
        $this->assertSame(['30000.00', '3.33', 'margin', '945000.00'], [$summary['variance'], $summary['variance_pct'], $summary['status'], $summary['ceiling']]);

        $this->project->update(['budget_amount' => '800000.00']);
        $summary = $this->summary();
        $this->assertSame(['130000.00', '16.25', 'over'], [$summary['variance'], $summary['variance_pct'], $summary['status']]);

        // Exactly at the budget is within it, and exactly at the ceiling is inside the margin.
        $this->project->update(['budget_amount' => '930000.00']);
        $this->assertSame('within', $this->summary()['status']);
        $this->project->update(['budget_amount' => '885714.29']);
        $this->assertSame('margin', $this->summary()['status']);
    }

    public function test_with_no_budget_there_is_no_variance(): void
    {
        $this->project->update(['budget_amount' => null]);
        $summary = $this->summary();

        $this->assertNull($summary['budget']);
        $this->assertNull($summary['variance']);
        $this->assertNull($summary['status']);
        $this->assertSame('930000.00', $summary['boq']);

        $this->actingAs($this->pm)->get(route('project-setup.budget', $this->project))->assertOk()->assertSee('No budget has been set for this project yet.');
    }

    public function test_current_rates_show_next_to_the_boq_rate_with_the_budget_impact(): void
    {
        $this->rates([$this->rcc->id => '5200', $this->brick->id => '2100', $this->basin->id => '']);

        $lines = app(BoqBudget::class)->lines($this->project)->keyBy(fn ($line) => $line['item']->code);

        $this->assertSame(['5200.00', '200.00', '20000.00'], [$lines['R1']['current_rate'], $lines['R1']['difference'], $lines['R1']['impact']]);
        $this->assertSame(['2100.00', '100.00', '20000.00'], [$lines['B1']['current_rate'], $lines['B1']['difference'], $lines['B1']['impact']]);
        $this->assertNull($lines['S1']['current_rate']);
        $this->assertNull($lines['S1']['impact']);
        $this->assertSame('Dealer quote', $lines['R1']['source']);
        $this->assertSame('2026-11-05', $lines['R1']['noted_on']->toDateString());

        $summary = $this->summary();
        $this->assertSame('40000.00', $summary['impact']);
        $this->assertSame('970000.00', $summary['projected']);
        $this->assertSame('-30000.00', $summary['projected_variance']);
        $this->assertSame('within', $summary['projected_status']);
        $this->assertSame(2, $summary['priced_lines']);
        $this->assertSame(3, $summary['line_count']);
    }

    public function test_a_fall_in_price_is_a_negative_impact_and_the_projection_can_cross_the_margin(): void
    {
        $this->project->update(['budget_amount' => '900000.00']);
        $this->rates([$this->rcc->id => '5500', $this->brick->id => '2200']);
        $summary = $this->summary();

        // +500 × 100 and +200 × 200.
        $this->assertSame('90000.00', $summary['impact']);
        $this->assertSame('1020000.00', $summary['projected']);
        $this->assertSame('over', $summary['projected_status']);
        $this->assertSame('margin', $summary['status']);

        $this->rates([$this->rcc->id => '4800'], '2026-11-06');
        $this->assertSame('-20000.00', app(BoqBudget::class)->lines($this->project)->firstWhere(fn ($line) => $line['item']->code === 'R1')['impact']);
    }

    public function test_current_rates_never_touch_the_boq_its_value_or_its_history(): void
    {
        $revisions = BoqRevision::withoutGlobalScopes()->count();

        $this->rates([$this->rcc->id => '9999']);

        $this->assertSame('5000.00', $this->rcc->fresh()->rate);
        $this->assertSame('930000.00', $this->summary()['boq']);
        $this->assertSame($revisions, BoqRevision::withoutGlobalScopes()->count());
    }

    public function test_saving_the_same_rates_twice_adds_nothing_and_a_new_rate_becomes_the_current_one(): void
    {
        $this->rates([$this->rcc->id => '5200']);
        $this->actingAs($this->pm)->post(route('project-setup.budget.rates', $this->project), ['rates' => [$this->rcc->id => '5200'], 'noted_on' => '2026-11-07'])
            ->assertSessionHas('success', 'No rate was changed.');
        $this->assertSame(1, BoqCurrentRate::count());

        $this->rates([$this->rcc->id => '5300'], '2026-11-08');
        $this->assertSame(2, BoqCurrentRate::count());
        $this->assertSame('5300.00', app(BoqBudget::class)->lines($this->project)->firstWhere(fn ($line) => $line['item']->code === 'R1')['current_rate']);

        // A rate noted for an earlier day, typed in late, does not replace the newer one.
        $this->rates([$this->rcc->id => '5100'], '2026-11-01');
        $this->assertSame('5300.00', app(BoqBudget::class)->lines($this->project)->firstWhere(fn ($line) => $line['item']->code === 'R1')['current_rate']);
    }

    public function test_rates_are_checked(): void
    {
        $post = fn (array $data) => $this->actingAs($this->pm)->post(route('project-setup.budget.rates', $this->project), $data);

        $post(['rates' => [$this->rcc->id => '-1'], 'noted_on' => '2026-11-05'])->assertSessionHasErrors('rates.'.$this->rcc->id);
        $post(['rates' => [$this->rcc->id => 'abc'], 'noted_on' => '2026-11-05'])->assertSessionHasErrors('rates.'.$this->rcc->id);
        $post(['rates' => [$this->rcc->id => '1.234'], 'noted_on' => '2026-11-05'])->assertSessionHasErrors('rates.'.$this->rcc->id);
        $post(['rates' => [$this->rcc->id => '10'], 'noted_on' => '2999-01-01'])->assertSessionHasErrors('noted_on');
        $post(['rates' => [$this->rcc->id => '10']])->assertSessionHasErrors('noted_on');
        $post(['noted_on' => '2026-11-05'])->assertSessionHasErrors('rates');

        $this->assertSame(0, BoqCurrentRate::count());
    }

    public function test_a_rate_for_an_item_of_another_project_is_refused(): void
    {
        $elsewhere = $this->makeProject($this->org, ['code' => 'ELSE']);
        $foreign = $this->line('1.000', '1.00', [], $elsewhere);

        $this->actingAs($this->pm)->post(route('project-setup.budget.rates', $this->project), ['rates' => [$foreign->id => '5'], 'noted_on' => '2026-11-05'])->assertSessionHasErrors('rates');

        $this->assertSame(0, BoqCurrentRate::count());
    }

    public function test_rcc_and_brick_work_get_their_own_figures(): void
    {
        $this->rates([$this->rcc->id => '5200']);
        // 120 done against a BOQ of 100: 20 more than allowed for, at 5,000 a cum.
        ProgressEntry::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'boq_item_id' => $this->rcc->id, 'work_date' => '2026-11-05', 'qty_done' => '120']);
        ProgressEntry::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'boq_item_id' => $this->brick->id, 'work_date' => '2026-11-05', 'qty_done' => '50']);

        $summary = $this->summary();
        $watched = collect(app(BoqBudget::class)->watched($this->project, app(BoqBudget::class)->lines($this->project), $summary['boq']))->keyBy('category');

        $this->assertSame(['rcc', 'brick_work'], $watched->keys()->all());
        $this->assertSame(['1', '500000.00', '53.76', '20000.00', '100.00', 1, '100000.00'], [
            (string) $watched['rcc']['lines'], $watched['rcc']['value'], $watched['rcc']['share_pct'], $watched['rcc']['impact'], $watched['rcc']['done_pct'], $watched['rcc']['over_lines'], $watched['rcc']['over_value'],
        ]);
        // 50 of 200 cum done is a quarter.
        $this->assertSame(['400000.00', '25.00', 0, '0.00'], [$watched['brick_work']['value'], $watched['brick_work']['done_pct'], $watched['brick_work']['over_lines'], $watched['brick_work']['over_value']]);

        $this->actingAs($this->pm)->get(route('project-setup.budget', $this->project))->assertOk()
            ->assertSee('RCC and brick work')->assertSee('Done beyond the BOQ quantity')->assertSee('₹5,00,000.00', false)->assertSee('53.76%');
    }

    public function test_permission_fees_are_a_separate_head_and_are_not_mixed_into_the_variance(): void
    {
        ProjectPermit::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'name' => 'Fire NOC', 'status' => 'approved', 'fee' => '40000.00']);
        ProjectPermit::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'name' => 'Plan sanction', 'status' => 'applied', 'fee' => '60000.50']);

        $summary = $this->summary();

        $this->assertSame('100000.50', $summary['permit_fees']);
        $this->assertSame('1030000.50', $summary['boq_with_permits']);
        $this->assertSame('-70000.00', $summary['variance']);
        $this->assertSame('930000.00', $summary['boq']);
        $this->assertSame(['total' => 2, 'not_started' => 0, 'applied' => 1, 'approved' => 1, 'rejected' => 0], $summary['permits']);

        $this->actingAs($this->pm)->get(route('project-setup.budget', $this->project))->assertOk()
            ->assertSee('Permissions and approvals')->assertSee('₹1,00,000.50', false)->assertSee('They are not added into the BOQ value');
    }

    public function test_a_swap_shows_the_new_amount_and_the_effect_on_the_budget_without_saving_anything(): void
    {
        $this->project->update(['budget_amount' => '950000.00']);
        $revisions = BoqRevision::withoutGlobalScopes()->count();

        $swap = app(BoqBudget::class)->swapPreview($this->project->fresh(), $this->basin, 'Kohler wall-hung basin', '9000');

        $this->assertSame(['30000.00', '90000.00', '60000.00', '200.00'], [$swap['old_amount'], $swap['new_amount'], $swap['change'], $swap['change_pct']]);
        $this->assertSame(['930000.00', '990000.00'], [$swap['boq_before'], $swap['boq_after']]);
        $this->assertSame(['40000.00', '4.21', 'within', 'margin'], [$swap['variance_after'], $swap['variance_after_pct'], $swap['status_before'], $swap['status_after']]);
        $this->assertSame('Kohler wall-hung basin', $swap['new_description']);

        $this->actingAs($this->pm)->get(route('project-setup.budget', [$this->project, 'swap_item' => $this->basin->id, 'swap_rate' => '9000', 'swap_description' => 'Kohler wall-hung basin']))
            ->assertOk()->assertSee('What if we swap an item?')->assertSee('Jaguar wash basin')->assertSee('₹90,000.00', false)->assertSee('+60,000.00')->assertSee('+200.00%')->assertSee('Apply this swap to the BOQ');

        $this->assertSame('3000.00', $this->basin->fresh()->rate);
        $this->assertSame('Jaguar wash basin', $this->basin->fresh()->description);
        $this->assertSame($revisions, BoqRevision::withoutGlobalScopes()->count());
    }

    public function test_a_swap_that_lowers_the_cost_shows_a_saving(): void
    {
        $swap = app(BoqBudget::class)->swapPreview($this->project->fresh(), $this->basin, null, '1000');

        $this->assertSame(['-20000.00', '-66.67', '910000.00'], [$swap['change'], $swap['change_pct'], $swap['boq_after']]);
        $this->assertSame('Jaguar wash basin', $swap['new_description']);
    }

    public function test_applying_a_swap_revises_the_line_and_records_why(): void
    {
        BoqRevision::withoutGlobalScopes()->delete();

        $this->actingAs($this->pm)->post(route('project-setup.budget.swap', $this->project), [
            'boq_item_id' => $this->basin->id, 'rate' => '9000', 'description' => 'Kohler wall-hung basin', 'reason' => 'Client wants Kohler',
        ])->assertRedirect(route('project-setup.budget', $this->project))->assertSessionHas('success');

        $this->basin->refresh();
        $this->assertSame('9000.00', $this->basin->rate);
        $this->assertSame('Kohler wall-hung basin', $this->basin->description);

        $revision = BoqRevision::withoutGlobalScopes()->sole();
        $this->assertSame('Client wants Kohler', $revision->reason);
        $this->assertSame(['3000.00', '9000.00'], $revision->changes['rate']);
        $this->assertSame(['Jaguar wash basin', 'Kohler wall-hung basin'], $revision->changes['description']);
        $this->assertSame(['930000.00', '990000.00'], [$revision->valuation_before, $revision->valuation_after]);
        $this->assertSame($this->pm->id, $revision->user_id);
    }

    public function test_a_swap_with_no_new_description_keeps_the_old_one_and_needs_a_reason(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.budget.swap', $this->project), ['boq_item_id' => $this->basin->id, 'rate' => '9000', 'description' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('3000.00', $this->basin->fresh()->rate);

        $this->actingAs($this->pm)->post(route('project-setup.budget.swap', $this->project), ['boq_item_id' => $this->basin->id, 'rate' => '4000', 'description' => '', 'reason' => 'Dearer make'])->assertSessionHasNoErrors();
        $this->assertSame(['4000.00', 'Jaguar wash basin'], [$this->basin->fresh()->rate, $this->basin->fresh()->description]);
    }

    public function test_a_swap_cannot_reach_another_projects_item_or_take_a_bad_rate(): void
    {
        $elsewhere = $this->makeProject($this->org, ['code' => 'ELSE']);
        $foreign = $this->line('1.000', '1.00', [], $elsewhere);

        $this->actingAs($this->pm)->post(route('project-setup.budget.swap', $this->project), ['boq_item_id' => $foreign->id, 'rate' => '5', 'reason' => 'x'])->assertNotFound();
        $this->actingAs($this->pm)->post(route('project-setup.budget.swap', $this->project), ['boq_item_id' => $this->basin->id, 'rate' => '-5', 'reason' => 'x'])->assertSessionHasErrors('rate');
        $this->actingAs($this->pm)->get(route('project-setup.budget', [$this->project, 'swap_item' => $foreign->id, 'swap_rate' => '5']))->assertNotFound();
        $this->actingAs($this->pm)->get(route('project-setup.budget', [$this->project, 'swap_item' => $this->basin->id]))->assertSessionHasErrors('rate');

        $this->assertSame('1.00', $foreign->fresh()->rate);
    }

    public function test_the_screen_says_there_is_no_live_price_feed(): void
    {
        $this->actingAs($this->pm)->get(route('project-setup.budget', $this->project))->assertOk()
            ->assertSee('No live price feed.')->assertSee('typed in by hand')->assertSee('BOQ rate')->assertSee('Current rate')->assertSee('Budget impact')
            ->assertSee('Approved budget')->assertSee('₹10,00,000.00', false)->assertSee('₹10,50,000.00', false)->assertSee('-70,000.00')->assertSee('Within budget');
    }

    public function test_the_rates_can_be_limited_to_one_category(): void
    {
        $this->actingAs($this->pm)->get(route('project-setup.budget', [$this->project, 'category' => 'brick_work']))->assertOk()
            ->assertSee('Brick masonry')->assertSee('id="rate-'.$this->brick->id.'"', false)->assertDontSee('id="rate-'.$this->rcc->id.'"', false);

        $this->actingAs($this->pm)->get(route('project-setup.budget', [$this->project, 'category' => 'nonsense']))->assertSessionHasErrors('category');
    }

    public function test_every_budget_screen_needs_money_overview(): void
    {
        $swap = ['boq_item_id' => $this->basin->id, 'rate' => '9000', 'reason' => 'x'];
        $rates = ['rates' => [$this->rcc->id => '5200'], 'noted_on' => '2026-11-05'];
        $viewer = $this->holding(['boq.view', 'boq.manage', 'money.view', 'project.view']);

        // The estimator sees rates but not the budget, and a site engineer sees neither.
        foreach ([$this->engineer, $this->qs, $viewer, $this->member($this->org, 'accounts'), $this->member($this->org, 'store'), $this->member($this->org, 'viewer')] as $user) {
            $this->actingAs($user)->get(route('project-setup.budget', $this->project))->assertForbidden();
            $this->actingAs($user)->get(route('project-setup.budget', [$this->project, 'swap_item' => $this->basin->id, 'swap_rate' => '1']))->assertForbidden();
            $this->actingAs($user)->post(route('project-setup.budget.rates', $this->project), $rates)->assertForbidden();
            $this->actingAs($user)->post(route('project-setup.budget.swap', $this->project), $swap)->assertForbidden();
        }

        $this->assertSame(0, BoqCurrentRate::count());
        $this->assertSame('3000.00', $this->basin->fresh()->rate);

        foreach ([$this->owner, $this->pm] as $user) {
            $this->actingAs($user)->get(route('project-setup.budget', $this->project))->assertOk();
        }
    }

    public function test_applying_a_swap_also_needs_the_right_to_edit_the_boq(): void
    {
        $analyst = $this->holding(['money.overview', 'boq.view', 'project.view']);

        $this->actingAs($analyst)->get(route('project-setup.budget', [$this->project, 'swap_item' => $this->basin->id, 'swap_rate' => '9000']))
            ->assertOk()->assertSee('+60,000.00')->assertDontSee('Apply this swap to the BOQ');
        $this->actingAs($analyst)->post(route('project-setup.budget.swap', $this->project), ['boq_item_id' => $this->basin->id, 'rate' => '9000', 'reason' => 'x'])->assertForbidden();
        $this->assertSame('3000.00', $this->basin->fresh()->rate);
    }

    public function test_the_budget_and_rates_of_one_company_are_invisible_to_another(): void
    {
        [, $rivalOwner, $rivalProject] = $this->rival();
        $rivalProject->update(['budget_amount' => '123456789.00']);
        $this->rates([$this->rcc->id => '5200']);

        $this->actingAs($rivalOwner);
        $this->get(route('project-setup.budget', $this->project))->assertNotFound();
        $this->post(route('project-setup.budget.rates', $this->project), ['rates' => [$this->rcc->id => '1'], 'noted_on' => '2026-11-05'])->assertNotFound();
        $this->post(route('project-setup.budget.swap', $this->project), ['boq_item_id' => $this->rcc->id, 'rate' => '1', 'reason' => 'x'])->assertNotFound();
        $this->get(route('project-setup.budget', $rivalProject))->assertOk()->assertDontSee('Brick masonry')->assertDontSee('9,30,000');

        $this->assertSame(1, BoqCurrentRate::withoutGlobalScopes()->count());
    }

    public function test_the_nav_shows_the_budget_only_to_those_who_may_see_it(): void
    {
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertSee(route('project-setup.budget', $this->project), false);
        $this->actingAs($this->engineer)->get(route('boq.index', $this->project))->assertDontSee(route('project-setup.budget', $this->project), false)
            ->assertSee(route('project-setup.history', $this->project), false);
    }
}
