<?php

namespace Tests\Feature\SiteProgress;

use App\Models\FloorWorkPlan;
use App\Models\MepStageCell;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Models\ProjectFloor;
use App\Services\Access\TeamAccessService;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\MepTracker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SiteProgressWebTest extends SiteProgressTestCase
{
    private ProjectFloor $floor;

    private ProjectFloor $flat;

    private ProgressEntry $pending;

    private function populate(): void
    {
        $item = $this->item('100.000', null, self::RATE);
        $this->floor = $this->floor('2nd floor');
        $this->flat = $this->floor('Flat 201', $this->floor->id);
        app(FloorService::class)->setTarget($this->floor, $item, '60');
        app(FloorService::class)->setTarget($this->flat, $item, '40');
        $this->work($item, '25', $this->floor, ['remark' => 'Slab cast']);
        $this->pending = $this->work($item, '0', $this->floor, ['is_pending' => true, 'remark' => 'Outside passage wiring not done']);
        app(MepTracker::class)->update($this->project, ['project_floor_id' => $this->floor->id, 'trade' => 'electrical', 'stage' => 'wiring', 'status' => 'pending', 'remark' => 'Passage'], $this->owner);
        app(MepTracker::class)->update($this->project, ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'], $this->owner);
        $this->project->update(['start_date' => now()->subDays(30), 'planned_days' => 120, 'deadline_date' => now()->addDays(90)]);
    }

    /** @return array<string, string> Every screen that takes a floor or a project. */
    private function screens(): array
    {
        return [
            'overview' => route('site-progress.index', $this->project),
            'entries' => route('site-progress.entries', $this->project),
            'floors' => route('site-progress.floors.index', $this->project),
            'floor' => route('site-progress.floors.show', $this->floor),
            'flat' => route('site-progress.floors.show', $this->flat),
            'pending' => route('site-progress.pending', $this->project),
            'week' => route('site-progress.week', $this->project),
            'stages' => route('site-stages.index', $this->project),
            'electrical' => route('site-stages.index', [$this->project, 'trade' => 'electrical']),
            'checklist' => route('site-stages.checklist', $this->floor),
            'checklist-paint' => route('site-stages.checklist', [$this->floor, 'for' => 'paint']),
        ];
    }

    public function test_every_screen_renders_for_every_role_and_none_shows_money(): void
    {
        $this->populate();

        // Accounts, the project manager and the owner hold money.view. The engineer does not. None may see a rate here.
        foreach (['owner', 'pm', 'qs', 'accounts', 'engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($role === 'owner' ? $this->owner : $this->member($this->organization, $role));

            foreach ($this->screens() as $name => $url) {
                $response = $this->get($url);
                $response->assertOk();
                $this->assertNoMoney($response->getContent());
            }
        }
    }

    public function test_the_screens_show_the_work(): void
    {
        $this->populate();
        $this->actingAs($this->owner);

        $this->get(route('site-progress.index', $this->project))
            ->assertSee('Site progress')
            ->assertSee('RCC M25 in slab')
            ->assertSee('Outside passage wiring not done', false)
            ->assertSee('Time and work')
            ->assertSee('Needs attention');

        $this->get(route('site-progress.floors.show', $this->floor))->assertSee('2nd floor')->assertSee('Outside passage wiring not done');
        $this->get(route('site-progress.pending', $this->project))->assertSee('Outside passage wiring not done');
        $this->get(route('site-stages.checklist', $this->floor))->assertSee('Passage')->assertSee('pending on 2nd floor');
    }

    public function test_the_project_menu_lists_the_module(): void
    {
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->get(route('site-progress.index', $this->project))->assertSee('Stage tracker')->assertSee(route('site-stages.index', $this->project), false);
    }

    public function test_a_viewer_can_look_but_not_change(): void
    {
        $this->populate();
        $this->actingAs($this->member($this->organization, 'viewer'));

        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => 1, 'qty_done' => 1])->assertForbidden();
        $this->post(route('site-progress.entries.correct', $this->pending), ['qty_done' => 1, 'remark' => 'x'])->assertForbidden();
        $this->post(route('site-progress.entries.resolve', $this->pending))->assertForbidden();
        $this->post(route('site-stages.update', $this->project), [])->assertForbidden();
        $this->post(route('site-progress.floors.store', $this->project), ['name' => 'Terrace'])->assertForbidden();
        $this->patch(route('site-progress.schedule', $this->project), ['planned_days' => 10])->assertForbidden();

        $this->get(route('site-progress.index', $this->project))->assertOk()->assertDontSee('Add work done');
    }

    public function test_a_site_engineer_enters_work_and_stages_but_does_not_manage_floors(): void
    {
        $this->populate();
        $item = $this->item('10.000');
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id, 'qty_done' => '2'])->assertRedirect();
        $this->post(route('site-stages.update', $this->project), ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'bathroom_bed', 'status' => 'done'])->assertRedirect();

        $this->post(route('site-progress.floors.store', $this->project), ['name' => 'Terrace'])->assertForbidden();
        $this->patch(route('site-progress.floors.update', $this->floor), ['name' => 'Renamed'])->assertForbidden();
        $this->delete(route('site-progress.floors.destroy', $this->flat))->assertForbidden();
        $this->put(route('site-progress.floors.target', $this->floor), ['boq_item_id' => $item->id, 'planned_qty' => 5])->assertForbidden();
        $this->post(route('site-progress.floors.split', $this->project), ['floor_ids' => [$this->floor->id], 'all_items' => 1])->assertForbidden();
        $this->patch(route('site-progress.schedule', $this->project), ['planned_days' => 10])->assertForbidden();

        $this->get(route('site-progress.floors.index', $this->project))->assertOk()->assertDontSee('Add a floor or flat');
    }

    public function test_a_company_on_a_plan_without_site_control_is_refused(): void
    {
        $free = $this->tenant('Free Builders', 'free');
        $project = $this->makeProject($free);
        $this->actingAs($this->member($free, 'owner'));

        $this->get(route('site-progress.index', $project))->assertForbidden();
        $this->get(route('site-stages.index', $project))->assertForbidden();
    }

    public function test_another_company_reaches_nothing(): void
    {
        $this->populate();
        $other = $this->stranger();
        $this->actingAs($other->owner);

        foreach ($this->screens() as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->post(route('site-progress.entries.correct', $this->pending), ['qty_done' => 1, 'remark' => 'x'])->assertNotFound();
        $this->post(route('site-progress.entries.resolve', $this->pending))->assertNotFound();
        $this->patch(route('site-progress.floors.update', $this->floor), ['name' => 'Mine now'])->assertNotFound();
        $this->delete(route('site-progress.floors.destroy', $this->floor))->assertNotFound();
        $this->put(route('site-progress.floors.target', $this->floor), ['boq_item_id' => 1, 'planned_qty' => 5])->assertNotFound();
        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => 1, 'qty_done' => 1])->assertNotFound();
        $this->post(route('site-stages.update', $this->project), [])->assertNotFound();
        $this->assertSame('2nd floor', $this->floor->fresh()->name);
    }

    public function test_a_stage_update_cannot_name_another_companys_floor(): void
    {
        $this->populate();
        $other = $this->stranger();
        $theirFloor = $this->floor('Theirs', null, $other->project);

        $this->actingAs($this->owner);
        $this->post(route('site-stages.update', $this->project), ['project_floor_id' => $theirFloor->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'])
            ->assertSessionHasErrors('project_floor_id');

        $this->assertSame(0, MepStageCell::where('project_floor_id', $theirFloor->id)->count());
    }

    public function test_a_person_limited_to_other_projects_cannot_open_this_one(): void
    {
        $this->populate();
        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$second->id]);
        $this->actingAs($limited->refresh());

        foreach ($this->screens() as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->post(route('site-progress.entries.resolve', $this->pending))->assertNotFound();
        $this->get(route('site-progress.index', $second))->assertOk();
    }

    public function test_quick_entry_saves_work_with_photos_and_remembers_the_floor(): void
    {
        $this->populate();
        $item = $this->item('10.000');
        $this->actingAs($this->member($this->organization, 'engineer'));
        $uuid = (string) Str::uuid();

        $this->from(route('site-progress.index', $this->project))->post(route('site-progress.entries.store', $this->project), [
            'client_uuid' => $uuid, 'boq_item_id' => $item->id, 'project_floor_id' => $this->flat->id, 'qty_done' => '4.5', 'remark' => 'Done till passage',
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertRedirect(route('site-progress.index', $this->project))->assertSessionHas('success');

        $entry = ProgressEntry::where('client_uuid', $uuid)->firstOrFail();
        $this->assertSame('4.500', $entry->qty_done);
        $this->assertSame($this->flat->id, $entry->project_floor_id);
        $this->assertSame(2, ProgressPhoto::where('progress_entry_id', $entry->id)->count());

        // Pressing save twice (a slow network) changes nothing and does not store the photos again.
        $this->post(route('site-progress.entries.store', $this->project), [
            'client_uuid' => $uuid, 'boq_item_id' => $item->id, 'project_floor_id' => $this->flat->id, 'qty_done' => '4.5',
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertRedirect();
        $this->assertSame(1, ProgressEntry::where('client_uuid', $uuid)->count());
        $this->assertSame(2, ProgressPhoto::where('progress_entry_id', $entry->id)->count());

        $this->get(route('site-progress.index', $this->project))->assertSee('value="'.$this->flat->id.'" selected', false);
    }

    public function test_quick_entry_checks_the_input(): void
    {
        $this->populate();
        $this->actingAs($this->owner);
        $item = $this->item();
        $foreign = $this->item('5.000', $this->makeProject($this->organization, ['name' => 'Second site']));

        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id])->assertSessionHasErrors('qty_done');
        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $foreign->id, 'qty_done' => 1])->assertSessionHasErrors('boq_item_id');
        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id, 'qty_done' => -3])->assertSessionHasErrors('qty_done');
        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id, 'qty_done' => 1, 'photos' => [UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id, 'qty_done' => '', 'is_pending' => 1])->assertSessionHasErrors('remark');

        $this->assertSame(2, ProgressEntry::count(), 'Only the two entries from populate()');
    }

    public function test_pending_can_be_saved_without_a_quantity(): void
    {
        $item = $this->item();
        $this->actingAs($this->owner);

        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id, 'is_pending' => 1, 'remark' => 'Terrace railing left'])->assertSessionHasNoErrors();

        $this->assertTrue(ProgressEntry::firstOrFail()->is_pending);
    }

    public function test_going_over_the_boq_quantity_shows_a_warning_but_saves(): void
    {
        $item = $this->item('10.000');
        $this->actingAs($this->owner);

        $this->post(route('site-progress.entries.store', $this->project), ['boq_item_id' => $item->id, 'qty_done' => 12])
            ->assertSessionHas('site_progress_warning');

        $this->assertSame(1, ProgressEntry::count());
    }

    public function test_a_correction_is_a_new_entry_and_shows_both(): void
    {
        $item = $this->item('100.000');
        $entry = $this->work($item, '60');
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->post(route('site-progress.entries.correct', $entry), ['qty_done' => '50', 'remark' => 'Counted a wall twice'])->assertRedirect()->assertSessionHas('success');
        $this->post(route('site-progress.entries.correct', $entry), ['qty_done' => '50'])->assertSessionHasErrors('remark');

        $this->assertSame(2, ProgressEntry::count());
        $this->assertSame('60.000', $entry->fresh()->qty_done);
        $this->get(route('site-progress.entries', $this->project))->assertSee('Counted a wall twice')->assertSee('Corrected')->assertSee('Correction');
    }

    public function test_there_is_no_route_that_edits_or_deletes_an_entry(): void
    {
        $this->populate();
        $this->actingAs($this->owner);

        $this->assertContains($this->patch('/progress/entries/'.$this->pending->id, ['qty_done' => 99])->getStatusCode(), [404, 405]);
        $this->assertContains($this->delete('/progress/entries/'.$this->pending->id)->getStatusCode(), [404, 405]);
        $this->put('/projects/'.$this->project->id.'/progress/entries', [])->assertStatus(405);
        $this->assertSame('0.000', $this->pending->fresh()->qty_done);
    }

    public function test_finishing_a_pending_item_takes_it_off_the_list(): void
    {
        $this->populate();
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->get(route('site-progress.pending', $this->project))->assertSee('Outside passage wiring not done');
        $this->post(route('site-progress.entries.resolve', $this->pending), ['remark' => 'Passage wired and tested'])->assertRedirect();

        $this->get(route('site-progress.pending', $this->project))->assertDontSee('Outside passage wiring not done')->assertSee('Nothing pending');
        $this->get(route('site-progress.entries', $this->project))->assertSee('Passage wired and tested')->assertSee('Finished later');
        $this->post(route('site-progress.entries.resolve', $this->pending))->assertSessionHasErrors('entry');
    }

    public function test_the_project_manager_sets_up_floors_targets_and_the_time_frame(): void
    {
        $item = $this->item('90.000');
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->post(route('site-progress.floors.store', $this->project), ['name' => 'Ground floor'])->assertRedirect()->assertSessionHas('success');
        $this->post(route('site-progress.floors.store', $this->project), ['name' => 'Ground floor'])->assertSessionHasErrors('name');
        $ground = ProjectFloor::where('name', 'Ground floor')->firstOrFail();
        $this->post(route('site-progress.floors.store', $this->project), ['name' => 'Flat G1', 'parent_id' => $ground->id])->assertSessionHasNoErrors();
        $this->post(route('site-progress.floors.store', $this->project), ['name' => 'Deep', 'parent_id' => ProjectFloor::where('name', 'Flat G1')->value('id')])->assertSessionHasErrors('parent_id');
        $first = ProjectFloor::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => '1st floor']);

        $this->post(route('site-progress.floors.split', $this->project), ['all_items' => 1, 'floor_ids' => [$ground->id, $first->id]])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(['45.000', '45.000'], FloorWorkPlan::orderBy('id')->pluck('planned_qty')->all());

        $this->put(route('site-progress.floors.target', $ground), ['boq_item_id' => $item->id, 'planned_qty' => '30.5'])->assertRedirect();
        $this->assertSame('30.500', FloorWorkPlan::where('project_floor_id', $ground->id)->value('planned_qty'));

        $this->patch(route('site-progress.floors.update', $first), ['name' => 'First floor'])->assertRedirect();
        $this->assertSame('First floor', $first->fresh()->name);

        $this->delete(route('site-progress.floors.destroy', $first))->assertRedirect(route('site-progress.floors.index', $this->project));
        $this->assertNull(ProjectFloor::find($first->id));

        $this->patch(route('site-progress.schedule', $this->project), ['start_date' => '2026-09-02', 'planned_days' => 180, 'deadline_date' => '2027-02-28'])->assertRedirect();
        $this->assertSame(180, $this->project->fresh()->planned_days);
        $this->patch(route('site-progress.schedule', $this->project), ['start_date' => '2026-09-02', 'planned_days' => 0])->assertSessionHasErrors('planned_days');
        $this->patch(route('site-progress.schedule', $this->project), ['start_date' => '2026-09-02', 'planned_days' => 10, 'deadline_date' => '2026-08-01'])->assertSessionHasErrors('deadline_date');
    }

    public function test_a_floor_with_work_on_it_cannot_be_removed(): void
    {
        $this->populate();
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->delete(route('site-progress.floors.destroy', $this->floor))->assertSessionHasErrors('floor');
        $this->assertNotNull($this->floor->fresh());
    }

    public function test_a_stage_update_warns_when_an_earlier_stage_is_pending(): void
    {
        $this->populate();
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->post(route('site-stages.update', $this->project), ['project_floor_id' => $this->floor->id, 'trade' => 'electrical', 'stage' => 'boards', 'status' => 'done', 'client_uuid' => (string) Str::uuid()])
            ->assertRedirect()
            ->assertSessionHas('site_progress_warning', fn ($messages) => str_contains($messages[0], 'Wiring is still pending on 2nd floor'));

        $this->assertSame('done', MepStageCell::where('trade', 'electrical')->where('stage', 'boards')->value('status'));
        $this->get(route('site-stages.index', [$this->project, 'trade' => 'electrical']))->assertOk();
    }

    public function test_a_stage_update_takes_a_remark_and_photos_and_checks_them(): void
    {
        $this->populate();
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->post(route('site-stages.update', $this->project), [
            'project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'drainage', 'status' => 'pending', 'remark' => 'Outlet not connected',
            'photos' => [UploadedFile::fake()->image('outlet.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $cell = MepStageCell::where('stage', 'drainage')->firstOrFail();
        $this->assertSame('Outlet not connected', $cell->remark);
        $this->assertSame(1, $cell->photos()->count());

        $this->post(route('site-stages.update', $this->project), ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'drainage', 'status' => 'pending'])->assertSessionHasErrors('remark');
        $this->post(route('site-stages.update', $this->project), ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'drainage', 'status' => 'na'])->assertSessionHasErrors('status');
        $this->post(route('site-stages.update', $this->project), ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'wiring', 'status' => 'done'])->assertSessionHasErrors('stage');
    }

    public function test_photos_are_private_to_the_company_and_the_project(): void
    {
        $this->populate();
        $entry = $this->work($this->item(), '3');
        $this->actingAs($this->owner);
        $this->post(route('site-progress.entries.store', $this->project), [
            'boq_item_id' => $entry->boq_item_id, 'qty_done' => 1, 'photos' => [UploadedFile::fake()->image('wall.jpg')],
        ])->assertRedirect();
        $photo = ProgressPhoto::firstOrFail();

        $this->get(route('site-progress.photos.show', $photo))->assertOk();
        $this->assertTrue(Storage::exists($photo->path));

        auth()->logout();
        $this->get(route('site-progress.photos.show', $photo))->assertRedirect(route('login'));

        $this->actingAs($this->stranger()->owner);
        $this->get(route('site-progress.photos.show', $photo))->assertNotFound();

        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $limited = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($limited, true, [$second->id]);
        $this->actingAs($limited->refresh());
        $this->get(route('site-progress.photos.show', $photo))->assertNotFound();
    }

    public function test_the_weekly_screen_moves_between_weeks(): void
    {
        $this->populate();
        $this->actingAs($this->owner);

        $this->get(route('site-progress.week', [$this->project, 'date' => '2026-01-07']))->assertOk()->assertSee('05 Jan – 11 Jan 2026')->assertSee('No work was recorded this week.');
        $this->get(route('site-progress.week', [$this->project, 'date' => 'not-a-date']))->assertSessionHasErrors('date');
    }

    public function test_a_second_page_of_entries_and_filters_work(): void
    {
        $item = $this->item();
        $other = $this->item();
        $floor = $this->floor();
        foreach (range(1, 30) as $i) {
            $this->work($item, '1', $floor);
        }
        $this->work($other, '2');
        $this->actingAs($this->owner);

        $this->get(route('site-progress.entries', [$this->project, 'page' => 2]))->assertOk();
        $this->get(route('site-progress.entries', [$this->project, 'boq_item_id' => $other->id]))->assertOk()->assertSee('2.000');
        $this->get(route('site-progress.entries', [$this->project, 'project_floor_id' => $floor->id]))->assertOk();
    }

    public function test_the_overview_flags_a_project_that_is_behind(): void
    {
        $this->project->update(['start_date' => now()->subDays(90), 'planned_days' => 180]);
        $this->work($this->item('100.000'), '35');
        $this->actingAs($this->owner);

        $this->get(route('site-progress.index', $this->project))->assertSee('Behind schedule: 50% of the time is used but only 35% of the work is done (15 points behind).');
    }

    public function test_every_screen_has_an_empty_state_for_a_project_with_nothing_in_it(): void
    {
        $this->actingAs($this->owner);

        foreach ([route('site-progress.index', $this->project), route('site-progress.floors.index', $this->project), route('site-progress.pending', $this->project), route('site-stages.index', $this->project), route('site-progress.week', $this->project), route('site-progress.entries', $this->project)] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
