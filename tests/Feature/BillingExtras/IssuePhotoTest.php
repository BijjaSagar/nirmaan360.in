<?php

namespace Tests\Feature\BillingExtras;

use App\Models\AuditLog;
use App\Models\Issue;
use App\Models\IssuePhoto;
use App\Models\Project;
use App\Models\User;
use App\Services\BillingExtras\IssuePhotoService;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiTestCase;

/** FR-SITE-02: before and after photos on issues. */
class IssuePhotoTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function raise(Project $project, User $user): Issue
    {
        return app(IssueService::class)->create($project, $this->issueData(), $user);
    }

    private function upload(Issue $issue, array $overrides = [])
    {
        return $this->post(self::API."/issues/{$issue->id}/photos", ['kind' => 'before', 'photo' => UploadedFile::fake()->image('a.jpg'), ...$overrides], ['Accept' => 'application/json']);
    }

    private function photo(Issue $issue, User $user, string $kind = 'before'): IssuePhoto
    {
        return app(IssuePhotoService::class)->add($issue, UploadedFile::fake()->image('a.jpg'), ['kind' => $kind], $user);
    }

    public function test_api_stores_before_and_after_photos_privately(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);

        $this->upload($issue, ['kind' => 'before', 'caption' => 'Honeycombing'])->assertCreated()
            ->assertJsonPath('data.kind', 'before')
            ->assertJsonPath('data.caption', 'Honeycombing');
        $this->upload($issue, ['kind' => 'after'])->assertCreated()->assertJsonPath('data.kind', 'after');

        $photo = IssuePhoto::where('kind', 'before')->firstOrFail();
        $this->assertSame($site->organization->id, $photo->organization_id);
        $this->assertStringStartsWith("org-{$site->organization->id}/issues/{$issue->id}/", $photo->path);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertSame($site->user->id, $photo->uploaded_by);
        $this->assertSame(2, AuditLog::where('auditable_type', IssuePhoto::class)->where('action', 'created')->count());
    }

    public function test_photos_are_listed_and_the_file_is_served(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $photo = $this->photo($issue, $site->user, 'after');

        $this->getJson(self::API."/issues/{$issue->id}/photos")->assertOk()
            ->assertJsonPath('data.0.id', $photo->id)
            ->assertJsonPath('data.0.kind', 'after');
        $this->get(self::API."/issue-photos/{$photo->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_new_photo_reaches_devices_on_the_next_pull(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        Issue::whereKey($issue->id)->update(['updated_at' => now()->subDay()]);

        $this->photo($issue->refresh(), $site->user);

        $this->assertTrue($issue->refresh()->updated_at->isToday());
    }

    public function test_upload_is_idempotent_on_client_uuid(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $uuid = (string) Str::uuid();

        $id = $this->upload($issue, ['client_uuid' => $uuid])->assertCreated()->json('data.id');
        $this->upload($issue, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, IssuePhoto::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_client_uuid_cannot_be_reused_on_another_issue(): void
    {
        $site = $this->site();
        $uuid = (string) Str::uuid();
        $this->upload($this->raise($site->project, $site->user), ['client_uuid' => $uuid])->assertCreated();

        $this->upload($this->raise($site->project, $site->user), ['client_uuid' => $uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_kind_and_file_type_are_validated(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);

        $this->upload($issue, ['kind' => 'during'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->upload($issue, ['photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertSame(0, IssuePhoto::count());
    }

    public function test_removing_a_photo_deletes_its_file(): void
    {
        $site = $this->site();
        $photo = $this->photo($this->raise($site->project, $site->user), $site->user);

        $this->deleteJson(self::API."/issue-photos/{$photo->id}")->assertNoContent();

        $this->assertSame(0, IssuePhoto::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_api_routes_need_their_permission(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $photo = $this->photo($issue, $site->user);

        // QS has no issue permissions at all, store keepers can only look.
        $this->signIn($this->member($site->organization, 'qs'));
        $this->getJson(self::API."/issues/{$issue->id}/photos")->assertForbidden();
        $this->get(self::API."/issue-photos/{$photo->id}", ['Accept' => 'application/json'])->assertForbidden();

        $this->signIn($this->member($site->organization, 'store'));
        $this->getJson(self::API."/issues/{$issue->id}/photos")->assertOk();
        $this->upload($issue)->assertForbidden();
        $this->deleteJson(self::API."/issue-photos/{$photo->id}")->assertForbidden();

        $this->assertSame(1, IssuePhoto::count());
    }

    public function test_another_companys_photos_are_a_404(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $photo = $this->photo($issue, $site->user);
        $this->signInElsewhere();

        $this->getJson(self::API."/issues/{$issue->id}/photos")->assertNotFound();
        $this->upload($issue)->assertNotFound();
        $this->get(self::API."/issue-photos/{$photo->id}", ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson(self::API."/issue-photos/{$photo->id}")->assertNotFound();

        $this->assertSame(1, IssuePhoto::withoutGlobalScopes()->count());
    }

    public function test_the_file_needs_a_signed_in_user(): void
    {
        $site = $this->site();
        $photo = $this->photo($this->raise($site->project, $site->user), $site->user);
        auth()->forgetGuards();

        $this->get(self::API."/issue-photos/{$photo->id}", ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get(route('issue-photos.show', $photo))->assertRedirect(route('login'));
    }

    public function test_the_web_form_adds_before_photos_when_raising_an_issue(): void
    {
        $site = $this->site();
        $this->actingAs($site->user);

        $this->post(route('issues.store', $site->project), [
            ...$this->issueData(),
            'client_uuid' => (string) Str::uuid(),
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['before', 'before'], IssuePhoto::pluck('kind')->all());
        $this->get(route('issues.index', $site->project))->assertOk()->assertSee('Before and after photos')->assertSee(route('issue-photos.show', IssuePhoto::first()));
    }

    public function test_the_web_can_add_show_and_remove_a_photo(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $this->actingAs($site->user);

        $this->post(route('issue-photos.store', $issue), ['kind' => 'after', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasNoErrors();
        $photo = IssuePhoto::sole();

        $this->get(route('issue-photos.show', $photo))->assertOk();
        $this->delete(route('issue-photos.destroy', $photo))->assertRedirect();

        $this->assertSame(0, IssuePhoto::count());
    }

    public function test_web_routes_need_their_permission_and_the_right_company(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $photo = $this->photo($issue, $site->user);

        $this->actingAs($this->member($site->organization, 'store'));
        $this->post(route('issue-photos.store', $issue), ['kind' => 'after', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();
        $this->delete(route('issue-photos.destroy', $photo))->assertForbidden();

        $this->actingAs($this->member($this->tenant('Other Co'), 'owner'));
        $this->get(route('issue-photos.show', $photo))->assertNotFound();
        $this->post(route('issue-photos.store', $issue), ['kind' => 'after', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();
        $this->delete(route('issue-photos.destroy', $photo))->assertNotFound();

        $this->assertSame(1, IssuePhoto::withoutGlobalScopes()->count());
    }
}
