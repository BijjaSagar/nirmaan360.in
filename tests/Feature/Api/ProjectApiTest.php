<?php

namespace Tests\Feature\Api;

use App\Models\BoqSection;

class ProjectApiTest extends ApiTestCase
{
    public function test_projects_use_the_shared_shape_and_stay_in_the_tenant(): void
    {
        $site = $this->site();
        $site->project->update(['start_date' => '2026-07-01', 'location' => 'Baner']);
        $this->makeProject($this->tenant('Other Co'), ['name' => 'Theirs']);

        $this->getJson(self::API.'/projects')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $site->project->id)
            ->assertJsonPath('data.0.location', 'Baner')
            ->assertJsonPath('data.0.start_date', '2026-07-01')
            ->assertJsonPath('data.0.measurement_tolerance_pct', '5.00');
    }

    public function test_boq_has_section_and_ledger_columns(): void
    {
        $site = $this->site();
        $section = BoqSection::create(['organization_id' => $site->organization->id, 'project_id' => $site->project->id, 'name' => 'Superstructure']);
        $site->item->update(['boq_section_id' => $section->id]);
        $this->draftEntry($site->project, $site->item, $site->user);

        $this->getJson(self::API."/projects/{$site->project->id}/boq")->assertOk()
            ->assertJsonPath('data.0.section', 'Superstructure')
            ->assertJsonPath('data.0.qty', '100.000')
            ->assertJsonPath('data.0.rate', '5000.00')
            ->assertJsonPath('data.0.measured_qty', '50.000')
            ->assertJsonPath('data.0.approved_qty', '0.000')
            ->assertJsonPath('data.0.balance_qty', '50.000');
    }

    public function test_project_routes_need_their_permission(): void
    {
        $site = $this->site();
        $this->signIn($this->member($site->organization, 'no_such_role'));

        $this->getJson(self::API.'/projects')->assertForbidden();
        $this->getJson(self::API."/projects/{$site->project->id}/boq")->assertForbidden();
    }

    public function test_another_companys_boq_is_a_404(): void
    {
        $site = $this->site();
        $this->signInElsewhere();

        $this->getJson(self::API."/projects/{$site->project->id}/boq")->assertNotFound();
    }
}
