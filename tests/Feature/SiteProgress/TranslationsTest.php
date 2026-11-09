<?php

namespace Tests\Feature\SiteProgress;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\FieldOps\ModuleStrings;

/** Everything the module shows has a Hindi and a Marathi line, and nothing is left in English by accident. */
class TranslationsTest extends SiteProgressTestCase
{
    private const SOURCES = [
        'resources/views/modules/site_progress',
        'app/Http/Controllers/SiteProgress',
        'app/Services/SiteProgress',
        'app/Http/Presenters/SiteProgressPresenter.php',
        'app/Models/Concerns/ProgressAppendOnly.php',
    ];

    /** @return list<string> */
    private function strings(): array
    {
        $found = [];

        foreach (self::SOURCES as $path) {
            foreach ($this->files(base_path($path)) as $file) {
                foreach (ModuleStrings::literals((string) file_get_contents($file)) as $text) {
                    $found[$text] = true;
                }
            }
        }

        // Labels in the config are shown through __() in the views and the API.
        foreach (config('modules.site_progress.mep_trades') as $trade) {
            $found[$trade['label']] = true;

            foreach ($trade['stages'] as $stage) {
                $found[$stage['label']] = true;

                if (isset($stage['hint'])) {
                    $found[$stage['hint']] = true;
                }
            }
        }

        foreach ([...config('modules.site_progress.mep_statuses'), ...config('modules.site_progress.finishing_works')] as $label) {
            $found[$label] = true;
        }

        foreach (config('modules.site_progress.nav') as $item) {
            $found[$item['label']] = true;
        }

        return array_keys($found);
    }

    /** @return list<string> */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/\.php$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function mayStayAsIs(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9 \/.#&%-]*s?$/', $text) && strlen($text) <= 8;
    }

    public function test_every_text_has_hindi_and_marathi(): void
    {
        $strings = $this->strings();
        $this->assertGreaterThan(150, count($strings), 'The scan found the views, controllers and config.');

        $missing = ['hi' => [], 'mr' => []];

        foreach ($strings as $text) {
            foreach (['hi', 'mr'] as $locale) {
                if (app('translator')->defaultLine($text, $locale) === $text && ! $this->mayStayAsIs($text)) {
                    $missing[$locale][] = $text;
                }
            }
        }

        $this->assertSame([], $missing['hi'], 'No Hindi line for: '.implode(' | ', $missing['hi']));
        $this->assertSame([], $missing['mr'], 'No Marathi line for: '.implode(' | ', $missing['mr']));
    }

    public function test_placeholders_survive_translation(): void
    {
        $broken = [];

        foreach ($this->strings() as $text) {
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
        $hi = json_decode((string) file_get_contents(lang_path('modules/site_progress/hi.json')), true, flags: JSON_THROW_ON_ERROR);
        $mr = json_decode((string) file_get_contents(lang_path('modules/site_progress/mr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($hi), array_keys($mr));

        foreach ($hi + $mr as $key => $line) {
            $this->assertNotSame('', trim((string) $line), "Empty line for $key");
        }
    }

    public function test_the_screens_show_the_chosen_language(): void
    {
        $this->owner->update(['locale' => 'hi']);
        $this->actingAs($this->owner);

        $this->get(route('site-stages.index', $this->project))->assertOk()->assertSee('चरण ट्रैकर')->assertSee('प्लंबिंग');

        $this->owner->update(['locale' => 'mr']);
        $this->get(route('site-progress.index', $this->project))->assertOk()->assertSee('साइट प्रगती')->assertSee('वेळ आणि काम');
    }
}
