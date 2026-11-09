<?php

namespace Tests\Feature\Planning;

use App\Models\AuditLog;
use App\Models\PlanActivity;
use App\Models\PlanBoqLink;
use App\Models\PlanDependency;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanningHttpTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpPlan();
    }

    private function url(string $suffix = ''): string
    {
        return '/projects/'.$this->project->id.'/planning'.$suffix;
    }

    public function test_every_page_loads_with_data(): void
    {
        $node = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Structure']);
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-02', '2026-11-08', ['wbs_node_id' => $node->id, 'name' => 'Slab <b>L1</b>']);
        $this->link($activity, $item, '100');
        $this->approved($item, '40', '2026-11-04');

        $this->get($this->url())->assertOk()->assertSee('Planned vs actual')->assertSee('40.00%')->assertSee('S-curve');

        $this->get($this->url('/schedule'))->assertOk()->assertSee('planGantt')->assertSee('Slab &lt;b&gt;L1&lt;/b&gt;', false)->assertDontSee('Slab <b>L1</b>', false);

        $this->get($this->url('/budget'))->assertOk()->assertSee('Budget against actual');

        $this->get('/planning/activities/'.$activity->id)->assertOk()->assertSee('Linked BOQ items')->assertSee('40.000');
    }

    public function test_pages_load_with_no_data_yet(): void
    {
        $this->get($this->url())->assertOk()->assertSee('Nothing to chart yet');
        $this->get($this->url('/schedule'))->assertOk()->assertSee('No activities yet');
        $this->get($this->url('/budget'))->assertOk();
    }

    public function test_pages_load_in_hindi_and_marathi(): void
    {
        foreach (['hi' => 'नियोजित बनाम वास्तविक', 'mr' => 'नियोजित विरुद्ध प्रत्यक्ष'] as $locale => $text) {
            $this->owner->update(['locale' => $locale]);
            $this->actingAs($this->owner->refresh())->get($this->url())->assertOk()->assertSee($text);
        }
    }

    public function test_the_sidebar_links_to_planning(): void
    {
        $this->get('/projects/'.$this->project->id)->assertOk()->assertSee(route('planning.index', $this->project));
        $this->assertNotEmpty(array_filter(Modules::nav('project', $this->organization), fn ($item) => $item['route'] === 'planning.index'));
    }

    public function test_plans_without_planning_are_refused(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $project = $this->makeProject($free);

        $this->actingAs($this->member($free, 'owner'))->get('/projects/'.$project->id.'/planning')->assertForbidden();
    }

    public function test_every_listed_plan_includes_planning(): void
    {
        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $organization = $this->tenant('Co '.$plan, $plan);
            $project = $this->makeProject($organization);

            $this->actingAs($this->member($organization, 'owner'))->get('/projects/'.$project->id.'/planning')->assertOk();
        }
    }

    public function test_viewers_can_read_but_not_change(): void
    {
        $activity = $this->activity('2026-11-02', '2026-11-08');
        $viewer = $this->member($this->organization, 'viewer');

        $this->actingAs($viewer);
        $this->get($this->url())->assertOk()->assertDontSee('Save baseline');
        $this->get($this->url('/schedule'))->assertOk();
        // The budget is total spend, which needs money.overview; a viewer never holds it.
        $this->get($this->url('/budget'))->assertForbidden();
        $this->get('/planning/activities/'.$activity->id)->assertOk()->assertDontSee('Delete activity');

        $this->post($this->url('/baseline'))->assertForbidden();
        $this->post($this->url('/activities'), ['name' => 'X', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-02'])->assertForbidden();
        $this->patch('/planning/activities/'.$activity->id, ['name' => 'X', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-02'])->assertForbidden();
        $this->delete('/planning/activities/'.$activity->id)->assertForbidden();
        $this->post($this->url('/budget'), ['head' => 'material', 'amount' => '1'])->assertForbidden();
        $this->post($this->url('/alerts/scan'))->assertForbidden();
        $this->post($this->url('/import'))->assertForbidden();
    }

    public function test_a_role_without_the_permission_cannot_open_planning(): void
    {
        $this->actingAs($this->member($this->organization, 'store'))->get($this->url())->assertForbidden();
    }

    public function test_a_project_manager_can_plan(): void
    {
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->post($this->url('/activities'), ['name' => 'Footings', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-05', 'client_uuid' => (string) Str::uuid()])
            ->assertRedirect();

        $this->assertSame('Footings', PlanActivity::sole()->name);
        $this->assertTrue(AuditLog::where('auditable_type', (new PlanActivity)->getMorphClass())->where('action', 'created')->exists());
    }

    public function test_another_companys_records_are_not_found(): void
    {
        $activity = $this->activity('2026-11-02', '2026-11-08');
        $node = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Structure']);
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $link = $this->link($activity, $item, '10');
        $other = $this->activity('2026-11-09', '2026-11-10');
        [$dependency] = $this->schedule()->addDependency($other, ['predecessor_id' => $activity->id, 'type' => 'FS']);

        $outsiderOrg = $this->tenant('Rival Infra');
        $this->actingAs($this->member($outsiderOrg, 'owner'));

        $this->get($this->url())->assertNotFound();
        $this->get($this->url('/schedule'))->assertNotFound();
        $this->get($this->url('/budget'))->assertNotFound();
        $this->post($this->url('/baseline'))->assertNotFound();
        $this->get('/planning/activities/'.$activity->id)->assertNotFound();
        $this->patch('/planning/activities/'.$activity->id, ['name' => 'X', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-02'])->assertNotFound();
        $this->delete('/planning/activities/'.$activity->id)->assertNotFound();
        $this->delete('/planning/wbs/'.$node->id)->assertNotFound();
        $this->delete('/planning/boq-links/'.$link->id)->assertNotFound();
        $this->delete('/planning/dependencies/'.$dependency->id)->assertNotFound();

        $this->assertSame(2, PlanActivity::withoutGlobalScopes()->count());
        $this->assertSame(1, PlanBoqLink::withoutGlobalScopes()->count());
    }

    public function test_ids_from_another_company_are_rejected_in_forms(): void
    {
        $here = $this->activity('2026-11-02', '2026-11-08');

        $outsiderOrg = $this->tenant('Rival Infra');
        $this->actingAs($this->member($outsiderOrg, 'owner'));
        $outsiderProject = $this->makeProject($outsiderOrg);
        $outsiderItem = $this->makeBoqItem($outsiderProject);
        $outsiderActivity = $this->activity('2026-11-02', '2026-11-08', [], $outsiderProject);
        $outsiderNode = $this->schedule()->createNode($outsiderProject, ['code' => '9', 'name' => 'Theirs']);

        $this->actingAs($this->owner);

        $this->post('/planning/activities/'.$here->id.'/boq-links', ['boq_item_id' => $outsiderItem->id])->assertSessionHasErrors('boq_item_id');
        $this->post('/planning/activities/'.$here->id.'/dependencies', ['predecessor_id' => $outsiderActivity->id, 'type' => 'FS'])->assertSessionHasErrors('predecessor_id');
        $this->post($this->url('/activities'), ['name' => 'X', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-02', 'wbs_node_id' => $outsiderNode->id])->assertSessionHasErrors('wbs_node_id');

        $this->assertSame(0, PlanBoqLink::where('plan_activity_id', $here->id)->count());
    }

    public function test_a_loop_is_refused_with_a_message(): void
    {
        $a = $this->activity('2026-11-01', '2026-11-03', ['code' => 'A']);
        $b = $this->activity('2026-11-04', '2026-11-06', ['code' => 'B']);
        $this->post('/planning/activities/'.$b->id.'/dependencies', ['predecessor_id' => $a->id, 'type' => 'FS'])->assertSessionHasNoErrors();

        $this->post('/planning/activities/'.$a->id.'/dependencies', ['predecessor_id' => $b->id, 'type' => 'FS'])->assertSessionHasErrors('predecessor_id');

        $this->assertSame(1, PlanDependency::count());
    }

    public function test_finish_before_start_is_refused_in_the_form(): void
    {
        $this->post($this->url('/activities'), ['name' => 'X', 'planned_start' => '2026-11-05', 'planned_end' => '2026-11-01'])->assertSessionHasErrors('planned_end');
    }

    public function test_a_form_milestone_needs_no_finish_date(): void
    {
        $this->post($this->url('/activities'), ['name' => 'Handover', 'planned_start' => '2026-11-05', 'is_milestone' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-05', PlanActivity::sole()->planned_end->toDateString());
    }

    public function test_budget_forms_work(): void
    {
        $this->post($this->url('/budget'), ['head' => 'material', 'amount' => '12345.67'])->assertSessionHasNoErrors();
        $this->post($this->url('/budget'), ['head' => 'nonsense', 'amount' => '1'])->assertSessionHasErrors('head');
        $this->post($this->url('/costs'), ['head' => 'material', 'entry_date' => '2026-11-01', 'amount' => '100', 'client_uuid' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->get($this->url('/budget'))->assertOk()->assertSee('₹12,345.67');
    }

    public function test_the_import_form_takes_a_file(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Project xmlns="http://schemas.microsoft.com/project"><Tasks>'
            .'<Task><UID>1</UID><Name>Piling</Name><OutlineLevel>1</OutlineLevel><Start>2026-11-02T08:00:00</Start><Finish>2026-11-06T17:00:00</Finish></Task>'
            .'</Tasks></Project>';

        $this->post($this->url('/import'), ['file' => UploadedFile::fake()->createWithContent('plan.xml', $xml)])
            ->assertRedirect($this->url('/schedule'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Piling', PlanActivity::sole()->name);
    }

    public function test_the_import_form_rejects_other_files_and_bad_xml(): void
    {
        $this->post($this->url('/import'), ['file' => UploadedFile::fake()->createWithContent('plan.xer', 'ERMHDR')])->assertSessionHasErrors('file');
        $this->post($this->url('/import'), ['file' => UploadedFile::fake()->createWithContent('plan.xml', 'nope')])->assertSessionHasErrors('file');

        $this->assertSame(0, PlanActivity::count());
    }

    public function test_the_delay_check_button_reports_and_notifies(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-01-01', '2026-01-10');
        $this->link($activity, $item, '100');

        $this->post($this->url('/alerts/scan'))->assertSessionHas('success');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_the_notification_shows_in_the_bell(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-01-01', '2026-01-10', ['code' => 'LATE1', 'name' => 'Piling']);
        $this->link($activity, $item, '100');

        $this->post($this->url('/alerts/scan'));

        $this->get('/dashboard')->assertOk()->assertSee('LATE1 Piling');
    }
}
