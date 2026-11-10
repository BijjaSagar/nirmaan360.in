<?php

namespace Tests\Feature\ContractorControl;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Everything the contractor screens show has a Hindi and a Marathi line. */
class TranslationsTest extends TestCase
{
    /** @return list<string> */
    private function moduleStrings(): array
    {
        $files = collect([
            ...File::allFiles(app_path('Services/ContractorControl')),
            ...File::allFiles(app_path('Http/Controllers/ContractorControl')),
            ...File::allFiles(resource_path('views/modules/contractor_control')),
            app_path('Http/Presenters/ContractorControlPresenter.php'),
        ]);

        // The text of every __('...') and trans_choice('...') call.
        $strings = $files->flatMap(function (\SplFileInfo|string $file) {
            preg_match_all('/(?:__|trans_choice)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', File::get((string) $file), $matches);

            return array_map(fn (string $text) => stripcslashes($text), $matches[2]);
        });

        // Wording that reaches __() as a variable: the menu.
        $menu = collect(config('modules.contractor_control.nav'))->flatMap(fn (array $item) => [$item['label'], $item['group']]);

        return $strings->merge($menu)->unique()->values()->all();
    }

    /** @return array<string, string> */
    private function lines(string $locale): array
    {
        $lines = json_decode(File::get(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
            $lines = [...$lines, ...json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR)];
        }

        return $lines;
    }

    public function test_every_string_has_a_hindi_and_a_marathi_line(): void
    {
        $strings = $this->moduleStrings();
        $this->assertGreaterThan(200, count($strings), 'The scan found the views, controllers and services.');

        foreach (['hi', 'mr'] as $locale) {
            $lines = $this->lines($locale);
            $missing = array_values(array_filter($strings, fn (string $text) => trim((string) ($lines[$text] ?? '')) === ''));

            $this->assertSame([], $missing, "No {$locale} line for: ".implode(' | ', $missing));
        }
    }

    public function test_both_languages_cover_the_same_strings_and_keep_their_placeholders_and_plurals(): void
    {
        $hindi = json_decode(File::get(lang_path('modules/contractor_control/hi.json')), true, flags: JSON_THROW_ON_ERROR);
        $marathi = json_decode(File::get(lang_path('modules/contractor_control/mr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        foreach ([$hindi, $marathi] as $lines) {
            foreach ($lines as $english => $translated) {
                $this->assertNotSame('', trim((string) $translated), "Empty line for: {$english}");

                preg_match_all('/:[a-z_]+/', $english, $expected);
                preg_match_all('/:[a-z_]+/', $translated, $actual);
                $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), "Placeholders differ in: {$english}");

                // A count line keeps both of its forms, or the plural would fall back to English.
                $this->assertSame(substr_count($english, '|'), substr_count($translated, '|'), "Plural forms differ in: {$english}");
            }
        }
    }
}
