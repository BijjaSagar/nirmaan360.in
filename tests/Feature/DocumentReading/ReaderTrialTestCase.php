<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\ReadTrialPage;
use App\Models\AuditLog;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Models\User;
use App\Services\DocumentReading\ReaderTrials;
use App\Services\DocumentReading\TrialRunner;
use App\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * The platform admin and the dry runs. The setting is the one a dry run is made for: a key is
 * saved and stand-in readers are deployed, but the readers are NOT released to companies and the
 * company in the test has NOT agreed to anything. A dry run needs neither.
 *
 * Page jobs are run by hand, so each test sees one step at a time.
 */
abstract class ReaderTrialTestCase extends PageReadingTestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        app(PlatformSettings::class)->set(['ai_readers_released' => null, 'ai_tier_monthly_cap_usd' => null]);
        $this->consent($this->organization, false, null);

        // Platform staff sign in with an account of their own company, as on production.
        $this->admin = $this->member($this->tenant('Nirmaan Platform Staff'), 'owner');
        $this->admin->forceFill(['is_platform_admin' => true])->save();
    }

    protected function trials(): ReaderTrials
    {
        return app(ReaderTrials::class);
    }

    /** A dry run of the stand-in BOQ reader's first pass on page pictures, unless told otherwise. */
    protected function makeTrial(array $overrides = []): ReaderTrial
    {
        return $this->trials()->create($this->admin, ['allowed' => true, 'kind' => 'boq', 'pass' => 'rows', 'pages_total' => 2, ...$overrides]);
    }

    /** A page picture large enough for the stand-in reader, different for each page. */
    protected function pagePicture(int $number = 1): string
    {
        return $this->jpeg(700 + $number, 900);
    }

    protected function uploadPage(ReaderTrial $trial, int $number = 1, ?string $bytes = null): TestResponse
    {
        return $this->actingAs($this->admin)->post("/admin/ai-reader/trials/{$trial->id}/pages", [
            'page_no' => $number,
            'image' => UploadedFile::fake()->createWithContent("page-{$number}.jpg", $bytes ?? $this->pagePicture($number)),
        ], ['Accept' => 'application/json']);
    }

    /** A dry run with its pages stored, ready to start. */
    protected function trialWithPages(int $pages = 2, array $overrides = []): ReaderTrial
    {
        $trial = $this->makeTrial(['pages_total' => $pages, ...$overrides]);

        foreach (range(1, $pages) as $number) {
            $this->trials()->storePicture($trial, $number, $this->pagePicture($number), $this->admin);
        }

        return $trial->refresh();
    }

    protected function startTrial(ReaderTrial $trial): ReaderTrial
    {
        return $this->trials()->start($trial, $this->admin);
    }

    /** What a worker does with the job of a dry-run page. A new runner each time, as each job gets. */
    protected function runTrialPage(ReaderTrialPage|int $page): void
    {
        app(TrialRunner::class)->run($page instanceof ReaderTrialPage ? $page->id : $page);
    }

    /** Runs the page jobs that were handed to the queue until none is left, as a worker would. */
    protected function workTheQueue(int $most = 20): int
    {
        $done = 0;

        while ($done < $most) {
            $next = collect($this->queuedTrialPages())->slice($done)->first();

            if ($next === null) {
                break;
            }

            $this->runTrialPage($next);
            $done++;
        }

        return $done;
    }

    /** @return list<int> The page ids of the dry-run jobs handed to the queue, in order. */
    protected function queuedTrialPages(): array
    {
        return Queue::pushed(ReadTrialPage::class)->map(fn (ReadTrialPage $job) => $job->pageId)->values()->all();
    }

    /** @return list<ReaderTrialPage> */
    protected function pagesOf(ReaderTrial $trial): array
    {
        return ReaderTrialPage::query()->where('reader_trial_id', $trial->id)->orderBy('page_no')->orderBy('id')->get()->all();
    }

    protected function pageOf(ReaderTrial $trial, int $number = 1, ?string $pass = null): ReaderTrialPage
    {
        return ReaderTrialPage::query()->where('reader_trial_id', $trial->id)->where('page_no', $number)->where('pass', $pass ?? $trial->pass ?? 'main')->sole();
    }

    /** @return list<AuditLog> Audit rows that belong to no company. */
    protected function platformAudits(string $action): array
    {
        return AuditLog::withoutGlobalScopes()->whereNull('organization_id')->where('action', $action)->orderBy('id')->get()->all();
    }
}
