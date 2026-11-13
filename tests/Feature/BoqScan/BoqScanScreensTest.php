<?php

namespace Tests\Feature\BoqScan;

use App\Models\AiDocumentSettings;
use App\Models\CustomRole;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\BoqScan\BoqReadKind;
use App\Services\BoqScan\BoqScanScreen;
use App\Services\DocumentReading\ReadFiles;
use App\Services\DocumentReading\ReadKinds;
use App\Services\Reports\BoqPdfImport;
use App\Support\PlatformSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Feature\DocumentReading\Support\FakeKind;

/**
 * The web screens of the scanned BOQ reader: the scan screen of a project, the screen of one read
 * through each of its states, the offer on the BOQ in Excel page and the PDF's pages beside the
 * preview of a text PDF. Who may open each, what each says, and that none of them depends on the
 * provider, the limits or the consent switch except where a scan is started.
 */
class BoqScanScreensTest extends BoqScanTestCase
{
    /** Where the two hook partials are put in this test, as the module puts them in place when it goes live. */
    private ?string $hooks = null;

    protected function setUp(): void
    {
        parent::setUp();
        // No screen may ever reach the AI provider.
        Http::fake();
    }

    protected function tearDown(): void
    {
        if ($this->hooks !== null) {
            File::deleteDirectory($this->hooks);
        }

        parent::tearDown();
    }

    private function holding(array $permissions): User
    {
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);
        $user = $this->member($this->organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    private function limited(string $role, Project ...$projects): User
    {
        $user = $this->member($this->organization, $role);
        app(TeamAccessService::class)->applyProjects($user, true, array_map(fn (Project $project) => $project->id, $projects));

        return $user->refresh();
    }

    /** @return array<string, User> People of the company who must never open these screens. */
    private function refused(): array
    {
        return [
            'engineer' => $this->member($this->organization, 'engineer'),
            'store' => $this->member($this->organization, 'store'),
            'viewer' => $this->member($this->organization, 'viewer'),
            'accounts' => $this->member($this->organization, 'accounts'),
            'boq.manage without money.view' => $this->holding(['project.view', 'boq.view', 'boq.manage', 'agents.view', 'agents.use']),
        ];
    }

    /**
     * The two partials the reports screens include from this module, in place as the module puts them
     * when it goes live: modules.boq_scan._offer and modules.boq_scan._pdf_pages, each including the
     * module's own partial.
     */
    private function hookPartials(): void
    {
        $this->hooks = storage_path('framework/testing/boq-scan-hooks-'.uniqid());
        File::ensureDirectoryExists($this->hooks.'/modules/boq_scan');
        File::put($this->hooks.'/modules/boq_scan/_offer.blade.php', "@include('modules.boq_scan.partials.offer')");
        File::put($this->hooks.'/modules/boq_scan/_pdf_pages.blade.php', "@include('modules.boq_scan.partials.pdf-pages')");
        View::addLocation($this->hooks);
    }

    /** The stored picture of a page that was read, as the reader stored it. */
    private function picture(DocumentRead $read, int $number): void
    {
        $path = ReadFiles::page($read, $number, BoqReadKind::ROWS, 'full');
        Storage::put($path, $this->jpeg(40, 56));

        DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->where('page_no', $number)->where('pass', BoqReadKind::ROWS)
            ->update(['image_path' => $path, 'image_sha256' => hash('sha256', $this->jpeg(40, 56))]);
    }

    public function test_the_scan_screen_is_there_only_while_the_reader_kind_is_registered(): void
    {
        // The app was booted with the module's config file, which does not register the kind yet.
        $this->assertSame([], (require config_path('modules/boq_scan.php'))['read_kinds']);
        $this->assertFalse(Route::has('boq-scans.create'));
        $this->assertTrue(Route::has('boq-scans.show'), 'The screen of a read is always there; it answers as if nothing existed while the kind is not registered.');

        config(['modules.boq_scan.read_kinds' => []]);
        $this->withScanScreen();
        $this->assertFalse(Route::has('boq-scans.create'));

        config(['modules.boq_scan.read_kinds' => [BoqReadKind::class]]);
        $this->withScanScreen();
        $this->assertTrue(Route::has('boq-scans.create'));
        $this->assertSame('projects/{project}/boq-scans/create', Route::getRoutes()->getByName('boq-scans.create')->uri());
        $this->assertEqualsCanonicalizing(
            ['web', 'auth', 'active', 'feature:reports', 'feature:ai_agents', 'can:boq.manage', 'can:money.view'],
            Route::getRoutes()->getByName('boq-scans.create')->gatherMiddleware(),
        );
    }

    public function test_who_may_open_the_scan_screen(): void
    {
        $this->withScanScreen();
        $url = route('boq-scans.create', $this->project);

        foreach ($this->refused() as $who => $user) {
            $this->actingAs($user)->get($url)->assertForbidden($who);
        }

        $rival = $this->tenant('Godavari Infra');
        $this->consent($rival);
        $elsewhere = $this->makeProject($this->organization, ['code' => 'SB2', 'name' => 'South Block']);
        $this->actingAs($this->member($rival, 'owner'))->get($url)->assertNotFound();
        $this->actingAs($this->limited('pm', $elsewhere))->get($url)->assertNotFound();

        $this->actingAs($this->owner)->get($url)->assertOk()
            ->assertSee('Read a scanned BOQ')
            ->assertSee('boqScanUpload(', false)
            ->assertSee('One PDF of at most 10 MB and 60 pages, or up to 60 photos. Leave out pages that have no BOQ lines.')
            ->assertSee('What the AI prepares is a draft until a named person confirms it.');
        $this->actingAs($this->member($this->organization, 'pm'))->get($url)->assertOk()->assertSee('boqScanUpload(', false);

        $this->organization->update(['plan' => 'contractor']);
        $this->actingAs($this->owner->fresh())->get($url)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_scan_screen_says_why_a_scan_cannot_be_started_and_never_fails(): void
    {
        $this->withScanScreen();
        $url = route('boq-scans.create', $this->project);
        $settings = app(PlatformSettings::class);
        $pm = $this->member($this->organization, 'pm');

        $blocked = function (User $user, string $code, string $sentence) use ($url) {
            return $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('A scan cannot be started now')
                ->assertSee('data-boq-scan-blocked="'.$code.'"', false)
                ->assertSee($sentence)
                ->assertSee('Meanwhile the Excel file of the estimate can be uploaded on the BOQ in Excel page.')
                ->assertDontSee('boqScanUpload(', false);
        };

        $settings->set(['ai_readers_released' => '0']);
        $blocked($this->owner, 'not_released', 'The readers are not switched on yet.');
        $this->release();

        $settings->set(['anthropic_api_key' => null]);
        $blocked($this->owner, 'not_connected', 'The AI service is not available right now. Try again later.');
        $this->connect();

        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);
        $blocked($this->owner, 'company_limit', 'The readers are switched off for your company. Please contact support.');
        AiDocumentSettings::for($this->organization)->update(['platform_block' => false]);

        // An owner is shown the way to the notice; a project manager, who cannot switch it, is not.
        $this->consent($this->organization, false);
        $sentence = 'Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.';
        $blocked($this->owner, 'consent_required', $sentence)->assertSee(route('ai-documents.edit'));
        $blocked($pm, 'consent_required', $sentence)->assertDontSee(route('ai-documents.edit'));
        $this->consent($this->organization);

        $reviewer = $this->holding(['project.view', 'boq.view', 'boq.manage', 'money.view']);
        $blocked($reviewer, 'agents_use', 'You can review and confirm scans, but starting one needs permission to use the AI agents. Ask an owner or admin.');

        $this->assertNull(app(BoqScanScreen::class)->blocker($this->owner));

        // Another module's stand-in under the BOQ key is not this module's reader.
        config(['modules.boq_scan.read_kinds' => [], 'modules.reader_stand_ins.read_kinds' => [FakeKind::class]]);
        $this->assertSame('not_released', app(BoqScanScreen::class)->blocker($this->owner)['code']);
        $this->assertFalse(app(BoqScanScreen::class)->installed());

        Http::assertNothingSent();
    }

    public function test_the_scan_screen_lists_the_scans_of_its_project_that_are_still_open(): void
    {
        $this->withScanScreen();
        $listed = [
            $this->scan(['status' => DocumentRead::READY, 'source_name' => 'Ready estimate.pdf']),
            $this->scan(['status' => DocumentRead::RUNNING, 'source_name' => 'Running estimate.pdf']),
            $this->scan(['status' => DocumentRead::DRAFT, 'source_name' => 'Draft estimate.pdf']),
            $this->scan(['status' => DocumentRead::FAILED, 'source_name' => 'Failed estimate.pdf']),
        ];
        $other = $this->makeProject($this->organization, ['code' => 'SB2', 'name' => 'South Block']);
        $hidden = [
            $this->scan(['status' => DocumentRead::CONFIRMED, 'source_name' => 'Written estimate.pdf']),
            $this->scan(['status' => DocumentRead::DISCARDED, 'source_name' => 'Thrown estimate.pdf']),
            $this->scan(['status' => DocumentRead::READY, 'project_id' => $other->id, 'source_name' => 'Other project.pdf']),
            $this->scan(['status' => DocumentRead::READY, 'kind' => 'tender', 'source_name' => 'A tender.pdf']),
        ];

        $page = $this->actingAs($this->owner)->get(route('boq-scans.create', $this->project))->assertOk()->assertSee('Scans of this project still open');

        foreach ($listed as $read) {
            $page->assertSee(route('boq-scans.show', $read))->assertSee($read->source_name);
        }

        foreach ($hidden as $read) {
            $page->assertDontSee($read->source_name);
        }

        $page->assertSeeInOrder(['Failed estimate.pdf', 'Draft estimate.pdf', 'Running estimate.pdf', 'Ready estimate.pdf'])
            ->assertSee('Ready for review')->assertSee('Could not be read');
    }

    public function test_who_may_open_the_screen_of_a_read(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);
        $url = route('boq-scans.show', $read);

        foreach ($this->refused() as $who => $user) {
            $this->actingAs($user)->get($url)->assertForbidden($who);
        }

        $rival = $this->tenant('Godavari Infra');
        $this->consent($rival);
        $elsewhere = $this->makeProject($this->organization, ['code' => 'SB2', 'name' => 'South Block']);
        $this->actingAs($this->member($rival, 'owner'))->get($url)->assertNotFound();
        $this->actingAs($this->limited('pm', $elsewhere))->get($url)->assertNotFound();
        $this->actingAs($this->limited('pm', $this->project))->get($url)->assertOk();

        $tender = $this->scan(['kind' => 'tender', 'project_id' => null]);
        $this->actingAs($this->owner)->get(route('boq-scans.show', $tender))->assertNotFound();

        // While the kind is not registered, a read of it cannot be told from one that does not exist.
        config(['modules.boq_scan.read_kinds' => []]);
        app()->forgetInstance(ReadKinds::class);
        $this->actingAs($this->owner)->get($url)->assertNotFound();
        config(['modules.boq_scan.read_kinds' => [BoqReadKind::class]]);
        app()->forgetInstance(ReadKinds::class);

        $this->organization->update(['plan' => 'contractor']);
        $this->actingAs($this->owner->fresh())->get($url)->assertForbidden();
    }

    public function test_each_state_of_a_read_has_its_screen(): void
    {
        $this->withScanScreen();
        $this->actingAs($this->owner);

        // Not started: the upload goes on where it stopped.
        $draft = $this->scan(['status' => DocumentRead::DRAFT, 'pages_total' => 3]);
        $this->storedPage($draft, 2, ['status' => DocumentReadPage::WAITING]);
        $this->get(route('boq-scans.show', $draft))->assertOk()
            ->assertSee('Upload the pages')->assertSee('boqScanUpload(', false)->assertSee('Cancel this scan')->assertSee('boqScanDiscard(', false);
        $this->assertSame([2], app(BoqScanScreen::class)->show($draft, $this->owner)['status']['stored_pages']);

        // Reading, and paused.
        $running = $this->startedRead($this->owner, '100', ['project_id' => $this->project->id, 'pages_total' => 2]);
        $this->get(route('boq-scans.show', $running))->assertOk()
            ->assertSee('Reading the pages')->assertSee('boqScanProgress(', false)
            ->assertSee('You can close this page. You will get a notification when the reading is done.')
            ->assertDontSee('boqScanReview(', false);

        // Ready: the review, with what may be said about it.
        $ready = $this->scan();
        $this->cleanPage($ready);
        $this->picture($ready, 1);
        $this->get(route('boq-scans.show', $ready))->assertOk()
            ->assertSee('boqScanReview(', false)
            ->assertSee('Each line is shown beside the part of the page the AI says it read. That position is approximate. Every line is confirmed by a person before it is saved.')
            ->assertSee('A line without a mark has two readings that multiply out. That makes an error less likely, not impossible. Descriptions and units are not protected by any check: read them.')
            ->assertSee('Totals of the whole document')
            ->assertSee('BOQ value after import (quantity × rate)')
            ->assertSee('Excavation in ordinary soil');
        $rowsPage = DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $ready->id)->where('pass', 'rows')->value('id');
        $readings = collect(app(BoqScanScreen::class)->show($ready, $this->owner)['page']['page']['readings']);
        $this->assertSame(route('document-reads.pages.image', [$ready, $rowsPage]), $readings->firstWhere('pass', 'rows')['image_url'], 'The page picture beside the rows, from the shared image route.');

        // Failed: what can still be done.
        $failed = $this->scan(['status' => DocumentRead::FAILED]);
        $this->makePage($failed, 1, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off', 'image_path' => ReadFiles::page($failed, 1, 'rows', 'full')]);
        $this->get(route('boq-scans.show', $failed))->assertOk()
            ->assertSee('No page of this document could be read')->assertSee('Discard this scan')->assertSee('boqScanProgress(', false);

        // Written to the BOQ.
        $confirmed = $this->scan(['status' => DocumentRead::CONFIRMED, 'review' => ['confirmed' => ['lines' => 3, 'created' => 2, 'updated' => 1, 'unchanged' => 0, 'confirmed_by_row' => 1, 'confirmed_by_page' => 2, 'cells_typed' => 4, 'rows_added_by_hand' => 0, 'rows_left_out' => 1]]]);
        $this->get(route('boq-scans.show', $confirmed))->assertOk()
            ->assertSee('Written to the BOQ')->assertSee('Confirmed with the page')->assertSee(route('boq.index', $this->project))
            ->assertDontSee('boqScanReview(', false)->assertDontSee('reader-capture', false);

        // Ended without being written.
        foreach ([
            DocumentRead::DISCARDED => 'This scan was discarded. Nothing was written to the BOQ.',
            DocumentRead::CANCELLED => 'This scan was cancelled. Nothing was written to the BOQ.',
            DocumentRead::EXPIRED => 'This scan was not confirmed in time and has expired. Nothing was written to the BOQ.',
        ] as $status => $sentence) {
            $this->get(route('boq-scans.show', $this->scan(['status' => $status])))->assertOk()
                ->assertSee($sentence)->assertSee(route('boq-scans.create', $this->project));
        }

        Http::assertNothingSent();
    }

    public function test_the_review_opens_on_the_first_page_that_still_needs_something(): void
    {
        $read = $this->scan();
        $this->cleanPage($read, 1);
        $this->stage($read, 2, [['4', 'Brickwork in CM 1:6', 'cum', '10.000', '100.00']], [['4', '1,500.00']]);
        $this->confirmAll($read);

        // Page 1 is all confirmed; page 2 has a line that does not multiply out.
        $shown = app(BoqScanScreen::class)->show($read, $this->owner);
        $this->assertSame(2, $shown['page']['page']['page_no']);
        $this->assertSame('Brickwork in CM 1:6', $shown['page']['rows'][0]['description']);

        $this->actingAs($this->owner)->get(route('boq-scans.show', $read))->assertOk()->assertSee('Brickwork in CM 1:6')->assertDontSee('Excavation in ordinary soil');
    }

    public function test_looking_at_a_read_never_depends_on_the_provider_the_switches_or_the_consent(): void
    {
        $ready = $this->scan();
        $this->cleanPage($ready);
        $running = $this->startedRead($this->owner, '100', ['project_id' => $this->project->id, 'pages_total' => 2]);

        $this->consent($this->organization, false);
        app(PlatformSettings::class)->set(['anthropic_api_key' => null, 'ai_readers_released' => '0']);
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);

        $this->actingAs($this->owner)->get(route('boq-scans.show', $ready))->assertOk()->assertSee('boqScanReview(', false);
        $this->get(route('boq-scans.show', $running))->assertOk()->assertSee('boqScanProgress(', false);

        // Someone who may review but not start a read sees the review, without the buttons that send a page again.
        $reviewer = $this->holding(['project.view', 'boq.view', 'boq.manage', 'money.view']);
        $this->assertFalse(app(BoqScanScreen::class)->show($ready, $reviewer)['canSend']);
        $this->assertTrue(app(BoqScanScreen::class)->show($ready, $this->owner)['canSend']);
        $this->actingAs($reviewer)->get(route('boq-scans.show', $ready))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_boq_in_excel_page_offers_the_scan_reader_and_keeps_a_copy_of_a_chosen_pdf(): void
    {
        $this->hookPartials();
        $pm = $this->member($this->organization, 'pm');
        $url = route('boq.excel.index', $this->project);
        $key = 'boq-pdf:'.$pm->id.':'.$this->project->id;

        // The module's scan screen is not registered: no offer, but the browser still keeps its copy of a PDF for the preview.
        $this->actingAs($pm)->get($url)->assertOk()
            ->assertDontSee('Scanned copy or photos?')->assertDontSee('data-boq-scan-offer', false)
            ->assertSee('window.BoqScanKeptPdf', false)->assertSee($key, false);

        $this->withScanScreen();
        $this->get($url)->assertOk()
            ->assertSee('Scanned copy or photos?')->assertSee('Read them here (uses AI credits)')
            ->assertSee(route('boq-scans.create', $this->project))
            ->assertDontSee('This PDF is a scanned copy. It can be read as a scan instead.');

        // After a PDF was refused as a scan, the offer says this file can be read as one.
        $this->withSession(['boq_import' => ['errors' => [['row' => 0, 'field' => null, 'message' => 'Refused.']], 'more' => 0, 'pdf' => true, 'scanned' => true]])
            ->get($url)->assertOk()->assertSee('This PDF is a scanned copy. It can be read as a scan instead.');

        // Readers off: no offer.
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $this->get($url)->assertOk()->assertDontSee('Scanned copy or photos?')->assertSee('window.BoqScanKeptPdf', false);
        $this->release();

        // Someone who may see the BOQ but not import it gets neither.
        $engineer = $this->member($this->organization, 'engineer');
        $this->actingAs($engineer)->get($url)->assertOk()->assertDontSee('Scanned copy or photos?')->assertDontSee('window.BoqScanKeptPdf', false);

        // Someone who may import but not use the AI agents is not offered a scan.
        $importer = $this->holding(['project.view', 'boq.view', 'boq.manage', 'money.view']);
        $this->actingAs($importer)->get($url)->assertOk()->assertDontSee('Scanned copy or photos?')->assertSee('window.BoqScanKeptPdf', false);

        Http::assertNothingSent();
    }

    public function test_the_pages_of_a_text_pdf_are_drawn_beside_its_preview_from_the_browsers_own_copy(): void
    {
        $this->hookPartials();
        $pm = $this->member($this->organization, 'pm');
        $html = '<html><body style="font-family:DejaVu Sans;font-size:9pt"><table style="border-collapse:collapse;width:100%" border="1">'
            .'<tr><th>Sr. No.</th><th>Description of Items</th><th>Qty.</th><th>Rate</th><th>Unit</th><th>Amount in Rs.</th></tr>'
            .'<tr><td>1</td><td>Excavation for foundation in soft murum</td><td>10</td><td>350</td><td>cum</td><td>3500</td></tr>'
            .'<tr><td>2</td><td>Brickwork in cement mortar for walls</td><td>4</td><td>5000</td><td>cum</td><td>20000</td></tr>'
            .'<tr><td></td><td>Total</td><td></td><td></td><td></td><td>23500</td></tr></table></body></html>';
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, Pdf::loadHTML($html)->output());

        $staged = app(BoqPdfImport::class)->stage($this->project, $pm, $path, 'tower-estimate.pdf');
        $this->assertArrayHasKey('token', $staged, (string) ($staged['error'] ?? ''));

        $this->actingAs($pm)->withSession([BoqPdfImport::SESSION => $staged['token']])
            ->get(route('boq.pdf.preview', $this->project))->assertOk()
            ->assertSee('Pages of the PDF')
            ->assertSee('boqPdfPages(', false)
            ->assertSee('tower-estimate.pdf', false)
            ->assertSee('boq-pdf:'.$pm->id.':'.$this->project->id, false)
            // Without a copy in this browser, the person is told to open the PDF, as the preview says without this module.
            ->assertSee('Open the PDF beside this page and compare each row with it.')
            ->assertSee('I compared these rows with the pages of the PDF');

        @unlink($path);
        Http::assertNothingSent();
    }
}
