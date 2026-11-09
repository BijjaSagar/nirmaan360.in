<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Worker;
use Illuminate\Support\Str;

class AttendanceApiTest extends ApiTestCase
{
    private function worker(Organization $organization, string $name = 'Dattu'): Worker
    {
        return Worker::create(['organization_id' => $organization->id, 'name' => $name, 'trade' => 'Mason', 'daily_wage' => 950]);
    }

    public function test_marks_are_saved_and_read_back_by_date(): void
    {
        $site = $this->site();
        $worker = $this->worker($site->organization);
        $url = self::API."/projects/{$site->project->id}/attendance";

        $this->postJson($url, ['date' => '2026-11-08', 'marks' => [['worker_id' => $worker->id, 'status' => 'overtime', 'ot_hours' => 2.5]]])->assertOk()
            ->assertJsonPath('date', '2026-11-08')
            ->assertJsonPath('data.0.status', 'overtime')
            ->assertJsonPath('data.0.ot_hours', '2.5')
            ->assertJsonPath('data.0.attendance_date', '2026-11-08');

        $this->getJson($url.'?date=2026-11-08')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.worker_id', $worker->id);
        $this->getJson($url.'?date=2026-11-07')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, AuditLog::where('action', 'created')->where('auditable_type', Attendance::class)->count());
    }

    public function test_a_repeated_client_uuid_changes_nothing_and_a_new_one_overwrites(): void
    {
        $site = $this->site();
        $worker = $this->worker($site->organization);
        $url = self::API."/projects/{$site->project->id}/attendance";
        $first = (string) Str::uuid();

        $this->postJson($url, ['date' => '2026-11-08', 'marks' => [['worker_id' => $worker->id, 'status' => 'present', 'client_uuid' => $first]]])->assertOk();
        $this->postJson($url, ['date' => '2026-11-08', 'marks' => [['worker_id' => $worker->id, 'status' => 'absent', 'client_uuid' => $first]]])->assertOk()
            ->assertJsonPath('data.0.status', 'present');
        $this->postJson($url, ['date' => '2026-11-08', 'marks' => [['worker_id' => $worker->id, 'status' => 'absent', 'client_uuid' => (string) Str::uuid()]]])->assertOk()
            ->assertJsonPath('data.0.status', 'absent')
            ->assertJsonPath('data.0.client_uuid', $first);

        $this->assertSame(1, Attendance::count());
        $this->assertSame(1, AuditLog::where('action', 'updated')->where('auditable_type', Attendance::class)->count());
    }

    public function test_the_web_register_can_be_saved_twice_for_a_date(): void
    {
        $site = $this->site();
        $worker = $this->worker($site->organization);
        $this->actingAs($site->user);

        foreach (['present', 'half'] as $status) {
            $this->post("/projects/{$site->project->id}/attendance", ['date' => '2026-11-08', 'attendance' => [$worker->id => ['status' => $status]]])
                ->assertRedirect();
        }

        $this->assertSame('half', Attendance::sole()->status);
    }

    public function test_a_worker_from_another_company_is_rejected(): void
    {
        $site = $this->site();
        $theirs = $this->worker($this->tenant('Other Co'));

        $this->postJson(self::API."/projects/{$site->project->id}/attendance", ['date' => '2026-11-08', 'marks' => [['worker_id' => $theirs->id, 'status' => 'present']]])
            ->assertUnprocessable()->assertJsonValidationErrors('marks.0.worker_id');
    }

    public function test_routes_need_their_permission(): void
    {
        $site = $this->site();
        $url = self::API."/projects/{$site->project->id}/attendance";

        $this->signIn($this->member($site->organization, 'qs'));
        $this->getJson($url)->assertForbidden();

        $this->signIn($this->member($site->organization, 'store'));
        $this->postJson($url, ['date' => '2026-11-08', 'marks' => []])->assertForbidden();
    }

    public function test_another_companys_attendance_is_a_404(): void
    {
        $site = $this->site();
        $worker = $this->worker($site->organization);
        $url = self::API."/projects/{$site->project->id}/attendance";
        $this->signInElsewhere();

        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['date' => '2026-11-08', 'marks' => [['worker_id' => $worker->id, 'status' => 'present']]])->assertNotFound();
    }
}
