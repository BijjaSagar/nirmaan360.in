<?php

namespace Tests\Feature\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * A company with a project and a draft read, and the shared read routes called the way the capture
 * script and the phone call them. The stand-in readers of PageReadingTestCase are registered: "boq"
 * (two passes over a picture), "tender" (text and pictures) and "slip" (one person's own).
 */
abstract class ReadRoutesTestCase extends PageReadingTestCase
{
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->project = $this->makeProject($this->organization);
    }

    /** A read as a feature's create route leaves it: a draft in a project, under the current notice. */
    protected function draft(array $overrides = [], ?User $by = null): DocumentRead
    {
        return $this->makeRead($this->organization, $by ?? $this->owner, [
            'status' => DocumentRead::DRAFT,
            'project_id' => $this->project->id,
            'pages_total' => 3,
            'consent_version' => AiDocumentConsent::VERSION,
            ...$overrides,
        ]);
    }

    /** A draft whose pages are all uploaded, without going through the upload route. */
    protected function draftWithPages(int $pages = 3, array $overrides = [], ?User $by = null): DocumentRead
    {
        $read = $this->draft(['pages_total' => $pages, ...$overrides], $by);

        foreach (range(1, $pages) as $number) {
            $this->storedPage($read, $number, ['status' => DocumentReadPage::WAITING, 'pass' => $read->kind === 'boq' ? 'rows' : ($read->kind === 'tender' ? 'image' : 'main')]);
        }

        return $read;
    }

    /** A page picture as a browser draws it: large enough for every stand-in reader. */
    protected function picture(int $width = 640, int $height = 900): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('page.jpg', $this->jpeg($width, $height));
    }

    protected function file(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** Calls a shared read route from the browser, as the capture script does. */
    protected function web(User $user, string $method, DocumentRead|int $read, string $path = '', array $data = []): TestResponse
    {
        $id = $read instanceof DocumentRead ? $read->id : $read;

        return $this->actingAs($user)->json($method, "/document-reads/{$id}{$path}", $data);
    }

    /** Calls the same route from the phone, with its token. */
    protected function api(User $user, string $method, DocumentRead|int $read, string $path = '', array $data = []): TestResponse
    {
        Sanctum::actingAs($user);
        $id = $read instanceof DocumentRead ? $read->id : $read;

        return $this->json($method, "/api/v1/document-reads/{$id}{$path}", $data);
    }

    protected function upload(DocumentRead $read, int $pageNo, string $part = 'full', ?UploadedFile $image = null, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->owner)->post("/document-reads/{$read->id}/pages", [
            'page_no' => $pageNo,
            'part' => $part,
            'image' => $image ?? $this->picture(),
        ], ['Accept' => 'application/json']);
    }

    /** @return list<DocumentReadPage> The read's page rows, in reading order. */
    protected function rows(DocumentRead $read): array
    {
        return DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->orderBy('page_no')->orderBy('id')->get()->all();
    }

    protected function row(DocumentRead $read, int $pageNo, string $pass = 'rows', string $part = 'full'): ?DocumentReadPage
    {
        return DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)
            ->where('page_no', $pageNo)->where('pass', $pass)->where('part', $part)->first();
    }

    /** @return list<string> Every file in the read's folder. */
    protected function filesOf(DocumentRead $read): array
    {
        return Storage::allFiles(ReadFiles::directory($read));
    }
}
