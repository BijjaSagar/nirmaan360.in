<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\Project;

class ProjectFormTest extends ProjectSetupTestCase
{
    private function fields(array $overrides = []): array
    {
        return [
            'code' => 'NEW1', 'name' => 'Sargam Hotel Phase 2', 'contract_value' => '0',
            'retention_pct' => '5', 'tds_pct' => '2', 'gst_pct' => '18', 'advance_amount' => '0',
            'advance_recovery_pct' => '0', 'measurement_tolerance_pct' => '5',
            'deduction_basis' => 'gross', 'deduction_order' => 'retention,advance,material,tds',
            ...$overrides,
        ];
    }

    public function test_the_form_asks_for_size_time_and_budget(): void
    {
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()
            ->assertSee('name="plot_size"', false)->assertSee('name="built_up_area"', false)
            ->assertSee('name="planned_days"', false)->assertSee('name="deadline_date"', false)
            ->assertSee('name="budget_amount"', false)->assertSee('name="budget_margin_pct"', false)
            ->assertSee('name="boq_mode"', false);
    }

    public function test_a_project_is_created_with_its_size_time_and_budget(): void
    {
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields([
            'plot_size' => '30 × 80 ft', 'built_up_area' => '6,200 sq ft', 'planned_days' => '180',
            'deadline_date' => '2027-06-30', 'budget_amount' => '20000000', 'budget_margin_pct' => '5', 'boq_mode' => 'thumb_rule',
        ]))->assertSessionHasNoErrors();

        $project = Project::where('code', 'NEW1')->firstOrFail();

        $this->assertSame('30 × 80 ft', $project->plot_size);
        $this->assertSame('6,200 sq ft', $project->built_up_area);
        $this->assertSame(180, $project->planned_days);
        $this->assertSame('2027-06-30', $project->deadline_date->toDateString());
        $this->assertSame('20000000.00', $project->budget_amount);
        $this->assertSame('5.00', $project->budget_margin_pct);
        $this->assertSame('thumb_rule', $project->boq_mode);
    }

    public function test_every_new_field_is_optional(): void
    {
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields())->assertSessionHasNoErrors();

        $project = Project::where('code', 'NEW1')->firstOrFail();

        $this->assertNull($project->planned_days);
        $this->assertNull($project->budget_amount);
        $this->assertSame('5.00', $project->budget_margin_pct);
        $this->assertSame('detailed', $project->boq_mode);
    }

    public function test_the_new_fields_are_checked(): void
    {
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields([
            'planned_days' => '0', 'deadline_date' => 'soon', 'budget_amount' => '-5', 'budget_margin_pct' => '150', 'boq_mode' => 'rough',
        ]))->assertSessionHasErrors(['planned_days', 'deadline_date', 'budget_amount', 'budget_margin_pct', 'boq_mode']);

        $this->assertNull(Project::where('code', 'NEW1')->first());
    }

    public function test_a_project_is_updated_and_its_budget_can_be_cleared(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'UPD', 'budget_amount' => '5000000.00']);

        $this->actingAs($this->pm)->patch(route('projects.update', $project), $this->fields([
            'code' => 'UPD', 'status' => 'active', 'planned_days' => '240', 'budget_amount' => '', 'budget_margin_pct' => '10',
        ]))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(240, $project->planned_days);
        $this->assertNull($project->budget_amount);
        $this->assertSame('10.00', $project->budget_margin_pct);
    }

    public function test_the_budget_is_money_so_only_money_overview_sees_or_changes_it(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'SEC', 'budget_amount' => '5000000.00', 'budget_margin_pct' => '7.50']);
        $manager = $this->holding(['project.*', 'party.view']);

        $this->actingAs($manager)->get(route('projects.edit', $project))->assertOk()
            ->assertDontSee('name="budget_amount"', false)->assertDontSee('name="budget_margin_pct"', false)->assertSee('name="planned_days"', false);

        $this->actingAs($manager)->patch(route('projects.update', $project), $this->fields([
            'code' => 'SEC', 'status' => 'active', 'planned_days' => '90', 'budget_amount' => '1', 'budget_margin_pct' => '99',
        ]))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(90, $project->planned_days);
        $this->assertSame('5000000.00', $project->budget_amount);
        $this->assertSame('7.50', $project->budget_margin_pct);

        $this->actingAs($manager)->post(route('projects.store'), $this->fields(['budget_amount' => '123456']))->assertSessionHasNoErrors();
        $this->assertNull(Project::where('code', 'NEW1')->firstOrFail()->budget_amount);
    }

    public function test_the_boq_style_is_offered_only_on_plans_with_site_control(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $owner = $this->member($free, 'owner');

        $this->actingAs($owner)->get(route('projects.create'))->assertOk()->assertSee('name="plot_size"', false)->assertDontSee('name="boq_mode"', false);
    }
}
