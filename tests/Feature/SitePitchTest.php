<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSettingsController;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home page story: material, contractor payment and time against the BOQ.
 * Every line has Hindi and Marathi, every section can be switched off from /admin, and nothing is promised.
 */
class SitePitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return list<string> Every text key the home page and the public layout pass through __(). */
    private function homeTexts(): array
    {
        $translator = app('translator');
        $translator->startRecording();

        try {
            $this->get('/')->assertOk();
        } finally {
            $keys = $translator->stopRecording();
        }

        return $keys;
    }

    /** Short capital-letter terms such as MT, GST or UPI read the same in every language. */
    private function mayStayAsIs(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9 \/.#&%-]*s?$/', $text) && strlen($text) <= 8;
    }

    public function test_the_page_leads_with_material_payment_and_time(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Three things go untracked on a site.')
            ->assertSee('Material, contractor payment and time.')
            ->assertSee('Paid 90%, Work 70%')
            ->assertSee('5 bags unaccounted')
            ->assertSee('Sample project data')
            ->assertSee('images/logo.webp', false);
    }

    public function test_every_line_on_the_home_page_has_hindi_and_marathi(): void
    {
        $texts = $this->homeTexts();
        $this->assertGreaterThan(150, count($texts), 'The recording caught the page text.');

        $missing = [];

        foreach ($texts as $text) {
            foreach (['hi', 'mr'] as $locale) {
                if (app('translator')->defaultLine($text, $locale) === $text && ! $this->mayStayAsIs($text)) {
                    $missing[] = "$locale: $text";
                }
            }
        }

        $this->assertSame([], $missing, 'No translation for: '.implode(' | ', $missing));
    }

    public function test_placeholders_survive_translation(): void
    {
        $broken = [];

        foreach ($this->homeTexts() as $text) {
            preg_match_all('/:[a-z_]+/', $text, $expected);

            foreach (['hi', 'mr'] as $locale) {
                $line = app('translator')->defaultLine($text, $locale);

                foreach ($expected[0] as $placeholder) {
                    if (! str_contains($line, $placeholder)) {
                        $broken[] = "$locale: $text";
                    }
                }
            }
        }

        $this->assertSame([], $broken, 'A placeholder was lost in: '.implode(' | ', $broken));
    }

    public function test_the_language_files_are_valid_and_in_step(): void
    {
        $hi = json_decode((string) file_get_contents(lang_path('modules/site_pitch/hi.json')), true, flags: JSON_THROW_ON_ERROR);
        $mr = json_decode((string) file_get_contents(lang_path('modules/site_pitch/mr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($hi), array_keys($mr), 'lang/modules/site_pitch has the same keys in Hindi and Marathi');

        foreach ($hi + $mr as $key => $line) {
            $this->assertNotSame('', trim((string) $line), "Empty line for $key");
            $this->assertNotSame($key, $line, "Left in English: $key");
        }
    }

    public function test_every_section_is_listed_for_the_admin(): void
    {
        preg_match_all("/site_on\\('([a-z_]+)'\\)/", (string) file_get_contents(resource_path('views/public/home.blade.php')), $matches);

        $this->assertEqualsCanonicalizing(array_keys(AdminSettingsController::SECTIONS), array_values(array_unique($matches[1])));
    }

    public function test_a_section_can_be_switched_off(): void
    {
        $this->get('/')->assertSee('Pay for work done, not for asking.')->assertSee('Nothing gets plastered over.');

        app(PlatformSettings::class)->set(['section_payment' => '0', 'section_trades' => '0']);

        $this->get('/')->assertOk()
            ->assertDontSee('Pay for work done, not for asking.')
            ->assertDontSee('Nothing gets plastered over.')
            ->assertSee('Every bag and every bundle, against the BOQ.');
    }

    public function test_nothing_is_promised_or_named(): void
    {
        $html = strtolower($this->get('/')->getContent());

        foreach (['guarantee', 'save at least', 'save 3', 'cut wastage', 'arshad', 'primavera', 'ms project', 'sargam'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $html, "The home page must not say \"$phrase\".");
        }
    }
}
