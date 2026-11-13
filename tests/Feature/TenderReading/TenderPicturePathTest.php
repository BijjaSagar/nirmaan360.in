<?php

namespace Tests\Feature\TenderReading;

use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Services\DocumentReading\ReadDesk;
use App\Services\TenderReading\TenderReads;
use App\Services\TenderReading\TenderTextPreparer;
use App\Services\TenderReading\TenderTextRefused;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DocumentReading\BuildsPictures;

/**
 * The picture path of the tender reader: a JPG or PNG document, the pages of a text PDF whose text
 * cannot be trusted, and a PDF too large or too long for its text to be taken out. The browser draws
 * the pages and uploads them through the shared upload route; these tests upload pictures made in
 * code the same way. Code sets the page of every fact read from a picture.
 */
class TenderPicturePathTest extends TenderReadingTestCase
{
    use BuildsPictures;

    private function uploadPicture(DocumentRead $read, int $pageNo, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->owner)->post(route('document-reads.pages.store', $read), [
            'page_no' => $pageNo,
            'part' => 'full',
            'image' => UploadedFile::fake()->createWithContent('page-'.$pageNo.'.jpg', $this->jpeg(1240, 1754)),
        ], ['Accept' => 'application/json']);
    }

    /** @param  array<string, mixed>  $body */
    private function hasPicture(array $body): bool
    {
        foreach ((array) ($body['messages'][0]['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'image') {
                return true;
            }
        }

        return false;
    }

    public function test_a_picture_document_is_read_as_one_page_picture_and_its_facts_are_on_that_page(): void
    {
        $document = $this->storeDocument($this->tender, $this->jpeg(1240, 1754), 'notice.jpg');

        $this->actingAs($this->owner)->get(route('crm.tenders.show', $this->tender))->assertOk()
            ->assertSee(route('tender-reads.store', ['tender' => $this->tender, 'document' => $document]), false);

        $uuid = '0b6c1f8e-4d55-4a43-8f0e-6b2d1c9a7e31';
        $response = $this->startRead($this->owner, $document, null, ['client_uuid' => $uuid])->assertCreated()->assertJson(['status' => 'prepared']);
        $read = DocumentRead::withoutGlobalScopes()->findOrFail($response->json('id'));
        $this->startRead($this->owner, $document, null, ['client_uuid' => $uuid])->assertOk()->assertJson(['id' => $read->id]);

        Queue::assertNothingPushed();
        $this->assertSame([TenderReads::SOURCE_IMAGE, 1, [1]], [TenderReads::source($read), (int) $read->pages_total, TenderReads::picturePages($read)]);

        // There is no text to compare: only the language is asked.
        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('This document is a picture. It is read as one page picture.');
        $page->assertSee(route('crm.documents.download', $document), false);
        $page->assertDontSee('Does the text on the left match the page on the right?');
        $page->assertDontSee('name="page_from"', false);

        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), [])->assertSessionHasErrors(['language'])->assertSessionDoesntHaveErrors(['matches', 'page_from']);
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'mr'])->assertRedirect(route('tender-reads.view', $read))->assertSessionHasNoErrors();

        $read->refresh();
        $this->assertSame(['mr', false, [1]], [$read->options['language'], $read->options['matches'], $read->options['needs_picture']]);

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('The document is read as one page picture.');
        $page->assertSee('Upload the picture');
        $page->assertSee('Upload the pictures to see the price.');

        $this->uploadPicture($read, 1)->assertCreated();
        $this->assertSame([1], TenderReads::storedPictures($read));

        // The reader names a page printed on the picture; code keeps the picture's own page.
        $this->fakeReader([$this->reply([
            $this->fact('emd_amount', 'Rs. 61,500/-', 7, 'Earnest Money Deposit: Rs. 61,500/- by online payment', 'Earnest Money Deposit'),
            $this->fact('due_at', '15-12-2026 up to 15:00 hrs', 0, 'Last date of online submission: 15-12-2026 up to 15:00 hrs'),
        ])]);

        app(ReadDesk::class)->start($read->refresh(), $this->owner);
        $this->readAll($read);

        $this->assertCount(1, $this->sent);
        $this->assertTrue($this->hasPicture($this->sent[0]));
        $this->assertStringContainsString('Main language of the document: Marathi.', json_encode($this->sent[0]));

        $facts = $this->facts();
        $this->assertSame([[1, 'image', null], [1, 'image', null]], $facts->map(fn (CrmTenderFact $fact) => [(int) $fact->page, $fact->source, $fact->quote_found])->all());

        // The sheet shows the picture document itself beside each fact, with the sentence for page pictures.
        $sheet = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertSee('Read from the page picture. Check it against the page.');
        $sheet->assertSee('data-picture="'.route('crm.documents.download', $document).'"', false);
        $sheet->assertSee('data-pdf=""', false);
        $sheet->assertDontSee('Pages not read');

        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()->assertSee('Pages read: 1 of 1');
    }

    public function test_the_flagged_pages_of_a_text_pdf_are_read_as_pictures_once_their_pictures_are_uploaded(): void
    {
        $read = $this->preparedRead();
        $chars = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->sum('text_chars');

        // As if page 2 had no usable text: it is no part of any run.
        $read->forceFill(['options' => [...$read->options, 'page_flags' => [2 => ['no_text']], 'runs' => [[1, 1, (int) $chars, 0], [3, 3, (int) $chars, 0]]]])->save();

        $this->answer($read)->assertRedirect();
        $read->refresh();
        $this->assertSame([2], TenderReads::picturesToRead($read));

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('Pages to read as text: 2');
        $page->assertSee('Pages to read as page pictures: 2');
        $page->assertSee('Draw and upload the pages');
        $page->assertSee('A page without a picture is not read. The tender sheet lists it as not read.');

        $this->uploadPicture($read, 2)->assertCreated();

        $this->fakeReader([fn (array $body) => $this->hasPicture($body)
            ? $this->reply([$this->fact('emd_amount', 'Rs. 61,500/-', 2, 'Earnest Money Deposit: Rs. 61,500/- by online payment')])
            : $this->reply([])]);

        // The screen leaves out only the picture pages it has no picture of: none here.
        app(ReadDesk::class)->start($read->refresh(), $this->owner, null, null, null, array_values(array_diff(TenderReads::picturePages($read), TenderReads::storedPictures($read))));
        $this->readAll($read);

        $this->assertCount(3, $this->sent);
        $this->assertSame(1, count(array_filter($this->sent, $this->hasPicture(...))));
        $this->assertSame(DocumentRead::READY, $read->refresh()->status);

        $fact = $this->facts()->sole();
        $this->assertSame([2, 'image', null], [(int) $fact->page, $fact->source, $fact->quote_found]);

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('Read from the page picture. Check it against the page.')
            ->assertDontSee('Pages not read');
    }

    public function test_answering_again_sets_uploaded_pictures_aside_so_no_page_is_read_twice(): void
    {
        $read = $this->preparedRead();
        $images = fn () => DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('pass', 'image')->orderBy('page_no')->pluck('status', 'page_no')->all();

        $this->answer($read, 'en', 'no');
        $this->uploadPicture($read, 1)->assertCreated();
        $this->uploadPicture($read, 2)->assertCreated();
        $this->assertSame([1 => 'waiting', 2 => 'waiting'], $images());

        // The text matches after all: the pictures wait no longer, and the text is read instead.
        $this->answer($read, 'en', 'yes');
        $this->assertSame([1 => 'skipped', 2 => 'skipped'], $images());
        $this->assertSame([[1, 3, 'text']], DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('status', 'waiting')->get()
            ->map(fn ($row) => [(int) $row->page_no, (int) $row->page_to, $row->pass])->all());

        // And back: the pictures wait again and no run of text is left.
        $this->answer($read, 'en', 'no');
        $this->assertSame([1 => 'waiting', 2 => 'waiting'], $images());

        $this->fakeReader([$this->reply([])]);
        app(ReadDesk::class)->start($read->refresh(), $this->owner, null, null, null, [3]);
        $this->readAll($read);

        $this->assertCount(2, $this->sent);
        $this->assertTrue($this->hasPicture($this->sent[0]) && $this->hasPicture($this->sent[1]));

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('Pages not read: 3, because its text does not match the document.');
    }

    public function test_a_pdf_over_the_size_limit_is_read_as_pictures_a_range_of_pages_at_a_time(): void
    {
        config(['modules.tender_reading.max_bytes' => 200, 'modules.tender_reading.picture_pages' => 4]);
        $document = $this->storeDocument($this->tender);

        $read = DocumentRead::withoutGlobalScopes()->findOrFail($this->startRead($this->owner, $document)->assertCreated()->json('id'));
        Queue::assertNothingPushed();
        $this->assertSame([DocumentRead::PREPARED, TenderReads::SOURCE_PICTURES], [$read->status, TenderReads::source($read)]);

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('Only a PDF of up to 0 MB can be read as text, so its pages are read as page pictures.');
        $page->assertSee('Choose up to 4 pages. A longer document is read as several ranges, each with its own price.');
        $page->assertDontSee('Does the text on the left match the page on the right?');

        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'en'])
            ->assertSessionHasErrors(['page_from' => 'Choose the pages to read as pictures.', 'page_to' => 'Choose the pages to read as pictures.'])
            ->assertSessionDoesntHaveErrors('matches');
        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'en', 'page_from' => 2, 'page_to' => 6])
            ->assertSessionHasErrors(['page_to' => 'Up to 4 pages can be read as pictures at a time. Read the rest as another range.']);
        $this->assertFalse(TenderReads::answered($read->refresh()));

        $this->actingAs($this->owner)->post(route('tender-reads.mode', $read), ['language' => 'hi', 'page_from' => 2, 'page_to' => 3])->assertSessionHasNoErrors();
        $read->refresh();
        $this->assertSame([[2, 3], ['page_from' => 2, 'page_to' => 3]], [TenderReads::picturePages($read), $read->options['range']]);

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk();
        $page->assertSee('Pages to read as page pictures: 2, 3');
        $page->assertSee('Pictures uploaded: :done of :total', false);

        // Page 3 is left without a picture: it is not read, and the sheet says so.
        $this->uploadPicture($read, 2)->assertCreated();
        $this->fakeReader([$this->reply([$this->fact('completion_period', '9 (Nine) months', 2, 'Time allowed for completion: 9 (Nine) months')])]);
        app(ReadDesk::class)->start($read->refresh(), $this->owner, null, 2, 3, [3]);
        $this->readAll($read);

        $this->assertCount(1, $this->sent);
        $this->assertSame(2, (int) $this->facts()->sole()->page);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('Pages not read: 3, because it was not sent to be read.');
        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()->assertSee('Pages read: 1 of 2');
    }

    public function test_a_pdf_too_long_for_its_text_is_offered_as_pictures_and_a_damaged_one_is_not(): void
    {
        config(['modules.tender_reading.max_pages' => 2]);
        $document = $this->storeDocument($this->tender);

        $failed = $this->preparedRead($document);
        $this->assertSame([DocumentRead::FAILED, TenderTextRefused::TOO_MANY_PAGES], [$failed->status, $failed->error]);

        $page = $this->actingAs($this->owner)->get(route('tender-reads.view', $failed))->assertOk();
        $page->assertSee('This PDF has 3 pages. Up to 2 pages can be read as text.');
        $page->assertSee('Read pages as pictures');
        $page->assertSee('name="as" value="pictures"', false);

        // Someone who may only look is not offered it.
        $this->actingAs($this->qs)->get(route('tender-reads.view', $failed))->assertOk()->assertDontSee('Read pages as pictures');

        $response = $this->actingAs($this->owner)->post(route('tender-reads.store', ['tender' => $this->tender, 'document' => $document]), [
            'as' => 'pictures',
            'client_uuid' => 'c9a1b8e2-3f4d-4e5a-9b6c-7d8e9f0a1b2c',
        ]);
        $read = DocumentRead::withoutGlobalScopes()->where('client_uuid', 'c9a1b8e2-3f4d-4e5a-9b6c-7d8e9f0a1b2c')->sole();
        $response->assertRedirect(route('tender-reads.view', $read));
        $this->assertSame([TenderReads::SOURCE_PICTURES, DocumentRead::PREPARED], [TenderReads::source($read), $read->status]);
        $this->assertArrayNotHasKey('too_large', $read->options);

        $this->startRead($this->owner, $document, null, ['as' => 'sideways'])->assertStatus(422)->assertJsonValidationErrors('as');

        // A PDF that cannot be opened at all cannot be drawn either: nothing is offered.
        $damaged = $this->storeDocument($this->tender, "%PDF-1.4\n%%EOF\n", 'damaged.pdf');
        $broken = DocumentRead::withoutGlobalScopes()->findOrFail($this->startRead($this->owner, $damaged)->assertCreated()->json('id'));
        app(TenderTextPreparer::class)->prepare($broken->id);
        $this->assertSame(DocumentRead::FAILED, $broken->refresh()->status);
        $this->assertNotContains($broken->error, [TenderTextRefused::TOO_MANY_PAGES, TenderTextRefused::TOO_LARGE, TenderTextRefused::TOO_SLOW]);
        $this->actingAs($this->owner)->get(route('tender-reads.view', $broken))->assertOk()->assertDontSee('Read pages as pictures');
    }

    public function test_who_may_read_a_tender_document_as_pictures(): void
    {
        $document = $this->storeDocument($this->tender, $this->png(1240, 1754), 'notice.png');

        $this->startRead($this->qs, $document)->assertForbidden();
        $read = DocumentRead::withoutGlobalScopes()->findOrFail($this->startRead($this->owner, $document)->assertCreated()->json('id'));

        // qs may watch the read, but not answer for it or send its pages.
        $this->actingAs($this->qs)->get(route('tender-reads.view', $read))->assertOk()->assertDontSee('name="language"', false);
        $this->actingAs($this->qs)->post(route('tender-reads.mode', $read), ['language' => 'en'])->assertForbidden();

        $outsider = $this->member($this->tenant('Godavari Infra'), 'owner');
        $this->actingAs($outsider)->get(route('tender-reads.view', $read))->assertNotFound();
        $this->actingAs($outsider)->post(route('tender-reads.mode', $read), ['language' => 'en'])->assertNotFound();
        $this->uploadPicture($read, 1, $outsider)->assertNotFound();

        // A picture of another tender is not found under this one.
        $other = $this->makeTender($this->organization, 'Another sample road');
        $this->startRead($this->owner, $this->storeDocument($other, $this->png(1240, 1754), 'other.png'))->assertNotFound();

        $this->assertSame(1, DocumentRead::withoutGlobalScopes()->count());
        $this->assertSame([], TenderReads::storedPictures($read));
    }
}
