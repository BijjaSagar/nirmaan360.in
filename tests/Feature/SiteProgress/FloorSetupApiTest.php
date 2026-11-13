<?php

namespace Tests\Feature\SiteProgress;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\FloorWorkPlan;
use App\Models\MepStageCell;
use App\Models\MepStageEvent;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\MepTracker;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Setting up and planning floors and segments from the field app: add, rename, move, plan per BOQ line, share lines
 * equally, the time frame and the weekly view. Removing a floor is for the company owner only.
 */
class FloorSetupApiTest extends SiteProgressTestCase
{
    private const API = '/api/v1';

    private function url(string $path): string
    {
        return self::API."/projects/{$this->project->id}/$path";
    }

    /** Someone on a custom role with the given permissions; the base role is what it was copied from. */
    private function holding(array $permissions, string $baseRole = 'engineer'): User
    {
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = $this->member($this->organization, $baseRole);
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function signIn(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function audited(string $action, ProjectFloor|FloorWorkPlan $model): bool
    {
        return AuditLog::withoutGlobalScopes()->where('action', $action)->where('auditable_type', $model->getMorphClass())->where('auditable_id', $model->id)->exists();
    }

    // Adding floors and segments

    public function test_a_project_manager_adds_a_floor_and_a_segment_under_it(): void
    {
        $this->signIn($pm = $this->member($this->organization, 'pm'));
        $uuid = (string) Str::uuid();

        $floor = $this->postJson($this->url('floors'), ['name' => '  Ground floor ', 'client_uuid' => $uuid])->assertCreated()
            ->assertJsonPath('data.name', 'Ground floor')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.client_uuid', $uuid)
            ->assertJsonPath('data.project_id', $this->project->id)
            ->assertJsonPath('data.sort', 1);

        $flat = $this->postJson($this->url('floors'), ['name' => 'Flat A', 'parent_id' => $floor->json('data.id'), 'client_uuid' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.parent_id', $floor->json('data.id'));

        $this->assertEqualsCanonicalizing(['id', 'client_uuid', 'project_id', 'name', 'parent_id', 'sort'], array_keys($flat->json('data')));
        $this->getJson($this->url('floors'))->assertOk()
            ->assertJsonPath('data.0.name', 'Ground floor')
            ->assertJsonPath('data.0.children.0.name', 'Flat A');

        $created = ProjectFloor::findOrFail($floor->json('data.id'));
        $this->assertSame($this->organization->id, $created->organization_id);
        $this->assertTrue($this->audited('created', $created));
        $this->assertSame($pm->id, AuditLog::withoutGlobalScopes()->where('auditable_id', $created->id)->where('auditable_type', $created->getMorphClass())->value('user_id'));
    }

    public function test_a_floor_is_made_once_however_often_it_is_sent(): void
    {
        $this->signIn($this->member($this->organization, 'qs'));
        $payload = ['name' => '1st floor', 'client_uuid' => (string) Str::uuid()];

        $first = $this->postJson($this->url('floors'), $payload)->assertCreated();
        // The same request again, and even one renamed on the way, returns the floor already made.
        $this->postJson($this->url('floors'), $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson($this->url('floors'), [...$payload, 'name' => 'Something else'])->assertOk()->assertJsonPath('data.name', '1st floor');

        $this->assertSame(1, ProjectFloor::count());
        // Without a client_uuid a second floor of that name is refused, not doubled.
        $this->postJson($this->url('floors'), ['name' => '1st floor'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_repeated_uuid_never_reaches_a_floor_of_another_project_or_company(): void
    {
        $uuid = (string) Str::uuid();
        $this->signIn($this->member($this->organization, 'pm'));
        $this->postJson($this->url('floors'), ['name' => 'Ground', 'client_uuid' => $uuid])->assertCreated();

        // The same uuid in another project of the same company is an error, not that project's floor.
        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $this->postJson(self::API."/projects/{$second->id}/floors", ['name' => 'Ground', 'client_uuid' => $uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        // Someone limited to the second project cannot learn of the first one's floor through the uuid either.
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$second->id]);
        $this->signIn($limited->refresh());
        $this->postJson(self::API."/projects/{$second->id}/floors", ['name' => 'Ground', 'client_uuid' => $uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        // Another company has its own uuids: the same value makes its own floor.
        $stranger = $this->stranger();
        $this->signIn($stranger->owner);
        $this->postJson(self::API."/projects/{$stranger->project->id}/floors", ['name' => 'Ground', 'client_uuid' => $uuid])->assertCreated()
            ->assertJsonPath('data.project_id', $stranger->project->id);

        $this->assertSame(1, ProjectFloor::withoutGlobalScopes()->where('project_id', $this->project->id)->count());
        $this->assertSame(0, ProjectFloor::withoutGlobalScopes()->where('project_id', $second->id)->count());
    }

    public function test_adding_a_floor_follows_the_web_rules(): void
    {
        $ground = $this->floor('Ground');
        $flat = $this->floor('Flat 1', $ground->id);
        $other = $this->floor('Elsewhere', null, $this->makeProject($this->organization, ['name' => 'Second site']));
        $this->signIn($this->member($this->organization, 'pm'));

        $this->postJson($this->url('floors'), [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->url('floors'), ['name' => str_repeat('x', 81)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->url('floors'), ['name' => 'ground'])->assertUnprocessable()->assertJsonValidationErrors('name');
        // A segment sits under a floor, never under another segment, and only in the same project.
        $this->postJson($this->url('floors'), ['name' => 'Room', 'parent_id' => $flat->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($this->url('floors'), ['name' => 'Room', 'parent_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($this->url('floors'), ['name' => 'Flat 1', 'parent_id' => $ground->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($this->url('floors'), ['name' => 'Ok', 'client_uuid' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        // The same name under another floor is fine: two floors may each have a "Passage".
        $first = $this->floor('First');
        $this->postJson($this->url('floors'), ['name' => 'Flat 1', 'parent_id' => $first->id])->assertCreated();
    }

    // Renaming and moving

    public function test_a_floor_is_renamed_and_moved_with_only_the_fields_sent(): void
    {
        $ground = $this->floor('Ground');
        $first = $this->floor('First');
        $flat = $this->floor('Flat 1', $ground->id);
        $this->signIn($this->member($this->organization, 'qs'));

        // A name alone keeps the segment under its floor.
        $this->patchJson(self::API."/floors/{$flat->id}", ['name' => 'Flat 101'])->assertOk()
            ->assertJsonPath('data.name', 'Flat 101')
            ->assertJsonPath('data.parent_id', $ground->id);
        // A parent alone moves it and keeps the name.
        $this->patchJson(self::API."/floors/{$flat->id}", ['parent_id' => $first->id])->assertOk()
            ->assertJsonPath('data.name', 'Flat 101')
            ->assertJsonPath('data.parent_id', $first->id);
        // parent_id null makes it a floor of its own.
        $this->patchJson(self::API."/floors/{$flat->id}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);

        $this->assertTrue($this->audited('updated', $flat));
    }

    public function test_renaming_follows_the_web_rules(): void
    {
        $ground = $this->floor('Ground');
        $first = $this->floor('First');
        $flat = $this->floor('Flat 1', $ground->id);
        $this->signIn($this->member($this->organization, 'pm'));

        $this->patchJson(self::API."/floors/{$first->id}", ['name' => 'GROUND'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::API."/floors/{$first->id}", ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson(self::API."/floors/{$first->id}", ['parent_id' => $first->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        // A floor with segments under it cannot become a segment itself.
        $this->patchJson(self::API."/floors/{$ground->id}", ['parent_id' => $first->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson(self::API."/floors/{$first->id}", ['parent_id' => $flat->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertSame('First', $first->fresh()->name);
        $this->assertNull($ground->fresh()->parent_id);
    }

    // Planned quantity per floor and BOQ line

    public function test_the_planned_quantity_is_set_changed_and_cleared(): void
    {
        $item = $this->item('100.000');
        $floor = $this->floor('Ground');
        $this->work($item, '10', $floor);
        $this->signIn($this->member($this->organization, 'qs'));

        $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '40'])->assertOk()
            ->assertJsonPath('data.id', $floor->id)
            ->assertJsonPath('data.percent', '25.00')
            ->assertJsonPath('data.lines.0.planned_qty', '40.000')
            ->assertJsonPath('data.lines.0.done_qty', '10.000')
            ->assertJsonPath('data.lines.0.balance_qty', '30.000');

        $plan = FloorWorkPlan::firstOrFail();
        $this->assertTrue($this->audited('created', $plan));

        // Sending it again changes nothing; a new figure replaces the old one.
        $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '40'])->assertOk();
        $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '20.5'])->assertOk()
            ->assertJsonPath('data.lines.0.planned_qty', '20.500');
        $this->assertSame(1, FloorWorkPlan::count());

        // Zero removes the target.
        $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => 0])->assertOk()
            ->assertJsonPath('data.lines.0.planned_qty', null);
        $this->assertSame(0, FloorWorkPlan::count());
    }

    public function test_the_planned_quantity_is_validated(): void
    {
        $item = $this->item();
        $floor = $this->floor();
        $foreign = $this->item('5.000', $this->makeProject($this->organization, ['name' => 'Second site']));
        $this->signIn($this->member($this->organization, 'pm'));
        $url = self::API."/floors/{$floor->id}/target";

        $this->putJson($url, ['planned_qty' => '1'])->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');
        $this->putJson($url, ['boq_item_id' => $foreign->id, 'planned_qty' => '1'])->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');
        $this->putJson($url, ['boq_item_id' => $item->id])->assertUnprocessable()->assertJsonValidationErrors('planned_qty');
        $this->putJson($url, ['boq_item_id' => $item->id, 'planned_qty' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('planned_qty');
        $this->putJson($url, ['boq_item_id' => $item->id, 'planned_qty' => '1.2345'])->assertUnprocessable()->assertJsonValidationErrors('planned_qty');
        $this->putJson($url, ['boq_item_id' => $item->id, 'planned_qty' => '100000000'])->assertUnprocessable()->assertJsonValidationErrors('planned_qty');

        $this->assertSame(0, FloorWorkPlan::count());
    }

    // Sharing BOQ lines across floors

    public function test_chosen_lines_are_shared_equally_and_the_last_floor_takes_the_remainder(): void
    {
        $item = $this->item('10.000');
        $untouched = $this->item('7.000');
        $ground = $this->floor('Ground');
        $first = $this->floor('First');
        $second = $this->floor('Second');
        app(FloorService::class)->setTarget($ground, $item, '9');
        $this->signIn($this->member($this->organization, 'pm'));
        $payload = ['boq_item_ids' => [$item->id], 'floor_ids' => [$first->id, $second->id, $ground->id]];

        $this->postJson($this->url('floors/split'), $payload)->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonCount(3, 'data.floors');

        $shares = FloorWorkPlan::where('boq_item_id', $item->id)->orderBy('project_floor_id')->pluck('planned_qty', 'project_floor_id')->all();
        $this->assertSame([$ground->id => '3.333', $first->id => '3.333', $second->id => '3.334'], $shares);
        $this->assertSame(0, FloorWorkPlan::where('boq_item_id', $untouched->id)->count());

        // Sending it again gives the same shares, not twice as many.
        $this->postJson($this->url('floors/split'), $payload)->assertOk();
        $this->assertSame($shares, FloorWorkPlan::where('boq_item_id', $item->id)->orderBy('project_floor_id')->pluck('planned_qty', 'project_floor_id')->all());

        // All lines at once.
        $this->postJson($this->url('floors/split'), ['all_items' => true, 'floor_ids' => [$first->id]])->assertOk()->assertJsonPath('data.count', 2);
        $this->assertSame(2, FloorWorkPlan::count());
    }

    public function test_sharing_is_validated(): void
    {
        $item = $this->item();
        $floor = $this->floor();
        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $foreignFloor = $this->floor('Elsewhere', null, $second);
        $foreignItem = $this->item('5.000', $second);
        $this->signIn($this->member($this->organization, 'pm'));

        $this->postJson($this->url('floors/split'), ['boq_item_ids' => [$item->id]])->assertUnprocessable()->assertJsonValidationErrors('floor_ids');
        $this->postJson($this->url('floors/split'), ['boq_item_ids' => [$item->id], 'floor_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('floor_ids');
        $this->postJson($this->url('floors/split'), ['boq_item_ids' => [$item->id], 'floor_ids' => [$foreignFloor->id]])->assertUnprocessable()->assertJsonValidationErrors('floor_ids.0');
        $this->postJson($this->url('floors/split'), ['boq_item_ids' => [$foreignItem->id], 'floor_ids' => [$floor->id]])->assertUnprocessable()->assertJsonValidationErrors('boq_item_ids.0');
        $this->postJson($this->url('floors/split'), ['floor_ids' => [$floor->id]])->assertUnprocessable()->assertJsonValidationErrors('floor_ids');

        $this->assertSame(0, FloorWorkPlan::count());
    }

    // Removing a floor: owner only

    public function test_the_owner_removes_an_empty_floor_with_its_plans(): void
    {
        $item = $this->item();
        $floor = $this->floor('Spare');
        app(FloorService::class)->setTarget($floor, $item, '5');
        // A stage never touched does not count as work.
        MepStageCell::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'project_floor_id' => $floor->id,
            'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'not_started',
        ]);
        $this->signIn($this->owner);

        $this->deleteJson(self::API."/floors/{$floor->id}")->assertOk()
            ->assertJsonPath('data.id', $floor->id)
            ->assertJsonPath('data.deleted', true);

        $this->assertNull(ProjectFloor::find($floor->id));
        $this->assertSame(0, FloorWorkPlan::count());
        $this->assertSame(0, MepStageCell::count());
        $this->assertTrue($this->audited('deleted', $floor));
        // Gone means gone: a retry finds nothing.
        $this->deleteJson(self::API."/floors/{$floor->id}")->assertNotFound();
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function notTheOwner(): array
    {
        return [
            'admin' => ['admin', null],
            'project manager' => ['pm', null],
            'estimator' => ['qs', null],
            'custom role copied from the owner, holding everything' => ['owner', 'custom'],
            'custom role holding progress.manage' => ['pm', 'custom'],
        ];
    }

    #[DataProvider('notTheOwner')]
    public function test_nobody_but_the_owner_removes_a_floor(string $role, ?string $custom): void
    {
        $floor = $this->floor('Spare');
        $user = $custom === null
            ? $this->member($this->organization, $role)
            : $this->holding($role === 'owner' ? ['*'] : ['progress.view', 'progress.manage'], $role);
        $this->signIn($user);

        $this->deleteJson(self::API."/floors/{$floor->id}")->assertForbidden()
            ->assertJsonPath('message', 'Only the company owner can remove a floor.');

        $this->assertNotNull(ProjectFloor::find($floor->id));
    }

    public function test_owner_means_the_built_in_role_and_a_switched_off_owner_removes_nothing(): void
    {
        $owner = $this->member($this->organization, 'owner');
        $this->assertTrue($owner->isOwner());
        $this->assertFalse($this->holding(['*'], 'owner')->isOwner());
        $this->assertFalse($this->member($this->organization, 'admin')->isOwner());

        $floor = $this->floor('Spare');
        $owner->forceFill(['is_active' => false])->save();
        $this->signIn($owner->fresh());

        $this->deleteJson(self::API."/floors/{$floor->id}")->assertForbidden();
        $this->assertNotNull(ProjectFloor::find($floor->id));
    }

    public function test_a_floor_with_work_or_segments_is_never_removed(): void
    {
        $item = $this->item();
        $worked = $this->floor('Worked');
        $this->work($item, '1', $worked);
        $staged = $this->floor('Staged');
        app(MepTracker::class)->update($this->project, ['project_floor_id' => $staged->id, 'trade' => 'electrical', 'stage' => 'wiring', 'status' => 'in_progress'], $this->owner);
        $pendingOnly = $this->floor('Pending only');
        $this->work($item, '0', $pendingOnly, ['is_pending' => true, 'remark' => 'Shuttering left']);
        $parent = $this->floor('Parent');
        $this->floor('Flat', $parent->id);
        // Marked and then set back: the cell reads not started, but its two stage events are history.
        $reset = $this->floor('Reset');
        foreach (['in_progress', 'not_started'] as $status) {
            app(MepTracker::class)->update($this->project, ['project_floor_id' => $reset->id, 'trade' => 'electrical', 'stage' => 'wiring', 'status' => $status], $this->owner);
        }
        $resetCell = MepStageCell::where('project_floor_id', $reset->id)->firstOrFail();
        $this->assertSame('not_started', $resetCell->status);
        $this->assertSame(2, MepStageEvent::where('mep_stage_cell_id', $resetCell->id)->count());
        $this->signIn($this->owner);

        foreach ([$worked, $staged, $pendingOnly, $parent, $reset] as $floor) {
            $this->deleteJson(self::API."/floors/{$floor->id}")->assertUnprocessable()->assertJsonValidationErrors('floor');
            $this->assertNotNull(ProjectFloor::find($floor->id), "{$floor->name} was removed");
        }

        $this->deleteJson(self::API."/floors/{$parent->id}")->assertJsonPath('errors.floor.0', 'Remove the flats under this floor first.');
        $this->deleteJson(self::API."/floors/{$worked->id}")->assertJsonPath('errors.floor.0', 'Work has been recorded on this floor, so it cannot be removed.');
        $this->deleteJson(self::API."/floors/{$reset->id}")->assertJsonPath('errors.floor.0', 'Work has been recorded on this floor, so it cannot be removed.');
        $this->assertNotNull(MepStageCell::find($resetCell->id));
        $this->assertSame(2, MepStageEvent::where('mep_stage_cell_id', $resetCell->id)->count());
    }

    // Time frame

    public function test_the_time_frame_is_set_and_comes_back_in_the_summary(): void
    {
        $this->signIn($this->member($this->organization, 'qs'));
        $start = now()->subDays(10)->toDateString();
        $deadline = now()->addDays(80)->toDateString();

        $this->patchJson($this->url('progress/time-frame'), ['start_date' => $start, 'planned_days' => 90, 'deadline_date' => $deadline])->assertOk()
            ->assertJsonPath('data.start_date', $start)
            ->assertJsonPath('data.planned_days', 90)
            ->assertJsonPath('data.deadline_date', $deadline)
            ->assertJsonPath('data.elapsed_days', 10);

        $this->assertSame(90, (int) $this->project->fresh()->planned_days);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('auditable_type', $this->project->getMorphClass())->where('auditable_id', $this->project->id)->where('action', 'updated')->exists());

        // Null clears a field.
        $this->patchJson($this->url('progress/time-frame'), ['start_date' => $start, 'planned_days' => null, 'deadline_date' => null])->assertOk()
            ->assertJsonPath('data.planned_days', null);
    }

    public function test_the_time_frame_is_validated(): void
    {
        $this->signIn($this->member($this->organization, 'pm'));
        $url = $this->url('progress/time-frame');
        $full = ['start_date' => '2026-12-01', 'planned_days' => 30, 'deadline_date' => '2027-01-01'];

        $this->patchJson($url, [...$full, 'deadline_date' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('deadline_date');
        $this->patchJson($url, [...$full, 'planned_days' => 0])->assertUnprocessable()->assertJsonValidationErrors('planned_days');
        $this->patchJson($url, [...$full, 'start_date' => 'soon'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        // All three are sent, as the web form does, so a forgotten field never clears silently.
        $this->patchJson($url, ['planned_days' => 30])->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'deadline_date']);
    }

    // Weekly view

    public function test_the_week_shows_what_was_done_where_and_what_is_pending(): void
    {
        $item = $this->item('100.000');
        $other = $this->item('50.000');
        $ground = $this->floor('Ground');
        $flat = $this->floor('Flat 1', $ground->id);
        $monday = now()->startOfWeek();
        $this->work($item, '12', $flat, ['work_date' => $monday->toDateString()]);
        $this->work($item, '3', null, ['work_date' => $monday->toDateString()]);
        $this->work($other, '5', $ground, ['work_date' => $monday->copy()->subWeek()->toDateString()]);
        $this->work($other, '0', $ground, ['is_pending' => true, 'remark' => 'Curing']);
        $this->signIn($this->member($this->organization, 'viewer'));

        $response = $this->getJson($this->url('progress/week'))->assertOk()
            ->assertJsonPath('data.start', $monday->toDateString())
            ->assertJsonPath('data.end', $monday->copy()->addDays(6)->toDateString())
            ->assertJsonPath('data.is_current', true)
            ->assertJsonCount(1, 'data.done')
            ->assertJsonPath('data.done.0.id', $item->id)
            ->assertJsonPath('data.done.0.week_qty', '15.000')
            ->assertJsonPath('data.done.0.floors.0.name', 'Ground › Flat 1')
            ->assertJsonPath('data.done.0.floors.0.qty', '12.000')
            ->assertJsonPath('data.pending.0.remark', 'Curing')
            ->assertJsonPath('data.pending.0.floor_name', 'Ground')
            ->assertJsonCount(2, 'data.next')
            ->assertJsonPath('data.next_more', 0)
            ->assertJsonPath('data.not_started', 0);

        $this->assertNoMoneyKeys($response->json());

        $this->getJson($this->url('progress/week?date='.$monday->copy()->subWeek()->toDateString()))->assertOk()
            ->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.done.0.id', $other->id)
            ->assertJsonPath('data.done.0.week_qty', '5.000');

        $this->getJson($this->url('progress/week?date=nope'))->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    // Who may do what

    public function test_no_answer_carries_money(): void
    {
        $item = $this->item('100.000');
        $floor = $this->floor('Ground');
        $this->work($item, '10', $floor);
        // The project manager holds money.view; this module still shows none.
        $this->signIn($this->member($this->organization, 'pm'));

        $answers = [
            $this->postJson($this->url('floors'), ['name' => 'First'])->assertCreated(),
            $this->patchJson(self::API."/floors/{$floor->id}", ['name' => 'Ground floor'])->assertOk(),
            $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '40'])->assertOk(),
            $this->postJson($this->url('floors/split'), ['all_items' => true, 'floor_ids' => [$floor->id]])->assertOk(),
            $this->patchJson($this->url('progress/time-frame'), ['start_date' => null, 'planned_days' => 30, 'deadline_date' => null])->assertOk(),
            $this->getJson($this->url('progress/week'))->assertOk(),
        ];

        foreach ($answers as $answer) {
            $this->assertNoMoneyKeys($answer->json());
        }
    }

    public function test_site_staff_and_viewers_cannot_set_up_floors(): void
    {
        $item = $this->item();
        $floor = $this->floor('Ground');

        foreach (['engineer', 'store', 'accounts', 'viewer'] as $role) {
            $this->signIn($this->member($this->organization, $role));

            $this->postJson($this->url('floors'), ['name' => 'New'])->assertForbidden();
            $this->postJson($this->url('floors/split'), ['all_items' => true, 'floor_ids' => [$floor->id]])->assertForbidden();
            $this->patchJson(self::API."/floors/{$floor->id}", ['name' => 'Renamed'])->assertForbidden();
            $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '1'])->assertForbidden();
            $this->deleteJson(self::API."/floors/{$floor->id}")->assertForbidden();
            $this->patchJson($this->url('progress/time-frame'), ['start_date' => null, 'planned_days' => 5, 'deadline_date' => null])->assertForbidden();
        }

        // Everyone who sees progress sees the week.
        $this->signIn($this->member($this->organization, 'engineer'));
        $this->getJson($this->url('progress/week'))->assertOk();

        $this->assertSame(1, ProjectFloor::count());
        $this->assertSame('Ground', $floor->fresh()->name);
        $this->assertSame(0, FloorWorkPlan::count());
        $this->assertNull($this->project->fresh()->planned_days);
    }

    public function test_a_custom_role_works_by_its_permissions(): void
    {
        $floor = $this->floor('Ground');

        $this->signIn($this->holding(['progress.view', 'progress.manage']));
        $this->postJson($this->url('floors'), ['name' => 'First'])->assertCreated();
        $this->patchJson(self::API."/floors/{$floor->id}", ['name' => 'G'])->assertOk();

        $this->signIn($this->holding(['progress.view', 'progress.create'], 'pm'));
        $this->postJson($this->url('floors'), ['name' => 'Second'])->assertForbidden();
        $this->getJson($this->url('progress/week'))->assertOk();
    }

    public function test_it_needs_a_signed_in_user_and_the_site_control_plan(): void
    {
        $floor = $this->floor();
        // floor() signs the owner in on the web guard; nobody is signed in for the requests below.
        $this->app['auth']->forgetGuards();
        $this->postJson($this->url('floors'), ['name' => 'X'])->assertUnauthorized();
        $this->deleteJson(self::API."/floors/{$floor->id}")->assertUnauthorized();
        $this->getJson($this->url('progress/week'))->assertUnauthorized();

        $free = $this->tenant('Free Builders', 'free');
        $project = $this->makeProject($free);
        $this->signIn($this->member($free, 'owner'));

        $this->postJson(self::API."/projects/{$project->id}/floors", ['name' => 'X'])->assertForbidden();
        $this->getJson(self::API."/projects/{$project->id}/progress/week")->assertForbidden();
        $this->assertSame(0, ProjectFloor::withoutGlobalScopes()->where('project_id', $project->id)->count());
    }

    public function test_another_company_reaches_nothing(): void
    {
        $item = $this->item();
        $floor = $this->floor('Ground');
        $stranger = $this->stranger();
        $theirItem = $this->item('5.000', $stranger->project);
        $this->signIn($stranger->owner);

        $this->postJson($this->url('floors'), ['name' => 'Mine now'])->assertNotFound();
        $this->postJson($this->url('floors/split'), ['all_items' => true, 'floor_ids' => [$floor->id]])->assertNotFound();
        $this->patchJson(self::API."/floors/{$floor->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '1'])->assertNotFound();
        $this->deleteJson(self::API."/floors/{$floor->id}")->assertNotFound();
        $this->patchJson($this->url('progress/time-frame'), ['start_date' => null, 'planned_days' => 5, 'deadline_date' => null])->assertNotFound();
        $this->getJson($this->url('progress/week'))->assertNotFound();

        // Their own project cannot point at our floors or lines either.
        $theirFloor = $this->floor('Theirs', null, $stranger->project);
        $this->signIn($stranger->owner);
        $this->postJson(self::API."/projects/{$stranger->project->id}/floors", ['name' => 'Flat', 'parent_id' => $floor->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson(self::API."/projects/{$stranger->project->id}/floors/split", ['boq_item_ids' => [$theirItem->id], 'floor_ids' => [$floor->id]])->assertUnprocessable()->assertJsonValidationErrors('floor_ids.0');
        $this->putJson(self::API."/floors/{$theirFloor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '1'])->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');

        $this->assertSame('Ground', $floor->fresh()->name);
        $this->assertSame(0, FloorWorkPlan::withoutGlobalScopes()->count());
        $this->assertNull($this->project->fresh()->planned_days);
    }

    public function test_a_person_limited_to_another_project_reaches_nothing_here(): void
    {
        $item = $this->item();
        $floor = $this->floor('Ground');
        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$second->id]);
        $this->signIn($limited->refresh());

        $this->postJson($this->url('floors'), ['name' => 'X'])->assertNotFound();
        $this->patchJson(self::API."/floors/{$floor->id}", ['name' => 'X'])->assertNotFound();
        $this->putJson(self::API."/floors/{$floor->id}/target", ['boq_item_id' => $item->id, 'planned_qty' => '1'])->assertNotFound();
        $this->postJson($this->url('floors/split'), ['all_items' => true, 'floor_ids' => [$floor->id]])->assertNotFound();
        $this->getJson($this->url('progress/week'))->assertNotFound();
        $this->postJson(self::API."/projects/{$second->id}/floors", ['name' => 'Ground'])->assertCreated();
        // A floor of the project they cannot reach is no parent in the one they can.
        $this->postJson(self::API."/projects/{$second->id}/floors", ['name' => 'Flat', 'parent_id' => $floor->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }
}
