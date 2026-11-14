<?php

namespace Tests\Feature\SlipReading;

use Illuminate\Support\Facades\File;
use Tests\Feature\Website\BannedWording;

/** Every line the challan reader can show has Hindi and Marathi, and none of it says what must not be said. */
class SlipReadingTranslationsTest extends SlipReadingTestCase
{
    /** @return list<string> */
    private function moduleFiles(): array
    {
        return array_map(fn (\SplFileInfo $file) => $file->getPathname(), [
            ...File::allFiles(app_path('Services/SlipReading')),
            ...File::allFiles(app_path('Http/Controllers/SlipReading')),
            ...File::allFiles(resource_path('views/modules/slip_reading')),
        ]);
    }

    /** @return list<string> */
    private function moduleStrings(): array
    {
        return collect($this->moduleFiles())->flatMap(function (string $file) {
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", File::get($file), $matches);

            return array_map(fn (string $key) => str_replace("\\'", "'", $key), $matches[1]);
        })->unique()->values()->all();
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode(File::get(lang_path("modules/slip_reading/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_string_is_translated_into_hindi_and_marathi_in_the_modules_own_files(): void
    {
        $strings = $this->moduleStrings();
        $this->assertGreaterThan(50, count($strings));

        foreach (['hi', 'mr'] as $locale) {
            $translations = $this->own($locale);

            foreach ($strings as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
                $this->assertNotSame('', trim((string) $translations[$key]), "Blank {$locale} translation: {$key}");
            }

            $this->assertSame([], array_values(array_diff(array_keys($translations), $strings)), "Unused {$locale} lines");
        }
    }

    public function test_both_languages_cover_the_same_strings_in_order_and_keep_their_placeholders(): void
    {
        $hindi = $this->own('hi');
        $marathi = $this->own('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        $sorted = array_keys($hindi);
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, array_keys($hindi), 'The lines are kept in order, so two people adding lines do not collide.');

        foreach ([$hindi, $marathi] as $translations) {
            foreach ($translations as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing(array_values(array_unique($expected[0])), array_values(array_unique($actual[0])), "Placeholders differ in: {$english}");
                $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $translated, $english);
            }
        }
    }

    public function test_a_line_shared_with_another_screen_is_worded_the_same_there(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $elsewhere = json_decode(File::get(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);

            foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
                if (basename(dirname($file)) !== 'slip_reading') {
                    $elsewhere = [...$elsewhere, ...json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR)];
                }
            }

            foreach ($this->own($locale) as $english => $translated) {
                if (isset($elsewhere[$english])) {
                    $this->assertSame($elsewhere[$english], $translated, "{$locale}: \"{$english}\" would change on another module's screen.");
                }
            }
        }
    }

    public function test_wording_that_must_not_be_used_is_absent(): void
    {
        $files = [
            ...$this->moduleFiles(),
            lang_path('modules/slip_reading/hi.json'),
            lang_path('modules/slip_reading/mr.json'),
            config_path('modules/slip_reading.php'),
            base_path('routes/modules/slip_reading.php'),
            base_path('routes/api_modules/slip_reading.php'),
        ];

        foreach ($files as $file) {
            $this->assertSame([], BannedWording::found((string) file_get_contents($file)), 'Not to be said in '.str_replace(base_path().'/', '', $file));
        }

        // The wording the design allows for this reader, word for word.
        $this->assertContains('Each field shows the text as read, beside the photo. You confirm each field against the photo.', $this->moduleStrings());
    }
}
