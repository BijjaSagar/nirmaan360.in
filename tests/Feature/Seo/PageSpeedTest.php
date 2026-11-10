<?php

namespace Tests\Feature\Seo;

use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * The page-speed basics that can be read off the HTML: pictures state their size so nothing jumps when they
 * arrive, what is below the first screen waits, text shows before web fonts arrive, and nothing in the head
 * holds the page up.
 */
class PageSpeedTest extends TestCase
{
    use BuildsTenant, ReadsPageHead, RefreshDatabase;

    private const PAGES = ['/', '/pricing', '/calculators', '/ai', '/contact', '/privacy', '/terms', '/login', '/register', '/hi'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_picture_states_its_real_size(): void
    {
        $seen = 0;

        foreach (self::PAGES as $path) {
            $this->flushSession();

            foreach ($this->document($this->get($path)->assertOk())->querySelectorAll('img') as $picture) {
                $source = (string) $picture->getAttribute('src');
                $file = public_path((string) parse_url($source, PHP_URL_PATH));

                $this->assertFileExists($file, "{$path} shows {$source}");
                $this->assertSame(
                    array_slice(getimagesize($file), 0, 2),
                    [(int) $picture->getAttribute('width'), (int) $picture->getAttribute('height')],
                    "{$path}: {$source} states its real width and height, so the browser keeps its space before it loads.",
                );
                $this->assertNotSame('', (string) $picture->getAttribute('alt'), "{$source} has a text alternative");
                $seen++;
            }
        }

        $this->assertGreaterThan(10, $seen, 'The pages do carry pictures.');
    }

    public function test_the_header_logo_loads_at_once_and_the_footer_logo_waits(): void
    {
        foreach (['/', '/pricing', '/contact'] as $path) {
            $document = $this->document($this->get($path));

            $this->assertSame('eager', $document->querySelector('header img')->getAttribute('loading'), "{$path}: the logo at the top is wanted straight away.");
            $this->assertSame('lazy', $document->querySelector('footer img')->getAttribute('loading'), "{$path}: the footer logo is below the first screen.");
            $this->assertSame('async', $document->querySelector('footer img')->getAttribute('decoding'));
        }
    }

    public function test_the_header_keeps_its_height_whatever_loads(): void
    {
        $document = $this->document($this->get('/'));
        $bar = $document->querySelector('header > div');
        $logo = $document->querySelector('header img');

        $this->assertStringContainsString('h-[72px]', (string) $bar->getAttribute('class'), 'The menu bar has a fixed height.');
        $this->assertStringContainsString('h-9', (string) $logo->getAttribute('class'));
        $this->assertSame(['720', '166'], [$logo->getAttribute('width'), $logo->getAttribute('height')], 'With its size stated, the logo holds its width before it arrives and the menu beside it stays put.');
    }

    public function test_an_uploaded_logo_states_its_size_too(): void
    {
        Storage::fake();
        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->post('/admin/website', ['logo' => UploadedFile::fake()->image('logo.png', 400, 120)])->assertSessionHasNoErrors();

        $logo = $this->document($this->get('/'))->querySelector('header img');
        $this->assertSame(['400', '120'], [$logo->getAttribute('width'), $logo->getAttribute('height')]);

        // A logo uploaded before sizes were kept has none, and is drawn as before.
        app(PlatformSettings::class)->set(['logo_width' => null, 'logo_height' => null]);
        $logo = $this->document($this->get('/'))->querySelector('header img');
        $this->assertFalse($logo->hasAttribute('width'));

        $this->actingAs($admin)->post('/admin/website', ['remove_logo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(app(PlatformSettings::class)->get('logo_width'));
    }

    public function test_text_shows_at_once_while_web_fonts_load(): void
    {
        foreach (['/', '/login', '/dashboard'] as $path) {
            if ($path === '/dashboard') {
                $this->actingAs($this->member($this->tenant(), 'owner'));
            }

            $document = $this->document($this->get($path)->assertOk());
            $sheets = 0;

            foreach ($document->querySelectorAll('head link[rel="stylesheet"]') as $sheet) {
                $address = (string) $sheet->getAttribute('href');

                if (str_contains($address, 'fonts.bunny.net')) {
                    $this->assertStringContainsString('display=swap', $address, "{$path}: without display=swap the text stays blank until the font arrives.");
                    $sheets++;
                }
            }

            $this->assertGreaterThan(0, $sheets);

            $early = [];

            foreach ($document->querySelectorAll('head link[rel="preconnect"]') as $link) {
                $early[] = $link->getAttribute('href').($link->hasAttribute('crossorigin') ? ' crossorigin' : '');
            }

            // Font files are fetched with CORS, so their connection has to be opened that way to be reused.
            $this->assertContains('https://fonts.bunny.net', $early);
            $this->assertContains('https://fonts.bunny.net crossorigin', $early);
            $this->assertContains('https://cdn.jsdelivr.net crossorigin', $early);
        }
    }

    public function test_no_script_in_the_head_holds_the_page_up(): void
    {
        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Run npm run build to check the real script tags.');
        }

        $this->withVite();
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF']);

        foreach (['/', '/pricing', '/calculators'] as $path) {
            foreach ($this->document($this->get($path)->assertOk())->querySelectorAll('head script[src]') as $script) {
                $waits = $script->getAttribute('type') === 'module' || $script->hasAttribute('defer') || $script->hasAttribute('async');

                $this->assertTrue($waits, "{$path}: {$script->getAttribute('src')} would stop the page being drawn while it downloads.");
            }
        }
    }

    public function test_the_consent_question_floats_over_the_page_instead_of_pushing_it(): void
    {
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF']);
        $document = $this->document($this->get('/'));

        // It waits in a <template>, so it is not part of the page until the script shows it.
        $this->assertNull($document->querySelector('body [data-consent-banner]'));

        preg_match('/<template id="analytics-consent">(.+?)<\/template>/s', (string) $this->get('/')->getContent(), $template);
        $banner = $this->document('<!doctype html><body>'.$template[1])->querySelector('[data-consent-banner]');
        $classes = explode(' ', (string) $banner->getAttribute('class'));

        $this->assertContains('fixed', $classes, 'Fixed to the window, it moves nothing on the page.');
        $this->assertContains('bottom-3', $classes);
        $this->assertCount(2, $banner->querySelectorAll('button[data-consent]'));
    }
}
