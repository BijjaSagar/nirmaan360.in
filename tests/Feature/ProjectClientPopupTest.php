<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class ProjectClientPopupTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function projectForm(array $overrides = []): array
    {
        return [
            'code' => 'TWR-A', 'name' => 'Tower A', 'contract_value' => 1000000,
            'retention_pct' => 5, 'tds_pct' => 2, 'gst_pct' => 18, 'advance_amount' => 0, 'advance_recovery_pct' => 0,
            'measurement_tolerance_pct' => 5, 'deduction_basis' => 'gross',
            'deduction_order' => array_key_first(config('buildos.deduction_orders')),
            ...$overrides,
        ];
    }

    public function test_owner_can_open_the_popup_on_new_and_edit_project_pages(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));

        $this->get('/projects/create')->assertOk()->assertSee('Add client')->assertSee('clientPopup()', false);
        $this->get(route('projects.edit', $this->makeProject($organization)))->assertOk()->assertSee('Add client');
    }

    public function test_project_manager_without_contact_rights_is_not_offered_it(): void
    {
        $this->actingAs($this->member($this->tenant(), 'pm'));

        $this->get('/projects/create')->assertOk()->assertDontSee('Add client')->assertDontSee('clientPopup()', false);
        $this->postJson('/parties', ['type' => 'client', 'name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(0, Party::count());
    }

    public function test_plan_without_contacts_is_not_offered_it(): void
    {
        $this->actingAs($this->member($this->tenant('Small Co', 'free'), 'owner'));

        $this->get('/projects/create')->assertOk()->assertDontSee('Add client');
        $this->postJson('/parties', ['type' => 'client', 'name' => 'Sneaky'])->assertForbidden();
    }

    public function test_popup_saves_the_client_and_the_project_can_use_it(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));

        $response = $this->postJson('/parties', ['type' => 'client', 'name' => 'Kalyani Developers', 'phone' => '9876543210', 'email' => '', 'gstin' => ''])
            ->assertCreated()
            ->assertJsonPath('name', 'Kalyani Developers');

        $client = Party::findOrFail($response->json('id'));
        $this->assertSame('client', $client->type);
        $this->assertSame($organization->id, $client->organization_id);

        $this->post('/projects', $this->projectForm(['client_id' => $client->id]))->assertSessionHasNoErrors();
        $this->assertSame($client->id, Project::where('code', 'TWR-A')->firstOrFail()->client_id);
        $this->get('/projects/create')->assertSee('Kalyani Developers');
    }

    public function test_bad_details_come_back_against_each_field(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        $this->postJson('/parties', ['type' => 'client', 'name' => '', 'email' => 'not-an-email', 'gstin' => 'SHORT'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'gstin']);

        $this->assertSame(0, Party::count());
    }

    public function test_contacts_page_form_still_redirects(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        $this->from('/parties')->post('/parties', ['type' => 'vendor', 'name' => 'Sharma Steel'])->assertRedirect('/parties')->assertSessionHas('success');
    }

    public function test_a_client_stays_inside_its_own_company(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));
        $id = $this->postJson('/parties', ['type' => 'client', 'name' => 'Private Client Co'])->assertCreated()->json('id');

        auth()->logout();
        $this->actingAs($this->member($this->tenant('Second Co'), 'owner'));

        $this->get('/projects/create')->assertDontSee('Private Client Co');
        $this->post('/projects', $this->projectForm(['client_id' => $id]))->assertSessionHasErrors('client_id');
    }
}
