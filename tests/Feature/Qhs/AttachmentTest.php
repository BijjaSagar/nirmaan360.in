<?php

namespace Tests\Feature\Qhs;

use App\Models\FieldAttachment;
use App\Models\Ncr;
use App\Services\Qhs\NcrService;
use App\Services\Qhs\SafetyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class AttachmentTest extends FieldOpsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function ncr(object $w): Ncr
    {
        return app(NcrService::class)->create($w->project, ['title' => 'Cracks in plaster', 'category' => 'workmanship', 'severity' => 'minor'], $w->owner);
    }

    public function test_a_photo_is_stored_privately_and_served_through_a_checked_route(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->ncr($w);

        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->image('crack.jpg', 800, 600), 'caption' => 'North wall'])->assertRedirect()->assertSessionHas('success');

        $file = FieldAttachment::firstOrFail();
        $this->assertSame('North wall', $file->caption);
        $this->assertSame('crack.jpg', $file->original_name);
        $this->assertSame($w->owner->id, $file->uploaded_by);
        $this->assertTrue($file->isImage());
        $this->assertStringStartsWith("org-{$w->organization->id}/qhs/ncrs/{$ncr->id}/", $file->path);
        Storage::disk('local')->assertExists($file->path);
        $this->assertFalse(str_contains($file->path, 'public'));

        $this->get(route('qhs-files.show', $file))->assertOk();
        $this->get(route('ncrs.show', $ncr))->assertOk()->assertSee(route('qhs-files.show', $file), false)->assertSee('North wall');
    }

    public function test_pdfs_are_accepted_and_other_file_types_are_not(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->ncr($w);

        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->create('lab-report.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();
        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->create('macro.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file');
        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->create('notes.html', 10, 'text/html')])->assertSessionHasErrors('file');
        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->create('big.pdf', 9000, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), [])->assertSessionHasErrors('file');

        $this->assertSame(1, FieldAttachment::count());
        $this->assertFalse(FieldAttachment::first()->isImage());
    }

    public function test_the_same_client_uuid_stores_one_file(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->ncr($w);
        $uuid = (string) Str::uuid();

        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->image('a.jpg'), 'client_uuid' => $uuid]);
        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->image('a.jpg'), 'client_uuid' => $uuid]);

        $this->assertSame(1, FieldAttachment::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_nothing_can_be_added_to_a_closed_record(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->ncr($w);
        $this->patch(route('ncrs.update', $ncr), ['root_cause' => 'x', 'corrective_action' => 'y', 'status' => 'resolved']);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'closed']);

        $this->post(route('qhs-files.store', ['ncrs', $ncr->id]), ['file' => UploadedFile::fake()->image('late.jpg')])->assertStatus(409);
        $this->assertSame(0, FieldAttachment::count());
    }

    public function test_files_can_go_on_incidents_with_the_safety_permission(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $incident = app(SafetyService::class)->report($w->project, ['kind' => 'incident', 'severity' => 'low', 'occurred_at' => '2026-11-02 10:00', 'description' => 'Slip'], $w->owner);

        $this->post(route('qhs-files.store', ['incidents', $incident->id]), ['file' => UploadedFile::fake()->image('scene.jpg')])->assertSessionHasNoErrors();
        $this->assertSame(1, $incident->attachments()->count());

        // A store keeper may view safety records but not add to them.
        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'store'));
        $this->post(route('qhs-files.store', ['incidents', $incident->id]), ['file' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
        $this->get(route('qhs-files.show', $incident->attachments()->first()))->assertOk();
    }

    public function test_only_people_who_may_view_the_record_can_open_its_files(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $incident = app(SafetyService::class)->report($w->project, ['kind' => 'incident', 'severity' => 'low', 'occurred_at' => '2026-11-02 10:00', 'description' => 'Slip'], $w->owner);
        $this->post(route('qhs-files.store', ['incidents', $incident->id]), ['file' => UploadedFile::fake()->image('scene.jpg')]);
        $file = $incident->attachments()->first();

        // Accounts can see quality records but has no safety permission.
        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'accounts'));
        $this->get(route('qhs-files.show', $file))->assertForbidden();

        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'viewer'));
        $this->get(route('qhs-files.show', $file))->assertOk();
        $this->post(route('qhs-files.store', ['incidents', $incident->id]), ['file' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    }

    public function test_unknown_owner_types_and_other_companys_files_are_not_found(): void
    {
        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirNcr = $this->ncr($theirs);
        $this->post(route('qhs-files.store', ['ncrs', $theirNcr->id]), ['file' => UploadedFile::fake()->image('secret.jpg')]);
        $theirFile = FieldAttachment::firstOrFail();

        Auth::forgetUser();
        $this->actingAs($mine->owner);

        $this->get(route('qhs-files.show', $theirFile))->assertNotFound();
        $this->post(route('qhs-files.store', ['ncrs', $theirNcr->id]), ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $this->post(route('qhs-files.store', ['users', $mine->owner->id]), ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $this->post(route('qhs-files.store', ['assets', 1]), ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $this->assertSame(1, FieldAttachment::withoutGlobalScopes()->count());
    }

    public function test_the_route_is_part_of_the_plan(): void
    {
        $small = $this->world('owner', 'contractor', 'Small Co');
        $this->actingAs($small->owner);

        $this->post(route('qhs-files.store', ['ncrs', 1]), ['file' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    }
}
