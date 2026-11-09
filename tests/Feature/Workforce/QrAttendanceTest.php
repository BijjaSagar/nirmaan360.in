<?php

namespace Tests\Feature\Workforce;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Services\Workforce\WorkerCardService;
use Illuminate\Support\Str;
use Tests\BuildsWorkforce;
use Tests\Feature\Api\ApiTestCase;

class QrAttendanceTest extends ApiTestCase
{
    use BuildsWorkforce;

    private function scan(object $site, string $code, array $extra = [])
    {
        return $this->postJson(self::API."/projects/{$site->project->id}/attendance/scan", ['code' => $code, ...$extra]);
    }

    private function card(object $worker): string
    {
        return app(WorkerCardService::class)->code($worker);
    }

    public function test_scanning_a_card_marks_the_worker_present_today_with_the_qr_method(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'Ramesh');

        $response = $this->scan($site, $this->card($worker))->assertCreated();

        $response->assertJsonPath('already_marked', false)
            ->assertJsonPath('worker.name', 'Ramesh')
            ->assertJsonPath('attendance.status', 'present')
            ->assertJsonPath('attendance.attendance_date', now()->toDateString());

        $mark = Attendance::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('qr', $mark->method);
        $this->assertSame($site->user->id, $mark->marked_by);
        $this->assertSame($site->project->id, $mark->project_id);
    }

    public function test_scanning_twice_changes_nothing(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization);

        $this->scan($site, $this->card($worker))->assertCreated();
        $this->scan($site, $this->card($worker))->assertOk()->assertJsonPath('already_marked', true);

        $this->assertSame(1, Attendance::withoutGlobalScopes()->count());
    }

    public function test_a_scan_never_overwrites_a_mark_someone_made_but_does_fix_absent(): void
    {
        $site = $this->site();
        $half = $this->makeWorker($site->organization, 'Half Day');
        $absent = $this->makeWorker($site->organization, 'Marked Absent');
        $this->mark($site->project, $half, now()->toDateString(), 'half');
        $this->mark($site->project, $absent, now()->toDateString(), 'absent');

        $this->scan($site, $this->card($half))->assertOk()->assertJsonPath('attendance.status', 'half');
        $this->scan($site, $this->card($absent))->assertCreated()->assertJsonPath('already_marked', false)->assertJsonPath('attendance.status', 'present');

        $this->assertSame('qr', Attendance::withoutGlobalScopes()->where('worker_id', $absent->id)->firstOrFail()->method);
        $this->assertSame(2, Attendance::withoutGlobalScopes()->count());
    }

    public function test_a_repeated_client_uuid_returns_the_first_scan(): void
    {
        $site = $this->site();
        $first = $this->makeWorker($site->organization, 'First');
        $second = $this->makeWorker($site->organization, 'Second');
        $uuid = (string) Str::uuid();

        $this->scan($site, $this->card($first), ['client_uuid' => $uuid])->assertCreated();
        // A retry of the same request, even carrying another card, gives back the original mark.
        $this->scan($site, $this->card($second), ['client_uuid' => $uuid])->assertOk()->assertJsonPath('already_marked', true);

        $this->assertSame(1, Attendance::withoutGlobalScopes()->count());
    }

    public function test_a_worker_cannot_be_present_on_two_projects_the_same_day(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'Busy Man');
        $elsewhere = $this->makeProject($site->organization, ['name' => 'Harbour Bridge']);
        $this->mark($elsewhere, $worker, now()->toDateString(), 'present');

        $this->scan($site, $this->card($worker))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertStringContainsString('Harbour Bridge', $this->scan($site, $this->card($worker))->json('errors.code.0'));
        $this->assertSame(1, Attendance::withoutGlobalScopes()->count());
    }

    public function test_forged_malformed_and_inactive_cards_are_refused(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'Real Worker');
        $inactive = $this->makeWorker($site->organization, 'Left Company', '700.00', ['is_active' => false]);
        $good = $this->card($worker);

        $forged = preg_replace('/-[a-f0-9]{20}$/', '-'.str_repeat('0', 20), $good);
        $guessed = sprintf('N360W-%d-1-%s', $worker->id + 1, str_repeat('a', 20));

        foreach ([$forged, $guessed, 'hello', 'N360W-1-1-short', ''] as $bad) {
            $this->scan($site, $bad)->assertUnprocessable();
        }

        $this->scan($site, $this->card($inactive))->assertUnprocessable();
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());
    }

    public function test_a_card_from_another_company_does_not_work_here(): void
    {
        $site = $this->site();
        $rival = $this->tenant('Rival Co');
        $stranger = $this->makeWorker($rival, 'Stranger');

        $this->scan($site, $this->card($stranger))->assertUnprocessable();
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());
    }

    public function test_replacing_a_card_cancels_the_old_one(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization);
        $old = $this->card($worker);

        app(WorkerCardService::class)->reissue($worker);
        $new = $this->card($worker->refresh());

        $this->assertNotSame($old, $new);
        $this->scan($site, $old)->assertUnprocessable();
        $this->assertStringContainsString('replaced', $this->scan($site, $old)->json('errors.code.0'));
        $this->scan($site, $new)->assertCreated();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'worker.card_reissued')->exists());
    }

    public function test_only_people_who_can_manage_attendance_may_scan(): void
    {
        $site = $this->site('store');
        $worker = $this->makeWorker($site->organization);

        $this->scan($site, $this->card($worker))->assertForbidden();
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());
    }

    public function test_a_project_from_another_company_cannot_be_scanned_into(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization);
        $rivalProject = $this->makeProject($this->tenant('Rival Co'));

        $this->postJson(self::API."/projects/{$rivalProject->id}/attendance/scan", ['code' => $this->card($worker)])->assertNotFound();
        $this->assertSame(0, Attendance::withoutGlobalScopes()->count());
    }

    public function test_the_scan_needs_a_code(): void
    {
        $site = $this->site();

        $this->scan($site, '')->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_signed_out_request_is_refused(): void
    {
        $project = $this->makeProject($this->tenant());

        $this->postJson(self::API."/projects/{$project->id}/attendance/scan", ['code' => 'x'])->assertUnauthorized();
    }

    public function test_cards_print_for_active_workers_of_this_company_only(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization, 'engineer');
        $mine = $this->makeWorker($organization, 'Printable Pat');
        $this->makeWorker($organization, 'Gone Gary', '500.00', ['is_active' => false]);
        $this->makeWorker($this->tenant('Rival Co'), 'Rival Rita');
        $this->withoutVite();

        $page = $this->actingAs($user)->get('/workers/cards')->assertOk();

        $page->assertSee('Printable Pat')->assertDontSee('Gone Gary')->assertDontSee('Rival Rita');
        $page->assertSee('<svg', false);

        $this->actingAs($user)->get('/workers/cards?worker='.$mine->id)->assertOk()->assertSee('Printable Pat');
        $this->actingAs($this->member($organization, 'accounts'))->get('/workers/cards')->assertForbidden();
    }

    public function test_the_web_scan_screen_uses_the_same_rules(): void
    {
        $this->withoutVite();
        $organization = $this->tenant();
        $user = $this->member($organization, 'engineer');
        $project = $this->makeProject($organization);
        $worker = $this->makeWorker($organization, 'Web Scan');

        $this->actingAs($user)->get("/projects/{$project->id}/attendance/scan")->assertOk()->assertSee('Scan attendance');
        $this->actingAs($user)->postJson("/projects/{$project->id}/attendance/scan", ['code' => $this->card($worker)])
            ->assertOk()->assertJsonPath('worker.name', 'Web Scan')->assertJsonPath('already_marked', false);
        $this->actingAs($user)->postJson("/projects/{$project->id}/attendance/scan", ['code' => 'nonsense'])->assertUnprocessable();

        $this->actingAs($this->member($organization, 'viewer'))->get("/projects/{$project->id}/attendance/scan")->assertForbidden();
        $this->actingAs($this->member($this->tenant('Rival Co'), 'owner'))->get("/projects/{$project->id}/attendance/scan")->assertNotFound();
    }

    public function test_replacing_a_card_needs_worker_manage_and_stays_inside_the_company(): void
    {
        $this->withoutVite();
        $organization = $this->tenant();
        $worker = $this->makeWorker($organization);

        $this->actingAs($this->member($organization, 'viewer'))->post("/workers/{$worker->id}/card/reissue")->assertForbidden();
        $this->actingAs($this->member($this->tenant('Rival Co'), 'owner'))->post("/workers/{$worker->id}/card/reissue")->assertNotFound();
        $this->assertSame(1, $worker->refresh()->qr_version);

        $this->actingAs($this->member($organization, 'engineer'))->post("/workers/{$worker->id}/card/reissue")->assertRedirect();
        $this->assertSame(2, $worker->refresh()->qr_version);
    }
}
