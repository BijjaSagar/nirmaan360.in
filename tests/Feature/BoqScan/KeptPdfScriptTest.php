<?php

namespace Tests\Feature\BoqScan;

use App\Models\User;
use App\Services\BoqScan\BoqScanScreen;
use App\Services\Reports\BoqPdfImport;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Reports\WritesPdfByHand;

/**
 * The browser's own copy of a PDF uploaded on the BOQ in Excel page, run in a pretend browser
 * (kept-pdf-harness.mjs) with the pages exactly as the server draws them, scripts and forms in their
 * order: the upload keeps it although its script comes before the form, the preview finds it, and it
 * is forgotten once the import is confirmed or discarded, once it is too old, and at sign-out.
 *
 * Every company, file and figure is invented.
 */
class KeptPdfScriptTest extends BoqScanTestCase
{
    use WritesPdfByHand;

    private const LIFETIME_SECONDS = BoqPdfImport::TTL_MINUTES * 60;

    private User $pm;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pm = $this->member($this->organization, 'pm');
        $this->key = BoqScanScreen::keptKey($this->pm, $this->project);
    }

    /**
     * What of a page the harness runs, in the order the page draws it: every inline script of the kept copy
     * and of the sign-out, the import form and every sign-out form.
     *
     * @return list<array<string, mixed>>
     */
    private function parts(TestResponse $page): array
    {
        $document = HTMLDocument::createFromString((string) $page->getContent(), LIBXML_NOERROR);
        $parts = [];

        foreach ($document->querySelectorAll('script:not([src]), form') as $node) {
            /** @var Element $node */
            if (strtolower($node->localName) === 'script') {
                $text = (string) $node->textContent;

                if (str_contains($text, 'BoqScanKeptPdf') || str_contains($text, 'BoqScanSignOut')) {
                    $parts[] = ['script' => $text];
                }

                continue;
            }

            if ($node->getAttribute('id') === 'boq-import-form' || $node->hasAttribute('data-sign-out')) {
                $attrs = [];

                foreach ($node->attributes as $attribute) {
                    $attrs[$attribute->name] = $attribute->value;
                }

                $parts[] = ['form' => [
                    'id' => (string) $node->getAttribute('id'),
                    'action' => (string) $node->getAttribute('action'),
                    'attrs' => $attrs,
                    'file' => $node->querySelector('input[type="file"][name="file"]') !== null,
                ]];
            }
        }

        return $parts;
    }

    /**
     * @param  list<array<string, mixed>>  $scenarios
     * @return array<string, list<array<string, mixed>>> Scenario name => what each step saw.
     */
    private function browse(array $scenarios): array
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed, so the page scripts cannot be run here.');
        }

        $file = tempnam(sys_get_temp_dir(), 'n360-kept-');
        file_put_contents($file, json_encode($scenarios, JSON_THROW_ON_ERROR));

        try {
            $process = new Process([$node, __DIR__.'/kept-pdf-harness.mjs', $file]);
            $process->setTimeout(60)->run();
            $this->assertTrue($process->isSuccessful(), 'The harness ran: '.$process->getErrorOutput());
        } finally {
            @unlink($file);
        }

        $results = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->keyBy('name');

        foreach ($results as $name => $result) {
            $this->assertArrayNotHasKey('error', $result, "{$name}: ".($result['error'] ?? ''));

            foreach ($result['events'] as $event) {
                if ($event['step'] === 'open') {
                    $this->assertSame([], $event['errors'], "{$name}: every script of {$event['page']} ran.");
                    $this->assertGreaterThan(0, $event['scripts'], "{$name}: {$event['page']} carries the scripts.");
                }
            }
        }

        return $results->map(fn (array $result) => $result)->all();
    }

    private function excelPage(): TestResponse
    {
        return $this->actingAs($this->pm)->get(route('boq.excel.index', $this->project))->assertOk();
    }

    private function upload(string $bytes, string $name): TestResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, $bytes);

        return $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), ['file' => new UploadedFile($path, $name, 'application/pdf', null, true)]);
    }

    /** A typed estimate of two invented items, as a text PDF. */
    private function estimatePdf(): string
    {
        return $this->handPdf($this->tableCells([
            ['Sr. No.', 'Description of Items', 'Qty.', 'Rate', 'Unit', 'Amount in Rs.'],
            ['1', 'Excavation for foundation', '10', '350', 'cu.m', '3500'],
            ['2', 'Providing and fixing flush door', '3', '4000', 'door', '12000'],
        ]), ['picture' => 'none']);
    }

    /** The preview of a staged upload, as the person is sent to it. */
    private function previewPage(): TestResponse
    {
        $this->upload($this->estimatePdf(), 'estimate.pdf')->assertRedirect(route('boq.pdf.preview', $this->project));

        return $this->actingAs($this->pm)->get(route('boq.pdf.preview', $this->project))->assertOk()->assertSee('boqPdfPages(', false);
    }

    /** @return array{name: string, type: string} */
    private function pdfFile(string $name = 'estimate.pdf'): array
    {
        return ['name' => $name, 'type' => 'application/pdf'];
    }

    public function test_the_upload_keeps_its_copy_although_the_script_comes_before_the_form_and_the_preview_finds_it(): void
    {
        $excel = $this->parts($this->excelPage());

        // The script that keeps the copy is drawn before the form it watches: that is the order the harness runs them in.
        $order = array_map(fn (array $part) => isset($part['form']) ? 'form:'.($part['form']['id'] ?: 'sign-out') : (str_contains($part['script'], 'boq-import-form') ? 'upload script' : 'script'), $excel);
        $this->assertLessThan(array_search('form:boq-import-form', $order, true), array_search('upload script', $order, true));

        $preview = $this->parts($this->previewPage());

        $run = $this->browse([
            ['name' => 'pdf', 'pages' => ['excel' => $excel, 'preview' => $preview], 'steps' => [
                ['open', 'excel'], ['choose', 'boq-import-form', $this->pdfFile()], ['submit', 'boq-import-form'],
                ['open', 'preview'], ['load', $this->key],
            ]],
            ['name' => 'excel file', 'pages' => ['excel' => $excel], 'steps' => [
                ['open', 'excel'], ['choose', 'boq-import-form', ['name' => 'north-block.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']], ['submit', 'boq-import-form'],
            ]],
        ]);

        [$opened, , $sent, $previewed, $loaded] = $run['pdf']['events'];

        // Opening the page makes no store when there is none.
        $this->assertNull($opened['stored']);

        // The upload waits for the copy, then the page sends the form itself, once.
        $this->assertTrue($sent['prevented']);
        $this->assertSame(1, $sent['byScript']);
        $this->assertSame([$this->key => ['name' => 'estimate.pdf', 'minutes' => 0]], $sent['stored']);

        // The preview's own page finds the same copy to draw its pages from.
        $this->assertSame([$this->key => ['name' => 'estimate.pdf', 'minutes' => 0]], $previewed['stored']);
        $this->assertSame('estimate.pdf', $loaded['found']);

        // Anything but a PDF goes as it is, and nothing is kept.
        $excelFile = $run['excel file']['events'][2];
        $this->assertSame([false, 1, 0, null], [$excelFile['prevented'], $excelFile['sent'], $excelFile['byScript'], $excelFile['stored']]);
        $this->assertSame([], $run['excel file']['databases']);

        Http::assertNothingSent();
    }

    public function test_the_copy_is_forgotten_once_the_import_is_confirmed_or_discarded_and_kept_after_a_refusal_as_a_scan(): void
    {
        $excel = $this->parts($this->excelPage());

        // Discarded.
        $preview = $this->previewPage();
        $token = session(BoqPdfImport::SESSION);
        $this->actingAs($this->pm)->post(route('boq.pdf.discard', $this->project), ['token' => $token])
            ->assertRedirect(route('boq.excel.index', $this->project))->assertSessionHas(BoqPdfImport::ENDED, true);
        $afterDiscard = $this->parts($this->excelPage());

        // Confirmed, with every answer the preview asks for.
        $preview = $this->previewPage();
        preg_match_all('/name="ack_notes\[\]" value="([0-9a-f]{40})"/', (string) $preview->getContent(), $ticks);
        $this->actingAs($this->pm)->post(route('boq.pdf.confirm', $this->project), [
            'token' => session(BoqPdfImport::SESSION), 'printed_language' => 'en', 'pages_checked' => '1', 'ack_notes' => $ticks[1],
        ])->assertRedirect(route('boq.excel.index', $this->project))->assertSessionHas('success')->assertSessionHas(BoqPdfImport::ENDED, true);
        $afterConfirm = $this->parts($this->excelPage()->assertSee('Import finished'));
        $this->assertSame(2, $this->project->boqItems()->count());

        // Refused as a scan: the copy stays, for the scan screen to offer the same file.
        $this->upload($this->handPdf('0.5 g 50 50 495 742 re f'), 'scan.pdf')->assertRedirect(route('boq.excel.index', $this->project))->assertSessionMissing(BoqPdfImport::ENDED);
        $afterRefusal = $this->parts($this->excelPage()->assertSee('Nothing was imported'));

        // Back on the page with nothing ended, a copy is not touched.
        $plain = $this->parts($this->excelPage());

        $flow = fn (string $after) => [
            ['open', 'excel'], ['choose', 'boq-import-form', $this->pdfFile()], ['submit', 'boq-import-form'],
            ['open', 'preview'], ['advance', 60], ['open', $after],
        ];
        $pages = ['excel' => $excel, 'preview' => $this->parts($preview), 'discarded' => $afterDiscard, 'confirmed' => $afterConfirm, 'refused' => $afterRefusal, 'plain' => $plain];

        $run = $this->browse([
            ['name' => 'discarded', 'pages' => $pages, 'steps' => $flow('discarded')],
            ['name' => 'confirmed', 'pages' => $pages, 'steps' => $flow('confirmed')],
            ['name' => 'refused', 'pages' => $pages, 'steps' => $flow('refused')],
            ['name' => 'plain', 'pages' => $pages, 'steps' => $flow('plain')],
            // A browser that kept nothing is left with no store at all: forgetting never makes an empty one.
            ['name' => 'nothing kept', 'pages' => $pages, 'steps' => [['open', 'confirmed'], ['open', 'discarded']]],
        ]);

        $this->assertSame([null, null], [$run['nothing kept']['events'][0]['stored'], $run['nothing kept']['events'][1]['stored']]);
        $this->assertSame([], $run['nothing kept']['databases']);

        $kept = [$this->key => ['name' => 'estimate.pdf', 'minutes' => 1]];

        foreach (['discarded', 'confirmed'] as $ended) {
            $this->assertSame($kept, $run[$ended]['events'][4]['stored'], "{$ended}: kept until then.");
            $this->assertSame([], $run[$ended]['events'][5]['stored'], "{$ended}: forgotten.");
        }

        $this->assertSame($kept, $run['refused']['events'][5]['stored']);
        $this->assertSame($kept, $run['plain']['events'][5]['stored']);
    }

    public function test_every_page_with_the_kept_copy_deletes_copies_too_old_to_use_and_copies_left_by_someone_else(): void
    {
        $excel = $this->parts($this->excelPage());
        $other = BoqScanScreen::keptKey($this->pm, $this->makeProject($this->organization, ['code' => 'SB2', 'name' => 'South Block']));
        $someoneElse = BoqScanScreen::keptKey($this->member($this->organization, 'pm'), $this->project);
        $lifetime = self::LIFETIME_SECONDS / 60;

        $run = $this->browse([['name' => 'sweep', 'pages' => ['excel' => $excel], 'steps' => [
            // A copy of this project older than the preview lasts, a fresh one of another project, one of another
            // person on this browser, and one dated ahead by more than its lifetime.
            ['save', $this->key, 'old.pdf', $lifetime + 1],
            ['save', $other, 'fresh.pdf', 5],
            ['save', $someoneElse, 'theirs.pdf', 1],
            ['save', 'boq-pdf:'.$this->pm->id.':999999', 'ahead.pdf', -($lifetime + 15)],
            // Nobody opens a preview: the BOQ in Excel page alone clears them out as it opens.
            ['open', 'excel'],
            ['advance', ($lifetime - 5) * 60 + 1],
            ['stored'],
            ['open', 'excel'],
        ]]])['sweep']['events'];

        $this->assertCount(4, $run[3]['stored']);
        $this->assertSame([$other => ['name' => 'fresh.pdf', 'minutes' => 5]], $run[4]['stored']);

        // Past its lifetime too, the fresh one goes the next time such a page opens.
        $this->assertSame([$other => ['name' => 'fresh.pdf', 'minutes' => $lifetime]], $run[6]['stored']);
        $this->assertSame([], $run[7]['stored']);
    }

    public function test_signing_out_deletes_every_kept_copy_before_the_sign_out_leaves_and_never_holds_it_up(): void
    {
        $dashboard = $this->parts($this->actingAs($this->pm)->get(route('dashboard'))->assertOk());
        $excel = $this->parts($this->excelPage());

        $form = collect($dashboard)->firstWhere('form')['form'];
        $this->assertArrayHasKey('data-sign-out', $form['attrs']);
        $this->assertSame(route('logout'), $form['action']);

        $run = $this->browse([
            ['name' => 'dashboard', 'pages' => ['dashboard' => $dashboard], 'steps' => [
                ['save', $this->key, 'estimate.pdf', 2], ['save', 'boq-pdf:999999:1', 'theirs.pdf', 1],
                ['open', 'dashboard'], ['submit', '[data-sign-out]'],
            ]],
            ['name' => 'excel', 'pages' => ['excel' => $excel], 'steps' => [
                ['open', 'excel'], ['choose', 'boq-import-form', $this->pdfFile()], ['submit', 'boq-import-form'], ['submit', '[data-sign-out]'],
            ]],
            // The browser cannot delete the store just now (another tab holds it open): the sign-out waits two seconds at most.
            ['name' => 'held', 'pages' => ['dashboard' => $dashboard], 'steps' => [
                ['save', $this->key, 'estimate.pdf', 2], ['hangDeletes'],
                ['open', 'dashboard'], ['submit', '[data-sign-out]'], ['submit', '[data-sign-out]'], ['advance', 1], ['advance', 1],
            ]],
        ]);

        $signedOut = $run['dashboard']['events'][3];
        $this->assertSame([true, 1, null], [$signedOut['prevented'], $signedOut['byScript'], $signedOut['stored']]);
        $this->assertSame([], $run['dashboard']['databases']);

        $this->assertSame([$this->key => ['name' => 'estimate.pdf', 'minutes' => 0]], $run['excel']['events'][2]['stored']);
        $this->assertSame([true, 1, null], [$run['excel']['events'][3]['prevented'], $run['excel']['events'][3]['byScript'], $run['excel']['events'][3]['stored']]);

        [, , , $first, $again, $oneSecond, $twoSeconds] = $run['held']['events'];
        $this->assertSame([true, 0], [$first['prevented'], $first['byScript']]);
        $this->assertSame([true, 0], [$again['prevented'], $again['byScript']], 'A second press waits for the first.');
        $this->assertSame([['sign-out', 0]], $oneSecond['sent']);
        $this->assertSame([['sign-out', 1]], $twoSeconds['sent'], 'Signed out once, after two seconds.');
        $this->assertSame(1, $run['held']['deletesAsked']);

        // The sign-out itself is the server's as before.
        $this->actingAs($this->pm)->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_every_sign_out_button_carries_the_deletion(): void
    {
        // The company's pages, the contractor portal's frame and the invitation page: every form that signs out.
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all("/<form[^>]*\\{\\{ route\\('logout'\\) \\}\\}[^>]*>/", $source, $forms) > 0) {
                $view = str_replace(resource_path('views').'/', '', $file->getPathname());
                $found[] = $view;

                foreach ($forms[0] as $tag) {
                    $this->assertStringContainsString(' data-sign-out', $tag, $view);
                }

                $this->assertSame(count($forms[0]), substr_count($source, "@includeIf('modules.boq_scan._sign_out')"), $view);
            }
        }

        sort($found);
        $this->assertSame(['layouts/app.blade.php', 'modules/access/invitation.blade.php', 'modules/contractor_portal/layout.blade.php'], $found);
        $this->assertTrue(view()->exists('modules.boq_scan._sign_out'));
    }
}
