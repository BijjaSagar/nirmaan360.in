<?php

namespace Tests\Feature\Qhs;

use App\Models\SafetyIncident;
use App\Models\ToolboxTalk;
use App\Services\Qhs\SafetyService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class SafetyTest extends FieldOpsTestCase
{
    private function report(object $w, array $data = []): SafetyIncident
    {
        return app(SafetyService::class)->report($w->project, [
            'kind' => 'incident', 'severity' => 'medium', 'injury' => 'first_aid', 'occurred_at' => '2026-11-02 10:30', 'description' => 'Labourer cut his hand on binding wire', ...$data,
        ], $w->owner);
    }

    public function test_incidents_and_near_misses_share_one_numbering_per_project(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $a = $this->report($w);
        $b = $this->report($w, ['kind' => 'near_miss', 'severity' => 'high', 'description' => 'Brick fell from the scaffold, nobody below']);

        $this->assertSame('INC-'.$w->project->code.'-001', $a->number);
        $this->assertSame('NM-'.$w->project->code.'-002', $b->number);
        $this->assertSame('open', $a->status);
        $this->assertSame('2026-11-02 10:30', $a->occurred_at->format('Y-m-d H:i'));
    }

    public function test_a_near_miss_never_records_an_injury(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $near = $this->report($w, ['kind' => 'near_miss', 'injury' => 'fatal', 'lost_days' => 30]);

        $this->assertSame('none', $near->injury);
        $this->assertSame(0, $near->lost_days);
    }

    public function test_days_lost_are_kept_only_for_a_lost_time_injury(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->assertSame(0, $this->report($w, ['injury' => 'first_aid', 'lost_days' => 5])->lost_days);
        $this->assertSame(5, $this->report($w, ['injury' => 'lost_time', 'lost_days' => 5])->lost_days);
    }

    public function test_the_same_client_uuid_reports_once(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $payload = ['kind' => 'incident', 'severity' => 'low', 'occurred_at' => '2026-11-02 10:30', 'description' => 'Slip', 'client_uuid' => (string) Str::uuid()];

        $this->post(route('incidents.store', $w->project), $payload)->assertRedirect();
        $this->post(route('incidents.store', $w->project), $payload)->assertRedirect();

        $this->assertSame(1, SafetyIncident::count());
    }

    public function test_a_report_cannot_be_dated_in_the_future_and_needs_a_description(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->post(route('incidents.store', $w->project), ['kind' => 'incident', 'severity' => 'low', 'occurred_at' => '2999-01-01 10:00'])
            ->assertSessionHasErrors(['occurred_at', 'description']);
        $this->post(route('incidents.store', $w->project), ['kind' => 'accident', 'severity' => 'extreme', 'occurred_at' => '2026-11-02 10:00', 'description' => 'x', 'injury' => 'bruise'])
            ->assertSessionHasErrors(['kind', 'severity', 'injury']);
    }

    public function test_closing_needs_the_root_cause_and_the_corrective_action_and_then_locks(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $incident = $this->report($w);

        $this->patch(route('incidents.update', $incident), ['status' => 'closed'])->assertSessionHasErrors(['root_cause', 'corrective_action']);
        $this->patch(route('incidents.update', $incident), ['status' => 'investigating'])->assertSessionHasNoErrors();
        $this->patch(route('incidents.update', $incident), ['status' => 'closed', 'root_cause' => 'Bare binding wire ends', 'corrective_action' => 'Wire ends turned in; gloves issued'])->assertSessionHasNoErrors();

        $incident->refresh();
        $this->assertSame('closed', $incident->status);
        $this->assertSame($w->owner->id, $incident->closed_by);
        $this->assertNotNull($incident->closed_at);

        $this->patch(route('incidents.update', $incident), ['root_cause' => 'Edited'])->assertStatus(409);
        $this->patch(route('incidents.update', $incident), ['status' => 'open'])->assertStatus(409);
        $this->assertSame('Bare binding wire ends', $incident->refresh()->root_cause);
    }

    public function test_it_cannot_go_back_from_investigating_to_open(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $incident = $this->report($w);
        $this->patch(route('incidents.update', $incident), ['status' => 'investigating']);

        $this->patch(route('incidents.update', $incident), ['status' => 'open'])->assertSessionHasErrors('status');
    }

    public function test_toolbox_talk_headcount_comes_from_the_names(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->post(route('incidents.toolbox.store', $w->project), ['talk_date' => '2026-11-02', 'topic' => 'Working at height', 'conducted_by' => 'Ravi Patil', 'attendees' => "Anil\n\n Sunil \r\nMahesh", 'attendee_count' => 99, 'duration_minutes' => 15])->assertRedirect();
        $this->post(route('incidents.toolbox.store', $w->project), ['talk_date' => '2026-11-03', 'topic' => 'Lifting', 'conducted_by' => 'Ravi Patil', 'attendee_count' => 24])->assertRedirect();

        $talks = ToolboxTalk::orderBy('talk_date')->get();
        $this->assertSame(3, $talks[0]->attendee_count);
        $this->assertSame(['Anil', 'Sunil', 'Mahesh'], $talks[0]->attendeeNames());
        $this->assertSame(24, $talks[1]->attendee_count);

        $this->get(route('incidents.toolbox.index', $w->project))->assertOk()->assertSee('Working at height')->assertSee('Anil, Sunil, Mahesh');
    }

    public function test_the_same_client_uuid_holds_one_talk(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $payload = ['talk_date' => '2026-11-02', 'topic' => 'Fire safety', 'conducted_by' => 'Ravi', 'client_uuid' => (string) Str::uuid()];

        $this->post(route('incidents.toolbox.store', $w->project), $payload);
        $this->post(route('incidents.toolbox.store', $w->project), $payload);

        $this->assertSame(1, ToolboxTalk::count());
    }

    public function test_the_safety_page_counts_and_links_to_ppe_checks(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $this->report($w, ['injury' => 'lost_time', 'lost_days' => 3]);
        $this->report($w, ['kind' => 'near_miss']);

        $this->get(route('incidents.index', $w->project))->assertOk()->assertSee('PPE checks')->assertSee('Days lost');
        $this->get(route('incidents.index', [$w->project, 'kind' => 'near_miss']))->assertOk()->assertSee('NM-')->assertDontSee('INC-');
    }

    public function test_who_can_report(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $incident = $this->report($w);
        $payload = ['kind' => 'near_miss', 'severity' => 'low', 'occurred_at' => '2026-11-02 10:30', 'description' => 'x'];

        foreach (['pm' => true, 'engineer' => true, 'store' => false, 'viewer' => false, 'qs' => false] as $role => $canWrite) {
            Auth::forgetUser();
            $this->actingAs($this->member($w->organization, $role));

            $this->get(route('incidents.index', $w->project))->assertOk();
            $this->get(route('incidents.show', $incident))->assertOk();
            $this->get(route('incidents.toolbox.index', $w->project))->assertOk();
            $this->post(route('incidents.store', $w->project), $payload)->assertStatus($canWrite ? 302 : 403);
            $this->post(route('incidents.toolbox.store', $w->project), ['talk_date' => '2026-11-02', 'topic' => 'x', 'conducted_by' => 'y'])->assertStatus($canWrite ? 302 : 403);
            $this->patch(route('incidents.update', $incident), ['status' => 'investigating'])->assertStatus($canWrite ? 302 : 403);
        }

        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'accounts'))->get(route('incidents.index', $w->project))->assertForbidden();
    }

    public function test_another_companys_reports_are_out_of_reach(): void
    {
        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirIncident = $this->report($theirs);

        Auth::forgetUser();
        $this->actingAs($mine->owner);

        $this->get(route('incidents.show', $theirIncident))->assertNotFound();
        $this->patch(route('incidents.update', $theirIncident), ['status' => 'investigating'])->assertNotFound();
        $this->get(route('incidents.index', $theirs->project))->assertNotFound();
        $this->post(route('incidents.toolbox.store', $theirs->project), ['talk_date' => '2026-11-02', 'topic' => 'x', 'conducted_by' => 'y'])->assertNotFound();
        $this->assertSame(0, ToolboxTalk::withoutGlobalScopes()->count());
    }
}
