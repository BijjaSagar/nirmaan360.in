<?php

namespace Tests\Feature\Notify;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationsTest extends TestCase
{
    /** @return list<string> */
    private function moduleStrings(): array
    {
        $files = collect([
            ...File::allFiles(app_path('Services/Notify')),
            ...File::allFiles(app_path('Http/Controllers/Notify')),
            ...File::allFiles(resource_path('views/modules/notify')),
        ]);

        $strings = $files->flatMap(function ($file) {
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);

            return array_map(fn (string $key) => str_replace("\\'", "'", $key), $matches[1]);
        });

        $navigation = collect(config('modules.notify.nav'))->flatMap(fn (array $item) => array_filter([$item['label'], $item['group'] ?? null]));

        return $strings->merge($navigation)->unique()->values()->all();
    }

    public function test_every_string_is_translated_into_hindi_and_marathi(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $translations = [
                ...json_decode(File::get(lang_path($locale.'.json')), true),
                ...json_decode(File::get(lang_path('modules/notify/'.$locale.'.json')), true),
            ];

            foreach ($this->moduleStrings() as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
            }
        }
    }

    public function test_both_languages_cover_the_same_strings(): void
    {
        $this->assertSame(
            array_keys(json_decode(File::get(lang_path('modules/notify/hi.json')), true)),
            array_keys(json_decode(File::get(lang_path('modules/notify/mr.json')), true)),
        );
    }

    public function test_placeholders_survive_translation(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            foreach (json_decode(File::get(lang_path('modules/notify/'.$locale.'.json')), true) as $key => $value) {
                preg_match_all('/:[a-z]+/', $key, $expected);
                preg_match_all('/:[a-z]+/', $value, $actual);

                $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), "{$locale}: {$key}");
            }
        }
    }
}
