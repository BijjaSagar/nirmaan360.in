<?php

namespace Tests\Feature\Reports;

use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\DprService;
use App\Services\Reports\DprReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mockery;

/**
 * The field app's DPR PDF, WhatsApp link and measurement sheet (routes/api_modules/reports.php).
 * Each must open exactly what the web's button opens, to the same people, and no more.
 */
class ReportShareApiTest extends ReportsTestCase
{
    private const API = '/api/v1';

    /** A rate no other fixture uses, so its presence in a sheet means money was shown. */
    private const RATE = '98765.43';

    private function dpr(?Organization $org = null, ?User $by = null): Dpr
    {
        $org ??= $this->org;
        $project = $this->makeProject($org, ['name' => 'Riverside Tower']);
        $item = $this->makeBoqItem($project);

        return app(DprService::class)->create($project, [
            'report_date' => '2026-11-05',
            'weather' => 'Cloudy',
            'manpower' => [['trade' => 'Mason', 'count' => 12], ['trade' => 'Helper', 'count' => 20]],
            'work' => [['boq_item_id' => $item->id, 'qty' => '42.500']],
        ], $by ?? ($org->is($this->org) ? $this->pm : $this->member($org, 'pm')));
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** Limited to one project, the way the team screen does it. */
    private function limitedTo(User $user, Project $project): User
    {
        app(TeamAccessService::class)->applyProjects($user, true, [$project->id]);

        return $user->refresh();
    }

    /** @return array{0: Project, 1: BoqItem} */
    private function measuredProject(?Organization $org = null): array
    {
        $org ??= $this->org;
        $project = $this->makeProject($org, ['code' => 'RT1']);
        $item = BoqItem::create(['organization_id' => $org->id, 'project_id' => $project->id, 'code' => 'A', 'description' => 'RCC slab', 'unit' => 'cum', 'qty' => '500', 'rate' => self::RATE, 'sort' => 1]);

        MeasurementEntry::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'boq_item_id' => $item->id,
            'measured_on' => '2026-11-02', 'description' => 'Slab', 'location' => 'Level 2',
            'nos' => 2, 'length' => 5, 'breadth' => 1, 'depth' => 1, 'is_deduction' => false, 'status' => 'APPROVED',
        ]);

        return [$project, $item];
    }

    private function sheetUrl(Project $project, array $query = ['from' => '2026-11-01', 'to' => '2026-11-30']): string
    {
        return self::API.'/projects/'.$project->id.'/measurement-sheet.pdf'.($query ? '?'.http_build_query($query) : '');
    }

    /** PDFs come back as the HTML they are made from, so what the sheet shows can be read. */
    private function readablePdfs(): void
    {
        Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data = []) {
            $html = view($view, $data)->render();
            $document = Mockery::mock(DomPdf::class);
            $document->shouldReceive('setPaper', 'setOption', 'setOptions')->andReturnSelf();
            $document->shouldReceive('output')->andReturn($html);
            $document->shouldReceive('download', 'stream')->andReturn(new Response($html, 200, ['Content-Type' => 'application/pdf']));

            return $document;
        });
    }

    // ---- DPR PDF ----

    public function test_the_dpr_pdf_downloads_like_the_web_one(): void
    {
        $dpr = $this->dpr();

        $response = $this->as($this->pm)->get(self::API.'/dprs/'.$dpr->id.'/pdf');

        $this->assertPdf($response);
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('DPR-2026-11-05-'.$dpr->id.'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_dpr_pdf_is_for_everyone_who_can_view_dprs(): void
    {
        $dpr = $this->dpr();

        foreach (['viewer', 'engineer', 'store', 'qs'] as $role) {
            $this->assertPdf($this->as($this->member($this->org, $role))->get(self::API.'/dprs/'.$dpr->id.'/pdf'));
        }

        // The accountant cannot see DPRs, on the web or here.
        $this->as($this->member($this->org, 'accounts'))->getJson(self::API.'/dprs/'.$dpr->id.'/pdf')->assertForbidden();
    }

    public function test_another_companys_dpr_pdf_is_not_found(): void
    {
        $dpr = $this->dpr();
        $outsider = $this->member($this->tenant('Other Co', 'business'), 'owner');

        $this->as($outsider)->getJson(self::API.'/dprs/'.$dpr->id.'/pdf')->assertNotFound();
    }

    public function test_someone_limited_to_other_projects_cannot_read_the_dpr_pdf(): void
    {
        $dpr = $this->dpr();
        $project = $dpr->project;
        $elsewhere = $this->makeProject($this->org);

        $this->as($this->limitedTo($this->member($this->org, 'engineer'), $elsewhere))->getJson(self::API.'/dprs/'.$dpr->id.'/pdf')->assertNotFound();
        $this->assertPdf($this->as($this->limitedTo($this->member($this->org, 'engineer'), $project))->get(self::API.'/dprs/'.$dpr->id.'/pdf'));
    }

    public function test_the_dpr_pdf_needs_a_plan_with_reports_and_a_sign_in(): void
    {
        $free = $this->tenant('Small Co', 'free');
        $freeDpr = $this->dpr($free);
        $this->as($this->member($free, 'owner'))->getJson(self::API.'/dprs/'.$freeDpr->id.'/pdf')->assertForbidden();

        $contractor = $this->tenant('Mid Co', 'contractor');
        $this->assertPdf($this->as($this->member($contractor, 'engineer'))->get(self::API.'/dprs/'.$this->dpr($contractor)->id.'/pdf'));

        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/dprs/'.$freeDpr->id.'/pdf')->assertUnauthorized();
    }

    // ---- WhatsApp link ----

    public function test_the_share_link_is_the_webs_signed_link_with_the_same_message(): void
    {
        $dpr = $this->dpr();

        $data = $this->as($this->engineerOf($this->org))->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertOk()->json('data');

        $this->assertStringStartsWith(url('/shared/dprs/'.$dpr->id).'?expires=', $data['url']);
        $this->assertTrue(URL::hasValidSignature(request()->create($data['url'])));
        $this->assertSame(DprReportService::LINK_DAYS, $data['link_days']);
        $this->assertSame('DPR-2026-11-05-'.$dpr->id.'.pdf', $data['filename']);
        $this->assertEqualsWithDelta(now()->addDays(DprReportService::LINK_DAYS)->timestamp, strtotime($data['expires_at']), 5);

        // The message is the web's, ending in this very link, and the wa.me address carries exactly that message.
        $this->assertStringContainsString('Acme Infra · Daily progress report', $data['text']);
        $this->assertStringContainsString('Riverside Tower', $data['text']);
        $this->assertStringContainsString('Weather: Cloudy · Manpower: 32', $data['text']);
        $this->assertStringEndsWith("\n".$data['url'], $data['text']);
        $this->assertSame('https://wa.me/?text='.rawurlencode($data['text']), $data['whatsapp_url']);

        // Exactly the keys the contract names, no rupee figure among them.
        $this->assertSame(['url', 'text', 'whatsapp_url', 'expires_at', 'link_days', 'filename'], array_keys($data));
        $this->assertStringNotContainsString('₹', $data['text']);
    }

    public function test_the_shared_link_opens_that_one_pdf_and_nothing_more(): void
    {
        $dpr = $this->dpr();
        $other = $this->dpr();
        $url = $this->as($this->pm)->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->json('data.url');

        $this->app['auth']->forgetGuards();
        $response = $this->get($url);
        $this->assertPdf($response);
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));

        // Changing the number, dropping the signature or adding to it is refused.
        $this->get(str_replace('/shared/dprs/'.$dpr->id, '/shared/dprs/'.$other->id, $url))->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get($url.'x')->assertForbidden();

        // It reaches nothing else: not the API, not the signed-in PDF.
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $this->getJson(self::API.'/dprs/'.$dpr->id.'?'.$query)->assertUnauthorized();
        $this->getJson(self::API.'/dprs/'.$dpr->id.'/pdf?'.$query)->assertUnauthorized();
        $this->get(route('dprs.pdf', $dpr).'?'.$query)->assertRedirect(route('login'));
    }

    public function test_the_shared_link_lasts_as_long_as_the_webs(): void
    {
        $dpr = $this->dpr();
        $url = $this->as($this->pm)->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->json('data.url');
        $this->app['auth']->forgetGuards();

        $this->travel(DprReportService::LINK_DAYS - 1)->days();
        $this->assertPdf($this->get($url));

        $this->travel(2)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_the_shared_link_stops_when_the_dpr_is_deleted_or_the_plan_drops_reports(): void
    {
        $dpr = $this->dpr();
        $url = $this->as($this->pm)->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->json('data.url');
        $this->app['auth']->forgetGuards();

        $this->org->update(['plan' => 'free']);
        $this->get($url)->assertNotFound();

        $this->org->update(['plan' => 'business']);
        $dpr->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_no_link_for_another_companys_dpr(): void
    {
        $dpr = $this->dpr();
        $outsider = $this->member($this->tenant('Other Co', 'business'), 'owner');

        $response = $this->as($outsider)->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertNotFound();

        $this->assertStringNotContainsString('/shared/dprs/', $response->getContent());
        $this->assertStringNotContainsString('signature', $response->getContent());
    }

    public function test_no_link_without_dpr_access_the_project_or_the_plan(): void
    {
        $dpr = $this->dpr();

        $this->as($this->member($this->org, 'accounts'))->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertForbidden();
        $this->as($this->limitedTo($this->member($this->org, 'engineer'), $this->makeProject($this->org)))->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertNotFound();
        $this->as($this->member($this->org, 'contractor'))->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertForbidden();

        $free = $this->tenant('Small Co', 'free');
        $this->as($this->member($free, 'owner'))->postJson(self::API.'/dprs/'.$this->dpr($free)->id.'/share-link')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertUnauthorized();
    }

    public function test_the_message_is_written_in_the_language_the_app_asks_for(): void
    {
        $dpr = $this->dpr();
        $this->as($this->pm);

        $this->assertStringContainsString('दैनिक प्रगति रिपोर्ट', $this->postJson(self::API.'/dprs/'.$dpr->id.'/share-link', ['locale' => 'hi'])->json('data.text'));
        $this->assertStringContainsString('दैनिक प्रगती अहवाल', $this->postJson(self::API.'/dprs/'.$dpr->id.'/share-link', ['locale' => 'mr'])->json('data.text'));
        $this->assertStringContainsString('Daily progress report', $this->postJson(self::API.'/dprs/'.$dpr->id.'/share-link')->json('data.text'));

        $this->postJson(self::API.'/dprs/'.$dpr->id.'/share-link', ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    }

    public function test_the_share_link_is_a_post_only(): void
    {
        $dpr = $this->dpr();

        $this->as($this->pm)->getJson(self::API.'/dprs/'.$dpr->id.'/share-link')->assertStatus(405);
    }

    // ---- Measurement sheet ----

    public function test_the_measurement_sheet_downloads_for_a_period(): void
    {
        [$project] = $this->measuredProject();

        $response = $this->as($this->pm)->get($this->sheetUrl($project));

        $this->assertPdf($response);
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('measurement-sheet-RT1-20261101-20261130.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_the_measurement_sheet_shows_rates_only_with_money_view(): void
    {
        $this->readablePdfs();
        [$project] = $this->measuredProject();

        // money.view: owner, PM, QS and the accountant get the rate, amount and total.
        foreach (['owner', 'pm', 'qs', 'accounts'] as $role) {
            $html = $this->as($this->member($this->org, $role))->get($this->sheetUrl($project))->assertOk()->getContent();
            $this->assertStringContainsString('98,765.43', $html, "{$role} should see the rate");
        }

        // The engineer and the viewer get quantities only.
        foreach (['engineer', 'viewer'] as $role) {
            $html = $this->as($this->member($this->org, $role))->get($this->sheetUrl($project))->assertOk()->getContent();
            $this->assertStringContainsString('RCC slab', $html);
            $this->assertStringContainsString('10.000', $html);
            $this->assertStringNotContainsString('98,765.43', $html, "{$role} must not see the rate");
            $this->assertStringNotContainsString('98765', $html);
            $this->assertStringNotContainsString('9,87,654', $html, "{$role} must not see the amount");
            $this->assertDoesNotMatchRegularExpression('/₹\s?\d/u', $html);
        }
    }

    public function test_the_measurement_sheet_follows_the_webs_access_rules(): void
    {
        [$project] = $this->measuredProject();

        // The store keeper has no measurement access.
        $this->as($this->member($this->org, 'store'))->getJson($this->sheetUrl($project))->assertForbidden();
        $this->as($this->member($this->org, 'contractor'))->getJson($this->sheetUrl($project))->assertForbidden();

        $outsider = $this->member($this->tenant('Other Co', 'business'), 'owner');
        $this->as($outsider)->getJson($this->sheetUrl($project))->assertNotFound();

        $this->as($this->limitedTo($this->member($this->org, 'engineer'), $this->makeProject($this->org)))->getJson($this->sheetUrl($project))->assertNotFound();
        $this->assertPdf($this->as($this->limitedTo($this->member($this->org, 'engineer'), $project))->get($this->sheetUrl($project)));

        $free = $this->tenant('Small Co', 'free');
        $this->as($this->member($free, 'owner'))->getJson($this->sheetUrl($this->makeProject($free)))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson($this->sheetUrl($project))->assertUnauthorized();
    }

    public function test_another_companys_entries_never_reach_the_sheet(): void
    {
        $this->readablePdfs();
        [$project] = $this->measuredProject();
        $other = $this->tenant('Other Co', 'business');
        $theirs = $this->makeProject($other);
        $item = BoqItem::create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'code' => 'Z', 'description' => 'Their secret item', 'unit' => 'cum', 'qty' => '5', 'rate' => '1.00', 'sort' => 1]);
        MeasurementEntry::create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'boq_item_id' => $item->id, 'measured_on' => '2026-11-03', 'nos' => 1, 'is_deduction' => false, 'status' => 'APPROVED']);

        $html = $this->as($this->pm)->get($this->sheetUrl($project))->assertOk()->getContent();

        $this->assertStringContainsString('RCC slab', $html);
        $this->assertStringNotContainsString('Their secret item', $html);
    }

    public function test_the_period_is_checked(): void
    {
        [$project] = $this->measuredProject();
        $this->as($this->pm);

        $this->getJson($this->sheetUrl($project, []))->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson($this->sheetUrl($project, ['from' => '2026-11-30', 'to' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson($this->sheetUrl($project, ['from' => 'soon', 'to' => '2026-11-01']))->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_an_empty_period_still_gives_a_sheet(): void
    {
        [$project] = $this->measuredProject();

        $this->assertPdf($this->as($this->pm)->get($this->sheetUrl($project, ['from' => '2027-01-01', 'to' => '2027-01-31'])));
    }

    private function engineerOf(Organization $org): User
    {
        return $this->member($org, 'engineer');
    }
}
