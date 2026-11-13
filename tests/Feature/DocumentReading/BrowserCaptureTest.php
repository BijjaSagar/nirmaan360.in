<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\ImageTokens;
use App\Services\DocumentReading\ReaderFixtures;
use App\Services\DocumentReading\ReadIntake;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * What the browser needs to draw pages: the pdf.js decoders served from a folder the config names,
 * a size function that agrees with the server's, the partial that hands the script its settings,
 * and the test PDFs of the self-check. Drawing itself is checked in a real browser, not here.
 */
class BrowserCaptureTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_the_asset_folder_in_the_config_is_the_installed_pdfjs_version(): void
    {
        $lock = json_decode($this->source('package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $installed = $lock['packages']['node_modules/pdfjs-dist']['version'];

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $installed);
        $this->assertSame('pdfjs-assets/'.$installed, config('modules.document_reading.pdfjs_assets'));
    }

    public function test_the_build_copies_the_decoders_and_the_copies_are_not_committed(): void
    {
        $vite = $this->source('vite.config.js');

        // The four folders pdf.js fetches from while it runs, copied under the installed version's own name.
        $this->assertStringContainsString("['wasm', 'cmaps', 'standard_fonts', 'iccs']", $vite);
        $this->assertStringContainsString("resolve(root, 'public/pdfjs-assets')", $vite);
        $this->assertStringContainsString('resolve(to, version, folder)', $vite);
        $this->assertStringContainsString('pdfjsAssets(),', $vite);
        // The capture script is an entry of its own. The drawings viewer stays as it was.
        $this->assertStringContainsString("'resources/js/reader/capture.js'", $vite);
        $this->assertStringContainsString("'resources/js/drawings/viewer.js'", $vite);

        $this->assertContains('/public/pdfjs-assets', array_map(trim(...), explode("\n", $this->source('.gitignore'))));

        // No package was added for any of this.
        $package = json_decode($this->source('package.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['alpinejs', 'konva', 'pdfjs-dist'], array_keys($package['dependencies']));
    }

    public function test_the_capture_script_opens_a_pdf_with_every_decoder_folder_and_draws_one_page_at_a_time(): void
    {
        $script = $this->source('resources/js/reader/capture.js');

        foreach (['wasmUrl: `${assets}wasm/`', 'cMapUrl: `${assets}cmaps/`', 'cMapPacked: true', 'standardFontDataUrl: `${assets}standard_fonts/`', 'iccUrl: `${assets}iccs/`', 'maxImageSize: LARGEST_PDF_PICTURE', 'disableRange: true', 'disableStream: true'] as $option) {
            $this->assertStringContainsString($option, $script);
        }

        $this->assertStringContainsString('const LARGEST_PDF_PICTURE = 40000000;', $script);
        $this->assertStringContainsString('canvas.toBlob((blob) =>', $script);
        $this->assertStringContainsString("'image/jpeg', JPEG_QUALITY", $script);
        $this->assertStringContainsString('const JPEG_QUALITY = 0.9;', $script);
        // Each page is released before the next is drawn, and the document is closed at the end.
        $this->assertStringContainsString('page.cleanup();', $script);
        $this->assertStringContainsString('canvas.width = 0;', $script);
        $this->assertStringContainsString('await task.destroy()', $script);
        // A page larger than A3 is not drawn, and a scan that came out flat is never uploaded.
        $this->assertStringContainsString('const LARGEST_PAGE_POINTS = 1280;', $script);
        $this->assertStringContainsString("status: 'not_decoded'", $script);
        $this->assertStringContainsString("imageOrientation: 'from-image'", $script);
        // The same legacy build the rest of the app already uses, so nothing is bundled twice.
        $this->assertStringContainsString("from 'pdfjs-dist/legacy/build/pdf.mjs'", $script);
    }

    public function test_the_partial_hands_the_script_its_addresses_words_and_asset_folder(): void
    {
        $this->withoutVite();
        app()->setLocale('en');

        $html = view('modules.document_reading._capture')->render();

        $this->assertSame(1, preg_match('/data-assets="([^"]+)"/', $html, $assets));
        $this->assertSame(asset(config('modules.document_reading.pdfjs_assets')), $assets[1]);
        $this->assertStringContainsString('data-half-share="550"', $html);
        $this->assertSame(550, ReadIntake::halfOf('top')['height']);

        $this->assertSame(1, preg_match('/data-urls="([^"]+)"/', $html, $found));
        $urls = json_decode(html_entity_decode($found[1]), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'status' => '/document-reads/__READ__/status',
            'pages' => '/document-reads/__READ__/pages',
            'source' => '/document-reads/__READ__/source',
            'quote' => '/document-reads/__READ__/quote',
            'start' => '/document-reads/__READ__/start',
            'cancel' => '/document-reads/__READ__/cancel',
            'resume' => '/document-reads/__READ__/resume',
            'retry' => '/document-reads/__READ__/pages/__PAGE__/retry',
            'split' => '/document-reads/__READ__/pages/__PAGE__/split',
            'image' => '/document-reads/__READ__/pages/__PAGE__/image',
        ], $urls);

        // Every address the script is given is a route that exists, and every word it may show is there.
        foreach ($urls as $url) {
            $path = str_replace(['__READ__', '__PAGE__'], ['1', '2'], ltrim($url, '/'));
            $this->assertTrue(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => preg_match('#^'.preg_replace('/\{\w+\}/', '\d+', $route->uri()).'$#', $path) === 1), $url);
        }

        $this->assertSame(1, preg_match('/data-words="([^"]+)"/', $html, $found));
        $words = json_decode(html_entity_decode($found[1]), true, flags: JSON_THROW_ON_ERROR);
        $script = $this->source('resources/js/reader/capture.js');
        preg_match_all("/words\('([a-z_]+)'/", $script, $used);

        $this->assertNotEmpty($used[1]);
        $this->assertSame([], array_values(array_diff(array_unique($used[1]), array_keys($words))), 'Words the script asks for that the partial does not carry.');
        $this->assertSame('Page :n could not be drawn in this browser. Export the scan at 300 dpi or take photos of the pages.', $words['not_decoded']);

        $this->assertStringContainsString('<noscript>', $html);
        $this->assertStringContainsString('This screen needs JavaScript.', $html);

        // In the person's own language.
        app()->setLocale('mr');
        $marathi = view('modules.document_reading._capture')->render();
        $this->assertStringContainsString(__('This screen needs JavaScript.', [], 'mr'), $marathi);
        $this->assertStringNotContainsString('This screen needs JavaScript.', $marathi);
        app()->setLocale('en');
    }

    public function test_a_failure_without_a_sentence_of_the_modules_own_is_told_in_the_persons_language(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed here, so the script\'s error handling cannot be run.');
        }

        $this->withoutVite();
        app()->setLocale('mr');
        $html = view('modules.document_reading._capture')->render();
        app()->setLocale('en');
        $this->assertSame(1, preg_match('/data-words="([^"]+)"/', $html, $found));
        $words = json_decode(html_entity_decode($found[1]), true, flags: JSON_THROW_ON_ERROR);

        // Every word the error handling may ask for is one the partial carries.
        preg_match_all("/return '([a-z_]+)';/", $this->source('resources/js/reader/errors.js'), $asked);
        $this->assertNotEmpty($asked[1]);
        $this->assertSame([], array_values(array_diff([...$asked[1], 'upload_failed'], array_keys($words))));

        $answers = [
            'proxy_502' => [502, null],
            'proxy_504' => [504, null],
            'too_large_413' => [413, null],
            'plain_403' => [403, ['message' => '']],
            'gate_403' => [403, ['message' => 'This action is unauthorized.']],
            'expired_419' => [419, ['message' => 'CSRF token mismatch.']],
            'throttled_429' => [429, ['message' => 'Too Many Attempts.']],
            'framework_500' => [500, ['message' => 'Server Error']],
            'gone_404' => [404, ['message' => 'Not Found']],
            'own_refusal' => [409, ['code' => 'wrong_state', 'message' => 'आपल्या भाषेतील वाक्य', 'status' => 'running']],
            'form_check' => [422, ['message' => 'The image field is required.', 'errors' => ['image' => ['चित्र निवडा.']]]],
        ];

        $program = 'import { ReaderError, refusalOf, uploadFailure, worthRetrying } from "./resources/js/reader/errors.js";'
            .'const words = JSON.parse(process.argv[1]); const answers = JSON.parse(process.argv[2]);'
            .'const say = (key, values = {}) => Object.entries(values).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), words[key] ?? key);'
            .'const out = {};'
            .'for (const [name, [status, body]] of Object.entries(answers)) { const error = refusalOf(status, body ?? {}, say); out[name] = { code: error.code, message: error.message, retry: worthRetrying(error), ok: error instanceof ReaderError }; }'
            .'out.after_proxy = uploadFailure(refusalOf(502, {}, say), 7, say);'
            .'out.after_network = uploadFailure(new TypeError("Failed to fetch"), 7, say);'
            .'out.after_not_stored = uploadFailure(refusalOf(503, { code: "not_stored", message: "जतन झाले नाही" }, say), 7, say);'
            .'for (const key of ["after_proxy", "after_network", "after_not_stored"]) { out[key] = { code: out[key].code, message: out[key].message }; }'
            .'console.log(JSON.stringify(out));';

        $run = new Process([$node, '--input-type=module', '-e', $program, json_encode($words, JSON_UNESCAPED_UNICODE), json_encode($answers, JSON_UNESCAPED_UNICODE)], base_path());
        $run->mustRun();
        $got = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $said = fn (string $english) => __($english, [], 'mr');

        $expected = [
            'proxy_502' => ['http_502', $said('Something went wrong on our side. Try again in a few minutes.'), true],
            'proxy_504' => ['http_504', $said('Something went wrong on our side. Try again in a few minutes.'), true],
            'too_large_413' => ['http_413', $said('This file is too large to upload.'), false],
            'plain_403' => ['http_403', $said('You are not allowed to do this.'), false],
            'gate_403' => ['http_403', $said('You are not allowed to do this.'), false],
            'expired_419' => ['http_419', $said('You have been signed out. Sign in again, then try once more.'), false],
            'throttled_429' => ['http_429', $said('Too many tries in a short time. Wait a minute and try again.'), true],
            'framework_500' => ['http_500', $said('Something went wrong on our side. Try again in a few minutes.'), true],
            'gone_404' => ['http_404', $said('This document is no longer here. Reload the page.'), false],
            'own_refusal' => ['wrong_state', 'आपल्या भाषेतील वाक्य', false],
            'form_check' => ['http_422', 'चित्र निवडा.', false],
        ];

        foreach ($expected as $name => [$code, $message, $retry]) {
            $this->assertSame(['code' => $code, 'message' => $message, 'retry' => $retry, 'ok' => true], $got[$name], $name);
            $this->assertNotSame('', $message);
        }

        // After the last try: the page that could not be uploaded, unless the module said something of its own.
        $page7 = __('Page :n could not be uploaded. Check your connection and try again.', ['n' => 7], 'mr');
        $this->assertSame(['code' => 'upload_failed', 'message' => $page7], $got['after_proxy']);
        $this->assertSame(['code' => 'upload_failed', 'message' => $page7], $got['after_network']);
        $this->assertSame(['code' => 'not_stored', 'message' => 'जतन झाले नाही'], $got['after_not_stored']);

        // No English from the framework, and no bare code, reaches the person.
        foreach ($got as $name => $error) {
            foreach (['Server Error', 'CSRF token mismatch.', 'Too Many Attempts.', 'This action is unauthorized.', 'Not Found'] as $english) {
                $this->assertStringNotContainsString($english, $error['message'], $name);
            }

            $this->assertDoesNotMatchRegularExpression('/^http_\d+$/', $error['message'], $name);
        }

        // The capture script uses this handling for every call and for the end of an upload.
        $script = $this->source('resources/js/reader/capture.js');
        $this->assertStringContainsString("from './errors.js'", $script);
        $this->assertStringContainsString('throw refusalOf(response.status, data, words);', $script);
        $this->assertStringContainsString('throw uploadFailure(last, pageNo, words);', $script);
        $this->assertStringNotContainsString('super(message || code)', $script.$this->source('resources/js/reader/errors.js'));
    }

    public function test_the_browsers_page_size_is_the_servers(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed here, so the script\'s size function cannot be run.');
        }

        $sizes = [[595, 842], [842, 1191], [612, 1008], [612, 792], [4000, 3000], [200, 100], [1191, 842], [420, 595], [300, 2000], [1280, 1], [1, 1]];

        mt_srand(20261113);

        for ($i = 0; $i < 300; $i++) {
            $sizes[] = [mt_rand(1, 1400), mt_rand(1, 1400)];
        }

        $program = 'import { targetSize, photoSize, imageTokens } from "./resources/js/reader/size.js";'
            .'const sizes = JSON.parse(process.argv[1]);'
            .'console.log(JSON.stringify(sizes.map(([w, h]) => [targetSize(w, h), photoSize(w, h), imageTokens(w, h)])));';

        $run = new Process([$node, '--input-type=module', '-e', $program, json_encode($sizes)], base_path());
        $run->mustRun();
        $browser = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($sizes as $index => [$width, $height]) {
            $this->assertSame(ImageTokens::largestFit($width, $height), $browser[$index][0], "Page of {$width} x {$height}");
            $this->assertSame(ImageTokens::resized($width, $height), $browser[$index][1], "Photo of {$width} x {$height}");
            $this->assertSame(ImageTokens::count($width, $height), $browser[$index][2]);
            // Whatever the browser draws at these sizes, the server takes as sent.
            $this->assertTrue(ImageTokens::fits(...$browser[$index][0]));
        }

        $this->assertSame([1622, 2296], $browser[0][0]);
    }

    public function test_the_self_check_has_one_scanned_page_of_each_kind(): void
    {
        $this->assertSame(['ccitt-g4', 'jbig2', 'jpeg2000', 'dct'], ReaderFixtures::names());

        foreach (ReaderFixtures::FILES as $name => $fixture) {
            $path = ReaderFixtures::path($name);
            $this->assertNotNull($path, $name);

            $bytes = (string) file_get_contents($path);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertStringContainsString('/Filter /'.$fixture['filter'], $bytes);
            $this->assertSame(1, substr_count($bytes, '/Type /Page '), 'one page');
            $this->assertSame(1, substr_count($bytes, '/Subtype /Image'), 'the page is one picture');
            $this->assertLessThan(200 * 1024, strlen($bytes));
        }

        // Only these names lead to a file: the route that serves them cannot be walked out of the folder.
        $this->assertNull(ReaderFixtures::path('../../.env'));
        $this->assertNull(ReaderFixtures::path('make_fixtures.py'));
        $this->assertNull(ReaderFixtures::path(''));
    }
}
