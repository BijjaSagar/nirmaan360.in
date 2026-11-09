<?php

namespace Tests\Feature\Notify;

use App\Models\Attendance;
use App\Models\AttendanceSelfie;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Services\Notify\SelfieAttendanceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class SelfieAttendanceTest extends NotifyTestCase
{
    private Organization $organization;

    private Project $project;

    private Site $site;

    private Worker $worker;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();

        $this->organization = $this->tenant();
        $this->engineer = $this->member($this->organization);
        $this->project = $this->makeProject($this->organization);
        $this->site = Site::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Tower A', 'lat' => '18.5204000', 'lng' => '73.8567000', 'geofence_radius_m' => 200]);
        $this->worker = Worker::create(['organization_id' => $this->organization->id, 'name' => 'Dattu', 'trade' => 'Mason', 'daily_wage' => 950]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'site_id' => $this->site->id,
            'worker_id' => $this->worker->id,
            'lat' => 18.5205,
            'lng' => 73.8568,
            'accuracy_m' => 12,
            'consent' => true,
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 200, 200),
            ...$overrides,
        ];
    }

    private function url(?Project $project = null): string
    {
        return self::API.'/projects/'.($project ?? $this->project)->id.'/attendance/selfies';
    }

    private function postSelfie(array $overrides = [])
    {
        return $this->post($this->url(), $this->payload($overrides), ['Accept' => 'application/json']);
    }

    public function test_distance_is_measured_on_the_earth(): void
    {
        $service = app(SelfieAttendanceService::class);

        $this->assertSame(0, $service->distanceMeters(18.5204, 73.8567, 18.5204, 73.8567));
        // One degree of latitude is about 111.2 km.
        $this->assertEqualsWithDelta(111195, $service->distanceMeters(18.0, 73.0, 19.0, 73.0), 5);
    }

    public function test_a_selfie_inside_the_fence_marks_the_worker_present(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie()->assertCreated()
            ->assertJsonPath('data.worker_id', $this->worker->id)
            ->assertJsonPath('data.radius_m', 200)
            ->assertJsonPath('attendance.status', 'present');

        $attendance = Attendance::sole();
        $this->assertSame('selfie', $attendance->method);
        $this->assertSame($this->engineer->id, $attendance->marked_by);

        $selfie = AttendanceSelfie::sole();
        $this->assertLessThanOrEqual(200, $selfie->distance_m);
        Storage::assertExists($selfie->photo_path);
    }

    public function test_the_photo_is_kept_out_of_public_storage(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie()->assertCreated();

        $path = AttendanceSelfie::sole()->photo_path;
        $this->assertStringStartsWith('org-'.$this->organization->id.'/attendance-selfies/', $path);
        $this->assertSame('private', Storage::getVisibility($path));
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    public function test_a_selfie_outside_the_fence_is_refused_and_no_photo_is_kept(): void
    {
        Sanctum::actingAs($this->engineer);

        $response = $this->postSelfie(['lat' => 18.53, 'lng' => 73.87])->assertUnprocessable()->assertJsonValidationErrors('lat');

        $this->assertStringContainsString('m from Tower A', $response->json('errors.lat.0'));
        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, AttendanceSelfie::count());
        $this->assertSame([], Storage::allFiles());
    }

    public function test_a_refused_attempt_is_audited_for_later_review(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie(['lat' => 18.53, 'lng' => 73.87]);

        $log = AuditLog::where('action', 'attendance.selfie_outside_geofence')->sole();
        $this->assertSame($this->site->id, $log->new_values['site_id']);
    }

    public function test_a_location_vaguer_than_the_fence_is_refused(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie(['accuracy_m' => 500])->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->assertSame(0, Attendance::count());
    }

    public function test_a_site_with_no_location_cannot_be_used(): void
    {
        Sanctum::actingAs($this->engineer);
        $this->site->update(['lat' => null, 'lng' => null]);

        $this->postSelfie()->assertUnprocessable()->assertJsonValidationErrors('site_id');
    }

    public function test_consent_is_required(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie(['consent' => false])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->assertSame(0, AttendanceSelfie::count());
    }

    public function test_the_consent_note_shown_is_stored_with_the_selfie(): void
    {
        Sanctum::actingAs($this->engineer);

        $note = $this->getJson(self::API.'/attendance/selfie-consent')->assertOk()->json('data');
        $this->postSelfie()->assertCreated()->assertJsonPath('data.consent_version', $note['version']);

        $selfie = AttendanceSelfie::sole();
        $this->assertSame($note['text'], $selfie->consent_text);
        $this->assertNotNull($selfie->consent_at);
    }

    public function test_the_consent_note_comes_in_the_users_language(): void
    {
        $this->engineer->update(['locale' => 'mr']);
        Sanctum::actingAs($this->engineer);

        $text = $this->getJson(self::API.'/attendance/selfie-consent')->assertOk()->json('data.text');

        $this->assertStringContainsString('कामगार', $text);
    }

    public function test_only_images_are_accepted(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie(['selfie' => UploadedFile::fake()->create('page.html', 10, 'text/html')])
            ->assertUnprocessable()->assertJsonValidationErrors('selfie');
    }

    public function test_a_repeated_client_uuid_returns_the_original_and_stores_one_photo(): void
    {
        Sanctum::actingAs($this->engineer);
        $uuid = (string) Str::uuid();

        $id = $this->postSelfie(['client_uuid' => $uuid])->assertCreated()->json('data.id');
        $this->postSelfie(['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, AttendanceSelfie::count());
        $this->assertCount(1, Storage::allFiles());
    }

    public function test_a_second_selfie_the_same_day_is_refused_and_leaves_no_file(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie()->assertCreated();
        $this->postSelfie()->assertUnprocessable()->assertJsonValidationErrors('worker_id');

        $this->assertCount(1, Storage::allFiles());
    }

    public function test_it_keeps_a_half_day_already_marked_and_turns_absent_into_present(): void
    {
        Sanctum::actingAs($this->engineer);
        $other = Worker::create(['organization_id' => $this->organization->id, 'name' => 'Ganesh', 'trade' => 'Helper', 'daily_wage' => 700]);
        $date = now()->toDateString();

        foreach ([[$this->worker, 'half'], [$other, 'absent']] as [$worker, $status]) {
            Attendance::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'worker_id' => $worker->id, 'attendance_date' => $date, 'status' => $status]);
        }

        $this->postSelfie()->assertCreated()->assertJsonPath('attendance.status', 'half');
        $this->postSelfie(['worker_id' => $other->id])->assertCreated()->assertJsonPath('attendance.status', 'present');
        $this->assertSame(2, Attendance::count());
    }

    public function test_an_offline_photo_counts_for_the_day_it_was_taken(): void
    {
        Sanctum::actingAs($this->engineer);
        $taken = now()->subDays(3)->setTime(9, 0);

        $this->postSelfie(['captured_at' => $taken->toIso8601String()])->assertCreated()
            ->assertJsonPath('data.attendance_date', $taken->toDateString());
    }

    public function test_times_in_the_future_or_too_old_are_refused(): void
    {
        Sanctum::actingAs($this->engineer);

        $this->postSelfie(['captured_at' => now()->addHour()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('captured_at');
        $this->postSelfie(['captured_at' => now()->subDays(8)->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('captured_at');
    }

    public function test_a_role_without_attendance_manage_is_refused(): void
    {
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->postSelfie()->assertForbidden();
        $this->getJson(self::API.'/attendance/selfie-consent')->assertForbidden();
    }

    public function test_workers_and_sites_from_elsewhere_are_refused(): void
    {
        Sanctum::actingAs($this->engineer);
        $elsewhere = $this->tenant('Other Co');
        $foreignWorker = Worker::create(['organization_id' => $elsewhere->id, 'name' => 'X', 'trade' => 'Mason', 'daily_wage' => 1]);
        $foreignProject = $this->makeProject($elsewhere);
        $foreignSite = Site::create(['organization_id' => $elsewhere->id, 'project_id' => $foreignProject->id, 'name' => 'Far', 'lat' => '18.52', 'lng' => '73.85']);

        $this->postSelfie(['worker_id' => $foreignWorker->id])->assertUnprocessable()->assertJsonValidationErrors('worker_id');
        $this->postSelfie(['site_id' => $foreignSite->id])->assertUnprocessable()->assertJsonValidationErrors('site_id');
    }

    public function test_another_companys_project_cannot_be_used(): void
    {
        Sanctum::actingAs($this->member($this->tenant('Other Co'), 'owner'));

        $this->post($this->url(), $this->payload(), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_photo_can_only_be_opened_by_managers_in_the_same_company(): void
    {
        Sanctum::actingAs($this->engineer);
        $selfie = AttendanceSelfie::findOrFail($this->postSelfie()->json('data.id'));
        $url = self::API."/attendance-selfies/{$selfie->id}/photo";

        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        Sanctum::actingAs($this->member($this->organization, 'viewer'));
        $this->getJson($url)->assertForbidden();

        Sanctum::actingAs($this->member($this->tenant('Other Co'), 'owner'));
        $this->getJson($url)->assertNotFound();
    }

    public function test_the_web_screen_shows_the_consent_note_and_only_sites_with_a_location(): void
    {
        $this->actingAs($this->engineer);
        Site::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Unmapped yard']);

        $this->get("/projects/{$this->project->id}/attendance/selfie")->assertOk()
            ->assertSee(app(SelfieAttendanceService::class)->consentNote())
            ->assertSee('Tower A')
            ->assertDontSee('Unmapped yard');
    }

    public function test_the_web_screen_says_when_no_site_can_be_used(): void
    {
        $this->actingAs($this->engineer);
        $this->site->update(['lat' => null, 'lng' => null]);

        $this->get("/projects/{$this->project->id}/attendance/selfie")->assertOk()->assertSee('No site has a map location');
    }

    public function test_the_web_form_marks_attendance(): void
    {
        $this->actingAs($this->engineer);

        $this->post("/projects/{$this->project->id}/attendance/selfie", $this->payload(['consent' => '1', 'client_uuid' => (string) Str::uuid()]))
            ->assertRedirect("/projects/{$this->project->id}/attendance/selfie")
            ->assertSessionHasNoErrors();

        $this->assertSame('selfie', Attendance::sole()->method);
        $this->get("/projects/{$this->project->id}/attendance/selfie")->assertSee('Dattu');
    }

    public function test_the_web_form_shows_a_clear_error_outside_the_fence(): void
    {
        $this->actingAs($this->engineer);

        $this->from("/projects/{$this->project->id}/attendance/selfie")
            ->post("/projects/{$this->project->id}/attendance/selfie", $this->payload(['lat' => 18.53, 'lng' => 73.87, 'consent' => '1']))
            ->assertSessionHasErrors('lat');
    }

    public function test_deleting_withdraws_the_photo_and_keeps_the_attendance(): void
    {
        Sanctum::actingAs($this->engineer);
        $selfie = AttendanceSelfie::findOrFail($this->postSelfie()->json('data.id'));
        $path = $selfie->photo_path;

        $this->actingAs($this->engineer)->delete("/attendance-selfies/{$selfie->id}")->assertRedirect();

        Storage::assertMissing($path);
        $this->assertSame(0, AttendanceSelfie::count());
        $this->assertSame(1, Attendance::count());
    }

    public function test_another_company_cannot_delete_a_photo(): void
    {
        Sanctum::actingAs($this->engineer);
        $selfie = AttendanceSelfie::findOrFail($this->postSelfie()->json('data.id'));

        $this->actingAs($this->member($this->tenant('Other Co'), 'owner'))->delete("/attendance-selfies/{$selfie->id}")->assertNotFound();

        Storage::assertExists($selfie->photo_path);
    }

    public function test_the_project_menu_links_to_selfie_attendance(): void
    {
        $this->actingAs($this->engineer);

        $this->get("/projects/{$this->project->id}")->assertOk()->assertSee('Selfie attendance');
    }
}
