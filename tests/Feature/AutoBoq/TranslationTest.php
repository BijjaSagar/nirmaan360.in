<?php

namespace Tests\Feature\AutoBoq;

use App\Services\AutoBoq\Assumptions;
use App\Services\AutoBoq\AutoBoqCalculator;

class TranslationTest extends AutoBoqTestCase
{
    /** @return array<string,string> */
    private function strings(string $locale): array
    {
        $strings = json_decode((string) file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
            $strings = [...$strings, ...json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)];
        }

        return $strings;
    }

    /** Every text literal passed to __() in the module's files, and the texts it passes by variable. */
    private function usedTexts(): array
    {
        $files = [
            ...glob(app_path('Services/AutoBoq/*.php')),
            ...glob(app_path('Http/Controllers/AutoBoq/*.php')),
            ...glob(resource_path('views/modules/auto_boq/*.blade.php')),
        ];
        $texts = [];

        foreach ($files as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        foreach (AutoBoqCalculator::LINES as [$description]) {
            $texts[] = $description;
        }

        array_push($texts, ...array_values(AutoBoqCalculator::MANUAL_NOTES), ...array_values(AutoBoqCalculator::GROUPS), ...array_values(Assumptions::GROUPS));

        foreach (Assumptions::CATALOGUE as [$default, $unit, $group, $label, $note]) {
            array_push($texts, $label, $note);
        }

        foreach (config('modules.auto_boq.nav', []) as $item) {
            array_push($texts, $item['label'], $item['group']);
        }

        return array_values(array_unique(array_filter($texts, fn (string $text) => mb_strlen($text) > 1)));
    }

    public function test_every_text_in_the_module_has_a_hindi_and_a_marathi_translation(): void
    {
        $this->assertGreaterThan(150, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $strings = $this->strings($locale);
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => ! array_key_exists($text, $strings) || trim((string) $strings[$text]) === ''));

            $this->assertSame([], $missing, "Missing {$locale} translations");
        }
    }

    public function test_the_two_language_files_cover_the_same_texts_and_keep_their_placeholders(): void
    {
        $hindi = json_decode((string) file_get_contents(lang_path('modules/auto_boq/hi.json')), true, flags: JSON_THROW_ON_ERROR);
        $marathi = json_decode((string) file_get_contents(lang_path('modules/auto_boq/mr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        foreach ([$hindi, $marathi] as $translations) {
            foreach ($translations as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
            }
        }
    }
}
