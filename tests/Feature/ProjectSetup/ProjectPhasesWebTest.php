<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\CustomRole;
use App\Models\User;
use App\Services\ProjectService;

class ProjectPhasesWebTest extends ProjectSetupTestCase
{
    private function split(): void
    {
        app(ProjectService::class)->update($this->project, ['phases' => [
            ['name' => 'Foundation', 'amount' => '4000000'],
            ['name' => 'Slab', 'amount' => '6000000'],
            ['name' => 'Finishing', 'amount' => '10000000'],
        ]], $this->owner);
    }

    /** The website's form, as the edit page fills it in. */
    private function fields(array $overrides = []): array
    {
        $project = $this->project->refresh();

        return [
            'code' => $project->code, 'name' => $project->name, 'status' => $project->status, 'contract_value' => $project->contract_value,
            'retention_pct' => '5', 'tds_pct' => '2', 'gst_pct' => '18', 'advance_amount' => '0',
            'advance_recovery_pct' => '0', 'measurement_tolerance_pct' => '5',
            'deduction_basis' => 'gross', 'deduction_order' => 'retention,advance,material,tds',
            ...$overrides,
        ];
    }

    public function test_the_project_page_lists_the_phases_with_their_shares(): void
    {
        $this->split();

        $this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk()
            ->assertSeeInOrder(['Contract value by phase', 'Foundation', '20.00%', 'Slab', '30.00%', 'Finishing', '50.00%', 'Total', '100.00%']);
    }

    public function test_the_project_page_has_no_phases_section_for_one_figure(): void
    {
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk()->assertDontSee('Contract value by phase');
    }

    public function test_someone_who_cannot_see_money_does_not_see_the_phases(): void
    {
        $this->split();
        $role = CustomRole::create(['organization_id' => $this->org->id, 'name' => 'Site lead', 'permissions' => ['project.view'], 'created_by' => $this->owner->id]);
        $lead = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'viewer', 'custom_role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($lead)->get(route('projects.show', $this->project))->assertOk()
            ->assertDontSee('Contract value by phase')->assertDontSee('Foundation');
    }

    public function test_the_edit_form_shows_a_phased_value_as_their_total_and_read_only(): void
    {
        $this->split();

        $this->actingAs($this->pm)->get(route('projects.edit', $this->project))->assertOk()
            ->assertSee('value="20000000.00" class="input num" readonly', false)
            ->assertSee('The total of 3 phases.');

        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()
            ->assertDontSee('readonly', false);
    }

    public function test_saving_the_form_keeps_the_phases_and_their_total(): void
    {
        $this->split();

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->fields(['name' => 'Sargam Hotel Annexe']))
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.show', $this->project));

        $project = $this->project->refresh();
        $this->assertSame('Sargam Hotel Annexe', $project->name);
        $this->assertSame('20000000.00', $project->contract_value);
        $this->assertSame(3, $project->valuePhases()->count());
    }

    public function test_the_form_cannot_part_a_phased_value_from_its_phases(): void
    {
        $this->split();

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->fields(['contract_value' => '1']))
            ->assertSessionHasErrors('contract_value');

        $this->assertSame('20000000.00', $this->project->refresh()->contract_value);
    }

    public function test_a_project_of_one_figure_is_still_saved_as_before(): void
    {
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->fields(['contract_value' => '12500000', 'status' => 'on_hold']))
            ->assertSessionHasNoErrors();

        $project = $this->project->refresh();
        $this->assertSame('12500000.00', $project->contract_value);
        $this->assertSame('on_hold', $project->status);
    }

    public function test_the_new_texts_have_hindi_and_marathi(): void
    {
        $texts = [
            'Contract value by phase', 'Phase', 'Share', 'Amount', 'Total',
            'The total of :count phases. Change the phases in the field app to change it.',
            "This value is the total of the project's phases. Change the phases to change it.",
            'The phases add up to more than a project can hold.',
            'The contract value must equal the total of the phases.',
            'The end date cannot be before the start date.',
        ];

        foreach (['hi', 'mr'] as $locale) {
            foreach ($texts as $text) {
                $this->assertNotSame($text, __($text, [], $locale), "{$text} has no {$locale} text");
            }
        }
    }
}
