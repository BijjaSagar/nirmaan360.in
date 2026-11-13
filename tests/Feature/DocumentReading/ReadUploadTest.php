<?php

namespace Tests\Feature\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Services\DocumentReading\ExifOrientation;
use App\Services\DocumentReading\ImageTokens;
use App\Services\DocumentReading\PageImage;
use App\Services\DocumentReading\ReadFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Uploading a read's page pictures and its original PDF: what is stored, what is refused and with
 * which words, and when an upload is still taken.
 */
class ReadUploadTest extends ReadRoutesTestCase
{
    public function test_a_page_picture_is_redrawn_stored_and_reported(): void
    {
        $read = $this->draft();
        $sent = $this->jpeg(640, 900);

        $response = $this->upload($read, 2, 'full', $this->file('scan.jpg', $sent));

        $page = $this->row($read, 2);
        $response->assertStatus(201)->assertExactJson([
            'id' => $page->id, 'page_no' => 2, 'part' => 'full', 'width' => 640, 'height' => 900, 'tokens' => ImageTokens::count(640, 900),
        ]);

        // The file is the server's own drawing of the picture, under the page's fixed name, and the row describes that file.
        $stored = Storage::get(ReadFiles::page($read, 2, 'rows', 'full'));
        $this->assertNotSame($sent, $stored);
        $this->assertSame([640, 900], array_slice(getimagesizefromstring($stored), 0, 2));
        $this->assertSame(ReadFiles::page($read, 2, 'rows', 'full'), $page->image_path);
        $this->assertSame(hash('sha256', $stored), $page->image_sha256);
        $this->assertSame(strlen($stored), (int) $page->image_bytes);
        $this->assertSame([640, 900], [(int) $page->image_width, (int) $page->image_height]);

        // It waits for the first pass of its kind, at that pass's usual model, in the read's own project.
        $this->assertSame(DocumentReadPage::WAITING, $page->status);
        $this->assertSame('rows', $page->pass);
        $this->assertSame('claude-sonnet-5-5', $page->model);
        $this->assertSame($this->project->id, $page->project_id);
        $this->assertSame(DocumentRead::DRAFT, $read->refresh()->status);

        // Who stored which picture is on record, by fingerprint. Nothing has been sent anywhere.
        $audit = $this->audits('ai.page_stored')[0];
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame($this->organization->id, $audit->organization_id);
        $this->assertSame(['read_id' => $read->id, 'kind' => 'boq', 'page_no' => 2, 'pass' => 'rows', 'part' => 'full', 'bytes' => strlen($stored), 'sha256' => hash('sha256', $stored), 'width' => 640, 'height' => 900], $audit->new_values);
        Http::assertNothingSent();
        $this->assertSame([], $this->queuedPages());
    }

    public function test_the_phone_uploads_through_the_same_route_under_api(): void
    {
        $read = $this->draft(['channel' => 'mobile']);

        $this->api($this->owner, 'POST', $read, '/pages', ['page_no' => 1, 'image' => $this->picture()])
            ->assertStatus(201)->assertJson(['page_no' => 1, 'part' => 'full', 'width' => 640, 'height' => 900]);

        $this->assertNotNull($this->row($read, 1)?->image_path);
    }

    public function test_location_and_camera_details_inside_a_photo_never_reach_the_disk(): void
    {
        $read = $this->draft();
        // A phone photo held sideways: 900 wide and 640 high in the file, with an orientation tag that turns it upright.
        $photo = $this->jpeg(900, 640, $this->exif(6).$this->otherMetadata());
        $this->assertStringContainsString('Exif', $photo);
        $this->assertSame(6, ExifOrientation::of($photo));

        $this->upload($read, 1, 'full', $this->file('photo.jpg', $photo))->assertStatus(201)->assertJson(['width' => 640, 'height' => 900]);

        $stored = Storage::get($this->row($read, 1)->image_path);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertSame(1, ExifOrientation::of($stored));
        $this->assertSame([640, 900], array_slice(getimagesizefromstring($stored), 0, 2));
    }

    public function test_the_same_page_sent_again_replaces_the_picture_until_the_read_starts(): void
    {
        $read = $this->draft();

        $first = $this->upload($read, 1)->assertStatus(201)->json('id');
        $before = $this->row($read, 1)->image_sha256;

        $this->upload($read, 1, 'full', $this->picture(700, 1000))->assertStatus(201)->assertJson(['id' => $first, 'width' => 700, 'height' => 1000]);

        $page = $this->row($read, 1);
        $this->assertCount(1, $this->rows($read));
        $this->assertNotSame($before, $page->image_sha256);
        $this->assertSame([ReadFiles::page($read, 1, 'rows', 'full')], $this->filesOf($read));
        $this->assertSame(hash('sha256', Storage::get($page->image_path)), $page->image_sha256);

        // A quote does not close the door either: the price is worked out again at the start.
        $read->update(['status' => DocumentRead::QUOTED]);
        $this->upload($read, 3)->assertStatus(201);
    }

    public function test_a_picture_that_cannot_be_used_is_refused_with_its_code_and_nothing_is_stored(): void
    {
        $read = $this->draft();

        $this->upload($read, 1, 'full', $this->file('notes.jpg', 'These are not the bytes of a picture.'))
            ->assertStatus(422)->assertExactJson(['code' => 'not_an_image', 'message' => 'This file is not a picture that can be read. Use a JPG, PNG or WebP picture.']);

        $this->upload($read, 1, 'full', $this->picture(400, 500))
            ->assertStatus(422)->assertExactJson(['code' => 'image_too_small', 'min_short_side' => 600, 'width' => 400, 'height' => 500, 'message' => 'This picture is too small to read. Its shorter side must be at least 600 pixels.']);

        $tooBig = $this->upload($read, 1, 'full', $this->file('huge.png', $this->pngHeaderOnly(6000, 4000)))->assertStatus(422)->assertJson(['code' => 'image_too_big', 'max_edge' => 2576, 'max_tokens' => 4784]);
        $this->assertStringContainsString($tooBig->json('fits.width').' by '.$tooBig->json('fits.height'), $tooBig->json('message'));

        $this->upload($read, 1, 'full', $this->file('mirror.jpg', $this->jpeg(640, 900, $this->exif(2))))
            ->assertStatus(422)->assertExactJson(['code' => 'image_mirrored', 'message' => 'This picture is saved mirrored. Take the photo again.']);

        $this->assertSame([], $this->rows($read));
        $this->assertSame([], $this->filesOf($read));
        $this->assertSame([], $this->audits('ai.page_stored'));
    }

    public function test_an_upload_request_may_carry_six_megabytes_at_most_and_needs_a_page_number(): void
    {
        $read = $this->draft();

        $this->upload($read, 1, 'full', UploadedFile::fake()->create('big.jpg', 6200))->assertStatus(422)->assertJsonValidationErrors('image');
        $this->actingAs($this->owner)->postJson("/document-reads/{$read->id}/pages", ['image' => $this->picture()])->assertStatus(422)->assertJsonValidationErrors('page_no');
        $this->actingAs($this->owner)->postJson("/document-reads/{$read->id}/pages", ['page_no' => 1])->assertStatus(422)->assertJsonValidationErrors('image');
        $this->actingAs($this->owner)->post("/document-reads/{$read->id}/pages", ['page_no' => 1, 'part' => 'left', 'image' => $this->picture()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('part');

        $this->assertSame([], $this->rows($read));
    }

    public function test_a_page_the_document_does_not_have_is_refused(): void
    {
        $read = $this->draft(['pages_total' => 3]);

        $this->upload($read, 4)->assertStatus(422)->assertJsonValidationErrors(['page_no' => 'This document has no page 4.']);
        $this->upload($read, 0)->assertStatus(422)->assertJsonValidationErrors('page_no');
        $this->upload($read, 3)->assertStatus(201);

        // A read that was opened without a page count takes any page number, up to as many pages as its kind reads.
        $open = $this->draft(['pages_total' => 0]);
        $this->upload($open, 412)->assertStatus(201);
    }

    public function test_a_read_takes_no_more_pages_than_its_kind_reads(): void
    {
        $read = $this->draft(['pages_total' => 0]);

        foreach (range(1, 60) as $number) {
            $this->makePage($read, $number, ['image_path' => ReadFiles::page($read, $number, 'rows', 'full')]);
        }

        $this->upload($read, 61)->assertStatus(422)->assertExactJson(['code' => 'too_many_pages', 'max_pages' => 60, 'message' => 'This document can have 60 pages at most.']);

        // Replacing one of the sixty is not a sixty-first page.
        $this->upload($read, 60)->assertStatus(201);
        $this->assertCount(60, $this->rows($read));
    }

    public function test_once_a_read_has_started_its_pages_are_fixed(): void
    {
        foreach ([DocumentRead::RUNNING, DocumentRead::PAUSED, DocumentRead::READY, DocumentRead::FAILED, DocumentRead::CONFIRMED, DocumentRead::CANCELLED, DocumentRead::EXPIRED] as $status) {
            $read = $this->draft(['status' => $status]);

            $this->upload($read, 1)->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => $status]);
            $this->assertSame([], $this->rows($read), $status);
        }

        // A read whose files were deleted takes nothing more, whatever its state.
        $purged = $this->draft(['files_purged_at' => now()]);
        $this->upload($purged, 1)->assertStatus(409)->assertJson(['code' => 'wrong_state']);
    }

    public function test_the_halves_of_a_cut_off_page_are_taken_after_the_start_and_wait_unsent(): void
    {
        $read = $this->startedRead(null, '100', ['status' => DocumentRead::READY, 'credits_held' => '0', 'project_id' => $this->project->id]);
        $whole = $this->storedPage($read, 2, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off', 'attempts' => 1, 'behaviour_failures' => 1]);
        $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1]);

        $top = $this->upload($read, 2, 'top', $this->picture(640, 620))->assertStatus(201)->assertJson(['page_no' => 2, 'part' => 'top', 'height' => 620]);
        $this->upload($read, 2, 'bottom', $this->picture(640, 620))->assertStatus(201);

        $half = $this->row($read, 2, 'rows', 'top');
        $this->assertSame($top->json('id'), $half->id);
        // Set aside, so nothing sends one half before the other is there and someone asks for both.
        $this->assertSame(DocumentReadPage::SKIPPED, $half->status);
        $this->assertSame([0, 550], [(int) $half->part_top, (int) $half->part_height]);
        $bottom = $this->row($read, 2, 'rows', 'bottom');
        $this->assertSame([450, 550], [(int) $bottom->part_top, (int) $bottom->part_height]);
        $this->assertSame(ReadFiles::page($read, 2, 'rows', 'bottom'), $bottom->image_path);
        $this->assertSame(DocumentReadPage::FAILED, $whole->refresh()->status);
        $this->assertSame([], $this->queuedPages());

        // A half can be sent again while it waits. A half of a page that was not cut off is not taken, and neither is a whole page.
        $this->upload($read, 2, 'top', $this->picture(640, 630))->assertStatus(201)->assertJson(['height' => 630]);
        $this->upload($read, 1, 'top', $this->picture(640, 620))->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'ready', 'page_status' => 'read']);
        $this->upload($read, 3, 'top', $this->picture(640, 620))->assertStatus(409)->assertJson(['code' => 'wrong_state']);
        $this->upload($read, 2, 'full')->assertStatus(409)->assertJson(['code' => 'wrong_state']);
    }

    public function test_halves_are_not_taken_for_a_kind_that_does_not_offer_them_or_before_the_start(): void
    {
        Support\FakeKind::$cutOff = 'fail';
        $read = $this->startedRead(null, '100', ['status' => DocumentRead::READY, 'credits_held' => '0', 'project_id' => $this->project->id]);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off', 'attempts' => 1]);

        $this->upload($read, 2, 'top', $this->picture(640, 620))->assertStatus(409)->assertJson(['code' => 'wrong_state']);

        $this->upload($this->draft(), 1, 'top', $this->picture(640, 620))->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'draft']);
    }

    public function test_a_picture_that_cannot_be_kept_on_the_disk_leaves_no_row_pointing_at_it(): void
    {
        $read = $this->draft();
        $this->upload($read, 1)->assertStatus(201);

        Storage::shouldReceive('put')->andReturn(false);

        $this->upload($read, 1, 'full', $this->picture(700, 1000))
            ->assertStatus(409)->assertExactJson(['code' => 'not_stored', 'message' => 'The page could not be stored. Send it again.']);
        $this->upload($read, 2)->assertStatus(409)->assertJson(['code' => 'not_stored']);

        // The page that was there before is no longer trusted either: its file may be half written.
        $this->assertNull($this->row($read, 1)->image_path);
        $this->assertNull($this->row($read, 1)->image_sha256);
        $this->assertNull($this->row($read, 2)->image_path);
    }

    public function test_a_server_that_cannot_redraw_pictures_refuses_the_upload_and_tells_the_platform_admins_once(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->tenant('Platform Team')->id, 'role' => 'owner']);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->app->instance(PageImage::class, new class extends PageImage
        {
            protected static function canRedraw(string $type): bool
            {
                return false;
            }
        });

        $read = $this->draft();

        foreach ([1, 2] as $number) {
            $this->upload($read, $number)->assertStatus(409)
                ->assertExactJson(['code' => 'server_cannot_process', 'message' => 'The server cannot process pictures right now. Nothing was stored. Try again later.']);
        }

        $this->assertSame([], $this->rows($read));
        $this->assertSame([], $this->filesOf($read));
        $this->assertSame(['The AI readers cannot take page pictures on this server'], $this->noticesOf($admin));
        // The company's own people are not told what is wrong with the server.
        $this->assertSame([], $this->noticesOf($this->owner));
    }

    public function test_the_original_pdf_is_kept_beside_the_pages_for_the_record(): void
    {
        $read = $this->draft();
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $this->actingAs($this->owner)->put("/document-reads/{$read->id}/source", ['file' => $this->file('Estimate block A.pdf', $pdf)], ['Accept' => 'application/json'])->assertNoContent();

        $read->refresh();
        $this->assertSame(ReadFiles::source($read), $read->source_path);
        $this->assertSame($pdf, Storage::get($read->source_path));
        $this->assertSame(hash('sha256', $pdf), $read->source_sha256);
        $this->assertSame(strlen($pdf), (int) $read->source_bytes);
        $this->assertSame('Estimate block A.pdf', $read->source_name);
        $this->assertSame(['kind' => 'boq', 'bytes' => strlen($pdf), 'sha256' => hash('sha256', $pdf)], $this->audits('ai.source_stored')[0]->new_values);

        // The phone sends it the same way. A name the feature already gave the read is kept.
        $named = $this->draft(['source_name' => 'Tower B estimate']);
        $this->api($this->owner, 'PUT', $named, '/source', ['file' => $this->file('scan0001.pdf', $pdf)])->assertNoContent();
        $this->assertSame('Tower B estimate', $named->refresh()->source_name);

        // It is kept only for the record. Nothing reads it, so nothing is queued or sent.
        $this->assertSame([], $this->queuedPages());
        Http::assertNothingSent();
    }

    public function test_only_a_pdf_is_kept_as_the_original_and_only_by_kinds_that_keep_one(): void
    {
        $read = $this->draft();
        $put = fn (DocumentRead $read, UploadedFile $file) => $this->actingAs($this->owner)->put("/document-reads/{$read->id}/source", ['file' => $file], ['Accept' => 'application/json']);

        $put($read, $this->file('estimate.pdf', $this->jpeg(640, 900)))
            ->assertStatus(422)->assertExactJson(['code' => 'not_a_pdf', 'max_bytes' => 10485760, 'message' => 'Only a PDF file of up to 10 MB can be kept as the original.']);
        $put($read, $this->file('estimate.pdf', 'A text file that calls itself a PDF.'))->assertStatus(422)->assertJson(['code' => 'not_a_pdf']);
        $put($read, UploadedFile::fake()->create('estimate.pdf', 10300))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($this->owner)->putJson("/document-reads/{$read->id}/source", [])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($read->refresh()->source_path);
        $this->assertSame([], $this->filesOf($read));

        // A challan photo has no original to keep, so the route is not there for it.
        $slip = $this->draft(['kind' => 'slip']);
        $put($slip, $this->file('slip.pdf', "%PDF-1.4\n%%EOF\n"))->assertNotFound();

        // Not for a read that is over.
        $closed = $this->draft(['status' => DocumentRead::CANCELLED]);
        $put($closed, $this->file('estimate.pdf', "%PDF-1.4\n%%EOF\n"))->assertStatus(409)->assertJson(['code' => 'wrong_state', 'status' => 'cancelled']);
    }

    public function test_no_table_ever_holds_a_page_picture(): void
    {
        $read = $this->draft();
        $this->upload($read, 1)->assertStatus(201);

        $stored = Storage::get($this->row($read, 1)->image_path);
        $needles = [base64_encode($stored), substr(base64_encode($stored), 0, 80)];

        foreach (['document_reads', 'document_read_pages', 'audit_logs', 'jobs', 'failed_jobs'] as $table) {
            $everything = DB::table($table)->get()->toJson();

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $everything, $table);
            }
        }
    }
}
