<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadAttempt;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReadAudit;
use App\Services\DocumentReading\ReadFiles;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\TenantData;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Erasing a company erases what its readers kept: every row, every page picture, the original PDF,
 * the extracted text, and the files in its reader folder that no row points at.
 */
class CompanyPurgeOfReadsTest extends PageReadingTestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $log) {
            $this->logged[] = $log;
        });
    }

    /** A read with two page pictures, the original PDF and the extracted text, as a real one leaves them. */
    private function readWithFiles(Organization $organization, User $by, string $status): DocumentRead
    {
        $read = $this->makeRead($organization, $by, [
            'status' => $status,
            'consent_version' => AiDocumentConsent::VERSION,
            'pages_total' => 2,
            'credits_cap' => '40',
            'credits_held' => $status === DocumentRead::RUNNING ? '40' : '0',
        ]);

        $first = $this->storedPage($read, 1, ['status' => DocumentReadPage::READ, 'attempts' => 1, 'result' => ['page_kind' => 'table']]);
        $this->storedPage($read, 2, ['status' => DocumentReadPage::WAITING]);
        $this->settledCall($first, ['cost_usd' => '0.05', 'credits' => '6']);

        Storage::put(ReadFiles::source($read), '%PDF-1.4 a test file');
        $read->forceFill(['source_path' => ReadFiles::source($read), 'source_name' => 'estimate.pdf'])->save();
        ReadFiles::putTexts($read, [1 => 'Text of page one', 2 => 'Text of page two']);

        ReadAudit::started($read, $by, 2);

        return $read->refresh();
    }

    /** @return list<string> */
    private function filesOf(int $organizationId): array
    {
        return Storage::allFiles("org-{$organizationId}");
    }

    private function purgeAfterGrace(Organization $organization, User $owner): void
    {
        app(CompanyDeletionService::class)->request($organization, $owner, $organization->name);

        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        Auth::forgetGuards();

        $errors = array_map(fn (MessageLogged $log) => $log->message.' '.json_encode($log->context), array_filter($this->logged, fn (MessageLogged $log) => $log->level === 'error'));
        $this->assertSame(1, app(CompanyDeletionService::class)->purgeDue(), implode("\n", $errors));
    }

    /** @return list<MessageLogged> */
    private function fileWarnings(): array
    {
        return array_values(array_filter($this->logged, fn (MessageLogged $log) => $log->message === 'Files from a deleted company could not be removed'));
    }

    public function test_a_company_with_reads_in_every_state_is_erased_with_every_file_of_theirs(): void
    {
        $id = $this->organization->id;
        $this->setCredits($this->organization, '500');
        $reads = [];

        foreach ([...DocumentRead::OPEN, DocumentRead::READY, DocumentRead::FAILED, ...DocumentRead::CLOSED] as $status) {
            $reads[$status] = $this->readWithFiles($this->organization, $this->owner, $status);
        }

        $this->assertCount(12, $reads);
        $first = $reads[DocumentRead::RUNNING];

        // A page whose picture was stored while its row could not be saved: no table row names this file.
        $orphan = ReadFiles::page($first, 9, 'rows');
        Storage::put($orphan, 'a page nobody recorded');
        // Halves of a cut-off page whose rows were replaced afterwards.
        Storage::put(ReadFiles::page($first, 1, 'rows', 'top'), 'top half');
        Storage::put(ReadFiles::page($first, 1, 'rows', 'bottom'), 'bottom half');
        // The folder of a read whose row is gone altogether.
        Storage::put("org-{$id}/document-reads/999999/p1-rows-full.jpg", 'left behind');
        Storage::put("org-{$id}/document-reads/999999/text.json", '{"pages":{"1":"left behind"}}');

        // A page that was uploaded again before the read started: the same name, a new picture.
        $again = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $reads[DocumentRead::DRAFT]->id)->where('page_no', 2)->sole();
        Storage::put($again->image_path, 'the second upload of page two');
        $again->forceFill(['image_sha256' => hash('sha256', 'the second upload of page two')])->save();

        // Another company with a read of its own, which must come through whole.
        $other = $this->tenant('Godavari Infra');
        $otherOwner = $this->member($other, 'owner');
        $this->consent($other);
        $theirs = $this->readWithFiles($other, $otherOwner, DocumentRead::READY);
        Storage::put(ReadFiles::page($theirs, 9, 'rows'), 'their unrecorded page');
        $theirFiles = $this->filesOf($other->id);

        // 12 reads with two pictures, a PDF and a text file each, and the five loose files.
        $this->assertCount(12 * 4 + 5, $this->filesOf($id));
        $this->assertCount(5, $theirFiles);
        $this->assertNotContains($orphan, app(TenantData::class)->storedFiles($id), 'No table row points at the unrecorded page, so only the folder sweep can find it.');
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('organization_id', $id)->where('action', 'ai.read_started')->count());

        $this->purgeAfterGrace($this->organization, $this->owner);

        $this->assertDatabaseMissing('organizations', ['id' => $id]);
        $this->assertSame([], $this->filesOf($id), 'Nothing of the erased company is left on the disk.');
        $this->assertSame([], Storage::allDirectories("org-{$id}/document-reads"));

        foreach (array_keys(app(TenantData::class)->tables()) as $table) {
            if ($table !== 'company_deletion_requests') {
                $this->assertSame(0, DB::table($table)->where('organization_id', $id)->count(), "{$table} still holds the erased company's rows.");
            }
        }

        foreach (['document_reads', 'document_read_pages', 'document_read_attempts', 'ai_document_settings', 'credit_ledger', 'audit_logs'] as $table) {
            $this->assertArrayHasKey($table, app(TenantData::class)->tables(), "{$table} is found by the sweep.");
        }

        $this->assertSame(0, DocumentReadPage::withoutGlobalScopes()->whereIn('document_read_id', collect($reads)->pluck('id'))->count());
        $this->assertSame(0, DocumentReadAttempt::withoutGlobalScopes()->whereIn('document_read_id', collect($reads)->pluck('id'))->count());
        $this->assertSame([], $this->fileWarnings(), 'Every file could be removed, so nothing is logged about files.');

        // The other company keeps its read, its pages, its consent and every file, the unrecorded one included.
        $this->assertSame($theirFiles, $this->filesOf($other->id));
        $this->assertSame(DocumentRead::READY, $theirs->fresh()->status);
        $this->assertSame(2, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $theirs->id)->count());
        $this->assertSame(1, DocumentReadAttempt::withoutGlobalScopes()->where('document_read_id', $theirs->id)->count());
        $this->assertTrue(AiDocumentConsent::current($other));
        $this->assertNotNull(AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $other->id)->first());
    }

    public function test_a_company_whose_id_starts_another_companys_id_is_not_swept_with_it(): void
    {
        // "org-1" must never be read as the start of "org-12".
        $id = $this->organization->id;
        $lookalike = "org-{$id}7/document-reads/5/p1-rows-full.jpg";
        Storage::put($lookalike, 'a picture of another company');
        Storage::put("org-{$id}/document-reads/5/p1-rows-full.jpg", 'left behind');

        $this->purgeAfterGrace($this->organization, $this->owner);

        $this->assertSame([], $this->filesOf($id));
        Storage::assertExists($lookalike);
    }

    public function test_an_entry_that_does_not_name_the_company_is_never_deleted(): void
    {
        $id = $this->organization->id;
        config(['modules.reader_stand_ins.purge_directories' => ['shared-folder', '', '/', '../org-{org}', '/org-{org}/absolute/', ['org-{org}/nested'], 'org-{org}/extra-folder']]);

        Storage::put('shared-folder/everyone.txt', 'belongs to every company');
        Storage::put("org-{$id}/absolute/loose.txt", 'x');
        Storage::put("org-{$id}/extra-folder/loose.txt", 'x');
        Storage::put("org-{$id}/nested/loose.txt", 'x');
        Storage::put("org-{$id}/document-reads/1/p1-rows-full.jpg", 'x');

        $this->purgeAfterGrace($this->organization, $this->owner);

        Storage::assertExists('shared-folder/everyone.txt');
        // A leading or trailing slash is only tidied; the folder still names the company, so it goes.
        Storage::assertMissing("org-{$id}/absolute/loose.txt");
        Storage::assertMissing("org-{$id}/extra-folder/loose.txt");
        Storage::assertMissing("org-{$id}/document-reads/1/p1-rows-full.jpg");
        // Only plain text entries are read.
        Storage::assertExists("org-{$id}/nested/loose.txt");
    }

    public function test_every_folder_a_module_asks_to_be_swept_names_the_company(): void
    {
        $entries = collect(config('modules'))->except('reader_stand_ins')->pluck('purge_directories')->filter()->flatten();

        $this->assertContains('org-{org}/document-reads', $entries->all());

        foreach ($entries as $entry) {
            $this->assertIsString($entry);
            $this->assertStringContainsString('{org}', $entry, 'A swept folder without the company in its name would be every company\'s folder.');
            $this->assertStringNotContainsString('..', $entry);
        }

        // The reader's folder is the one ReadFiles writes into.
        $read = $this->makeRead($this->organization, $this->owner);
        $this->assertStringStartsWith(str_replace('{org}', (string) $this->organization->id, 'org-{org}/document-reads').'/', ReadFiles::directory($read).'/');
    }

    public function test_files_in_the_folder_that_could_not_be_removed_are_counted_without_their_names(): void
    {
        $id = $this->organization->id;
        $read = $this->readWithFiles($this->organization, $this->owner, DocumentRead::READY);
        Storage::put(ReadFiles::page($read, 7, 'rows'), 'Kulkarni estimate, unrecorded');
        Storage::put(ReadFiles::page($read, 8, 'rows'), 'unrecorded too');

        // A disk that refuses to delete folders, and refuses one recorded file as well.
        $refused = ReadFiles::page($read, 1, 'rows');
        $fake = Storage::disk();
        Storage::set(config('filesystems.default'), new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $refused) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private string $refused)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function delete($paths)
            {
                return (is_array($paths) ? $paths[0] : $paths) === $this->refused ? false : parent::delete($paths);
            }

            public function deleteDirectory($directory)
            {
                return false;
            }
        });

        $this->purgeAfterGrace($this->organization, $this->owner);

        // The rows are gone and the company is erased; what stayed on the disk is counted once each.
        $this->assertDatabaseMissing('organizations', ['id' => $id]);
        $this->assertSame(0, DocumentRead::withoutGlobalScopes()->where('organization_id', $id)->count());
        $this->assertEqualsCanonicalizing([$refused, ReadFiles::page($read, 7, 'rows'), ReadFiles::page($read, 8, 'rows')], $this->filesOf($id));

        $warning = collect($this->fileWarnings())->sole();
        $this->assertSame('warning', $warning->level);
        $this->assertSame(['organization_id' => $id, 'files' => 3], $warning->context);

        foreach ($this->logged as $log) {
            $this->assertStringNotContainsString('document-reads', $log->message.json_encode($log->context));
            $this->assertStringNotContainsString('Kulkarni', $log->message.json_encode($log->context));
        }
    }

    public function test_a_file_the_single_delete_refused_but_the_folder_sweep_removed_is_not_counted(): void
    {
        $id = $this->organization->id;
        $read = $this->readWithFiles($this->organization, $this->owner, DocumentRead::READY);

        // Every single delete inside the reader folder is refused; deleting the folder works.
        $fake = Storage::disk();
        Storage::set(config('filesystems.default'), new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                return str_contains((string) (is_array($paths) ? $paths[0] : $paths), '/document-reads/') ? false : parent::delete($paths);
            }
        });

        $this->purgeAfterGrace($this->organization, $this->owner);

        $this->assertSame([], $this->filesOf($id));
        $this->assertSame([], $this->fileWarnings());
        $this->assertNull(DocumentRead::withoutGlobalScopes()->find($read->id));
    }
}
