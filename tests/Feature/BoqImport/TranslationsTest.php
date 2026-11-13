<?php

namespace Tests\Feature\BoqImport;

use Tests\TestCase;

/** Every text of the BOQ sheet import reads in Hindi and Marathi. */
class TranslationsTest extends TestCase
{
    /** @return array<string, string> */
    private function strings(string $locale): array
    {
        $strings = [];

        foreach ([lang_path($locale.'.json'), lang_path("modules/reports/{$locale}.json"), lang_path("modules/boq_import/{$locale}.json")] as $file) {
            $strings = [...$strings, ...json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)];
        }

        return $strings;
    }

    /** @return list<string> */
    private function usedTexts(): array
    {
        $files = [
            ...glob(app_path('Services/Imports/*.php')),
            ...glob(app_path('Http/Controllers/BoqImport/*.php')),
            ...glob(resource_path('views/modules/boq_import/*.blade.php')),
        ];
        $texts = [];

        foreach ($files as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        return array_values(array_unique($texts));
    }

    public function test_every_text_has_a_hindi_and_a_marathi_translation(): void
    {
        $this->assertGreaterThan(100, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $strings = $this->strings($locale);
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => trim((string) ($strings[$text] ?? '')) === ''));

            $this->assertSame([], $missing, "Missing {$locale} translations");
        }
    }

    public function test_the_two_files_are_sorted_alike_and_keep_their_placeholders(): void
    {
        $hindi = json_decode((string) file_get_contents(lang_path('modules/boq_import/hi.json')), true);
        $marathi = json_decode((string) file_get_contents(lang_path('modules/boq_import/mr.json')), true);
        $keys = array_keys($hindi);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
        $this->assertSame($keys, array_keys($marathi));

        foreach ([$hindi, $marathi] as $translations) {
            foreach ($translations as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
            }
        }
    }
}
