<?php

namespace Tests\Feature\Reports;

use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\User;
use App\Services\DprService;
use App\Services\Reports\DprReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DprReportTest extends ReportsTestCase
{
    private function dpr(?User $by = null, array $data = [], ?BoqItem $item = null): Dpr
    {
        $project = $this->makeProject($this->org, ['name' => 'Riverside Tower']);
        $item ??= $this->makeBoqItem($project);

        return app(DprService::class)->create($project, [
            'report_date' => '2026-11-05',
            'weather' => 'Cloudy',
            'summary' => "Slab casting on level 3.\nCuring on level 2.",
            'hindrances' => 'Cement delivery was late.',
            'manpower' => [['trade' => 'Mason', 'count' => 12], ['trade' => 'Helper', 'count' => 20]],
            'machinery' => [['name' => 'Concrete mixer', 'hours' => 6.5]],
            'work' => [['boq_item_id' => $item->id, 'qty' => '42.500']],
            ...$data,
        ], $by ?? $this->pm);
    }

    /** The text and signed link carried in a wa.me URL. */
    private function whatsappParts(string $url): array
    {
        $this->assertStringStartsWith('https://wa.me/?text=', $url);
        $text = rawurldecode(substr($url, strlen('https://wa.me/?text=')));
        preg_match('#https?://\S+#', $text, $link);

        return [$text, $link[0] ?? ''];
    }

    public function test_the_dpr_pdf_downloads_with_everything_in_it(): void
    {
        Storage::fake();
        $dpr = $this->dpr();
        app(DprService::class)->addPhoto($dpr, UploadedFile::fake()->image('site.jpg', 1600, 1200), ['caption' => 'Level 3 slab']);

        $response = $this->actingAs($this->pm)->get(route('dprs.pdf', $dpr));

        $this->assertPdf($response);
        $this->assertStringContainsString('DPR-2026-11-05-'.$dpr->id.'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_the_pdf_data_is_complete_and_photos_are_shrunk(): void
    {
        Storage::fake();
        $project = $this->makeProject($this->org);
        $item = $this->makeBoqItem($project);
        $dpr = $this->dpr(item: $item);

        foreach (range(1, 10) as $number) {
            app(DprService::class)->addPhoto($dpr, UploadedFile::fake()->image("p{$number}.jpg", 2400, 1800), ['caption' => "Photo {$number}", 'taken_at' => '2026-11-05 10:30:00']);
        }

        $data = app(DprReportService::class)->data($dpr);

        $this->assertSame(32, $data['manpower']->sum('count'));
        $this->assertSame('Concrete mixer', $data['machinery']->first()->name);
        $this->assertSame([['code' => $item->code, 'description' => $item->description, 'qty' => '42.500', 'unit' => 'cum']], $data['work']->all());
        $this->assertCount(DprReportService::MAX_PHOTOS, $data['photos']);
        $this->assertSame(2, $data['morePhotos']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $data['photos'][0]['src']);

        // A 2400 x 1800 photo is cut down to 640 pixels on its long side.
        [$width, $height] = getimagesizefromstring(base64_decode(substr($data['photos'][0]['src'], strlen('data:image/jpeg;base64,'))));
        $this->assertSame([640, 480], [$width, $height]);
    }

    public function test_a_photo_that_cannot_be_read_is_left_out_not_fatal(): void
    {
        Storage::fake();
        $dpr = $this->dpr();
        app(DprService::class)->addPhoto($dpr, UploadedFile::fake()->image('ok.jpg', 400, 300));
        $dpr->photos()->create(['organization_id' => $this->org->id, 'path' => 'org-1/missing-file.jpg']);

        $this->assertCount(1, app(DprReportService::class)->data($dpr)['photos']);
        $this->assertPdf($this->actingAs($this->pm)->get(route('dprs.pdf', $dpr)));
    }

    public function test_access_to_the_dpr_pdf(): void
    {
        $dpr = $this->dpr();

        // Anyone who can view DPRs, including a viewer and the engineer who is not the author.
        $this->assertPdf($this->actingAs($this->member($this->org, 'viewer'))->get(route('dprs.pdf', $dpr)));
        $this->assertPdf($this->actingAs($this->member($this->org, 'engineer'))->get(route('dprs.pdf', $dpr)));
        // Accounts cannot see DPRs at all.
        $this->actingAs($this->member($this->org, 'accounts'))->get(route('dprs.pdf', $dpr))->assertForbidden();

        // Another company's DPR is not found.
        $other = $this->member($this->tenant('Other Co', 'business'), 'owner');
        $this->actingAs($other)->get(route('dprs.pdf', $dpr))->assertNotFound();

        // Signed out goes to sign in.
        auth()->logout();
        $this->get(route('dprs.pdf', $dpr))->assertRedirect(route('login'));
    }

    public function test_the_free_plan_has_no_dpr_pdf(): void
    {
        $free = $this->tenant('Small Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);
        $dpr = app(DprService::class)->create($project, ['report_date' => '2026-11-05'], $owner);

        $this->actingAs($owner)->get(route('dprs.pdf', $dpr))->assertForbidden();
        $this->actingAs($owner)->get(route('dprs.show', $dpr))->assertOk()->assertDontSee('Share on WhatsApp')->assertDontSee('/pdf', false);
    }

    public function test_the_dpr_page_has_pdf_and_whatsapp_buttons(): void
    {
        $dpr = $this->dpr();

        $this->actingAs($this->pm)->get(route('dprs.show', $dpr))->assertOk()
            ->assertSee(route('dprs.pdf', $dpr), false)
            ->assertSee('Share on WhatsApp')
            ->assertSee('https://wa.me/?text=', false);
    }

    public function test_the_whatsapp_text_summarises_the_day_and_carries_a_signed_link(): void
    {
        $dpr = $this->dpr();
        $service = app(DprReportService::class);

        [$text, $link] = $this->whatsappParts($service->whatsappUrl($dpr));

        $this->assertStringContainsString('Acme Infra · Daily progress report', $text);
        $this->assertStringContainsString('Riverside Tower', $text);
        $this->assertStringContainsString('05 Nov 2026', $text);
        $this->assertStringContainsString('Weather: Cloudy · Manpower: 32', $text);
        $this->assertStringContainsString('42.500 cum', $text);

        $this->assertStringStartsWith(url('/shared/dprs/'.$dpr->id), $link);
        $this->assertTrue(URL::hasValidSignature(request()->create($link)));
        $this->assertStringContainsString('expires=', $link);
    }

    public function test_a_long_dpr_is_cut_down_in_the_whatsapp_text(): void
    {
        $project = $this->makeProject($this->org);
        $work = [];

        foreach (range(1, 8) as $number) {
            $work[] = ['boq_item_id' => $this->makeBoqItem($project)->id, 'qty' => $number];
        }

        $dpr = app(DprService::class)->create($project, ['report_date' => '2026-11-05', 'work' => $work], $this->pm);
        [$text] = $this->whatsappParts(app(DprReportService::class)->whatsappUrl($dpr));

        $this->assertSame(5, substr_count($text, "\n- ") - 1);
        $this->assertStringContainsString('and 3 more', $text);
    }

    public function test_the_signed_link_opens_the_pdf_without_signing_in(): void
    {
        $dpr = $this->dpr();
        $url = app(DprReportService::class)->signedUrl($dpr);

        auth()->logout();
        $response = $this->get($url);

        $this->assertPdf($response);
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_signed_link_works_for_someone_signed_in_to_another_company(): void
    {
        $dpr = $this->dpr();
        $url = app(DprReportService::class)->signedUrl($dpr);
        $outsider = $this->member($this->tenant('Other Co', 'business'), 'owner');

        $this->assertPdf($this->actingAs($outsider)->get($url));
    }

    public function test_the_link_cannot_be_edited_to_open_another_dpr(): void
    {
        $dpr = $this->dpr();
        $url = app(DprReportService::class)->signedUrl($dpr);

        auth()->logout();
        $this->get(str_replace('/shared/dprs/'.$dpr->id, '/shared/dprs/'.($dpr->id + 1), $url))->assertForbidden();
        $this->get(route('dprs.shared', ['id' => $dpr->id]))->assertForbidden();
        $this->get($url.'x')->assertForbidden();
    }

    public function test_the_link_stops_working_after_a_week(): void
    {
        $dpr = $this->dpr();
        $url = app(DprReportService::class)->signedUrl($dpr);

        auth()->logout();
        $this->travel(DprReportService::LINK_DAYS - 1)->days();
        $this->assertPdf($this->get($url));

        $this->travel(2)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_the_link_is_gone_when_the_dpr_is_deleted_or_the_plan_drops_reports(): void
    {
        $dpr = $this->dpr();
        $url = app(DprReportService::class)->signedUrl($dpr);

        $this->org->update(['plan' => 'free']);
        auth()->logout();
        $this->get($url)->assertNotFound();

        $this->org->update(['plan' => 'business']);
        $this->assertPdf($this->get($url));

        $dpr->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_the_pdf_reads_the_right_company_even_for_a_signed_in_outsider(): void
    {
        $dpr = $this->dpr();
        $outsider = $this->member($this->tenant('Other Co', 'business'), 'owner');
        $this->actingAs($outsider);

        $data = app(DprReportService::class)->data($dpr);

        $this->assertSame('Acme Infra', $data['organization']->name);
        $this->assertSame('Riverside Tower', $data['project']->name);
        $this->assertSame(32, $data['manpower']->sum('count'));
        $this->assertCount(1, $data['work']);
    }
}
