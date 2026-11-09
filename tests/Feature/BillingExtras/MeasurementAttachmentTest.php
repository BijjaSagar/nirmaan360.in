<?php

namespace Tests\Feature\BillingExtras;

use App\Exceptions\ImmutableRecordException;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Services\BillingExtras\MeasurementAttachmentService;
use App\Services\MeasurementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiTestCase;

/** FR-MB-01: photo and sketch attachments, locked with the entry they belong to. */
class MeasurementAttachmentTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function entry(object $site): MeasurementEntry
    {
        return $this->draftEntry($site->project, $site->item, $site->user);
    }

    private function upload(MeasurementEntry $entry, array $overrides = [])
    {
        return $this->post(self::API."/measurements/{$entry->id}/attachments", ['kind' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg'), ...$overrides], ['Accept' => 'application/json']);
    }

    private function attachment(MeasurementEntry $entry, object $site): MeasurementAttachment
    {
        return app(MeasurementAttachmentService::class)->add($entry, UploadedFile::fake()->image('a.jpg'), ['kind' => 'photo'], $site->user);
    }

    private function approved(MeasurementEntry $entry, object $site): MeasurementEntry
    {
        return $this->approveEntry($entry, $site->user, $this->member($site->organization, 'owner'));
    }

    public function test_api_stores_photos_and_sketches_privately(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);

        $this->upload($entry, ['caption' => 'Slab edge'])->assertCreated()
            ->assertJsonPath('data.kind', 'photo')
            ->assertJsonPath('data.is_image', true);
        $this->upload($entry, ['kind' => 'sketch', 'file' => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf')])->assertCreated()
            ->assertJsonPath('data.kind', 'sketch')
            ->assertJsonPath('data.is_image', false);

        $this->assertStringStartsWith("org-{$site->organization->id}/measurements/{$entry->id}/", MeasurementAttachment::first()->path);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_a_photo_must_be_an_image(): void
    {
        $site = $this->site();

        $this->upload($this->entry($site), ['file' => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_attachments_are_listed_and_served(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);

        $this->getJson(self::API."/measurements/{$entry->id}/attachments")->assertOk()->assertJsonPath('data.0.id', $attachment->id);
        $this->get(self::API."/measurement-attachments/{$attachment->id}")->assertOk();
    }

    public function test_upload_is_idempotent_on_client_uuid(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $uuid = (string) Str::uuid();

        $id = $this->upload($entry, ['client_uuid' => $uuid])->assertCreated()->json('data.id');
        $this->upload($entry, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, MeasurementAttachment::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_an_approved_entry_refuses_new_attachments_with_a_409(): void
    {
        $site = $this->site();
        $entry = $this->approved($this->entry($site), $site);

        $this->upload($entry)->assertStatus(409);

        $this->assertSame(0, MeasurementAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_an_approved_entry_refuses_removal_with_a_409_and_keeps_the_file(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);
        $this->approved($entry, $site);

        $this->deleteJson(self::API."/measurement-attachments/{$attachment->id}")->assertStatus(409);

        $this->assertSame(1, MeasurementAttachment::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_the_model_itself_refuses_changes_on_an_approved_entry(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);
        $this->approved($entry, $site);

        try {
            $attachment->update(['caption' => 'Altered']);
            $this->fail('An attachment on an approved entry was edited.');
        } catch (ImmutableRecordException) {
            $this->assertNull($attachment->refresh()->caption);
        }

        $this->expectException(ImmutableRecordException::class);
        $attachment->delete();
    }

    public function test_attachments_are_locked_while_under_review(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);
        app(MeasurementService::class)->submit($entry, $site->user);

        $this->upload($entry->refresh())->assertStatus(409);
        $this->deleteJson(self::API."/measurement-attachments/{$attachment->id}")->assertStatus(409);

        $this->assertSame(1, MeasurementAttachment::count());
    }

    public function test_a_returned_entry_can_change_its_attachments_again(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        MeasurementEntry::whereKey($entry->id)->update(['status' => 'RETURNED']);

        $this->upload($entry->refresh())->assertCreated();
    }

    public function test_a_retry_after_approval_returns_the_original_rather_than_a_409(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $uuid = (string) Str::uuid();
        $id = $this->upload($entry, ['client_uuid' => $uuid])->assertCreated()->json('data.id');
        $this->approved($entry, $site);

        $this->upload($entry->refresh(), ['client_uuid' => $uuid])->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_removing_on_a_draft_deletes_the_file(): void
    {
        $site = $this->site();
        $attachment = $this->attachment($this->entry($site), $site);

        $this->deleteJson(self::API."/measurement-attachments/{$attachment->id}")->assertNoContent();

        $this->assertSame(0, MeasurementAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_deleting_a_draft_entry_removes_its_files(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $this->attachment($entry, $site);

        $entry->delete();

        $this->assertSame(0, MeasurementAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_api_routes_need_their_permission(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);

        $this->signIn($this->member($site->organization, 'store'));
        $this->getJson(self::API."/measurements/{$entry->id}/attachments")->assertForbidden();
        $this->get(self::API."/measurement-attachments/{$attachment->id}", ['Accept' => 'application/json'])->assertForbidden();

        $this->signIn($this->member($site->organization, 'viewer'));
        $this->getJson(self::API."/measurements/{$entry->id}/attachments")->assertOk();
        $this->upload($entry)->assertForbidden();
        $this->deleteJson(self::API."/measurement-attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_another_companys_attachments_are_a_404(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);
        $this->signInElsewhere();

        $this->getJson(self::API."/measurements/{$entry->id}/attachments")->assertNotFound();
        $this->upload($entry)->assertNotFound();
        $this->get(self::API."/measurement-attachments/{$attachment->id}", ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson(self::API."/measurement-attachments/{$attachment->id}")->assertNotFound();

        $this->assertSame(1, MeasurementAttachment::withoutGlobalScopes()->count());
    }

    public function test_the_web_shows_uploads_and_removes_attachments_on_a_draft(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $this->actingAs($site->user);

        $this->post(route('measurement-attachments.store', $entry), ['kind' => 'sketch', 'file' => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf'), 'caption' => 'Grid C'])
            ->assertSessionHasNoErrors();
        $attachment = MeasurementAttachment::sole();

        $this->get(route('measurements.show', $entry))->assertOk()->assertSee('Grid C')->assertSee('Photos and sketches');
        $this->get(route('measurement-attachments.show', $attachment))->assertOk();
        $this->delete(route('measurement-attachments.destroy', $attachment))->assertRedirect();

        $this->assertSame(0, MeasurementAttachment::count());
    }

    public function test_the_web_page_locks_the_form_on_an_approved_entry(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $this->attachment($entry, $site);
        $this->approved($entry, $site);
        $this->actingAs($site->user);

        $this->get(route('measurements.show', $entry))->assertOk()
            ->assertSee('Attachments are locked with the approved entry.')
            ->assertDontSee(route('measurement-attachments.store', $entry));

        $this->post(route('measurement-attachments.store', $entry), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(409);
    }

    public function test_web_routes_need_their_permission_and_the_right_company(): void
    {
        $site = $this->site();
        $entry = $this->entry($site);
        $attachment = $this->attachment($entry, $site);

        $this->actingAs($this->member($site->organization, 'viewer'));
        $this->post(route('measurement-attachments.store', $entry), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();
        $this->delete(route('measurement-attachments.destroy', $attachment))->assertForbidden();

        $this->actingAs($this->member($this->tenant('Other Co'), 'owner'));
        $this->get(route('measurement-attachments.show', $attachment))->assertNotFound();
        $this->delete(route('measurement-attachments.destroy', $attachment))->assertNotFound();
    }
}
