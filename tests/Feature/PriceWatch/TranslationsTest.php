<?php

namespace Tests\Feature\PriceWatch;

use App\Services\PriceWatch\Alerts\PriceAlertSource;
use App\Services\PriceWatch\PriceRecorder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationsTest extends TestCase
{
    /** @return list<string> */
    private function moduleStrings(): array
    {
        $files = collect([
            ...File::allFiles(app_path('Services/PriceWatch')),
            ...File::allFiles(app_path('Http/Controllers/PriceWatch')),
            ...File::allFiles(resource_path('views/modules/price_watch')),
        ]);

        // __('...') calls, and the title: and message: wording of every Finding, which is translated when shown.
        $strings = $files->flatMap(function (\SplFileInfo $file) {
            preg_match_all("/(?:__\(|\\b(?:title|message):)\s*'((?:[^'\\\\]|\\\\.)*)'/", File::get($file->getPathname()), $matches);

            return array_map(fn (string $key) => str_replace("\\'", "'", $key), $matches[1]);
        });

        // Wording that reaches __() as a variable: menu labels and groups, the default materials, the sources, the admin tab, the budget alert.
        $dynamic = collect(config('modules.price_watch.nav'))->pluck('label')
            ->merge(collect(config('modules.price_watch.nav'))->pluck('group'))
            ->merge(collect(config('modules.price_watch.defaults'))->pluck('name'))
            ->merge(collect(app(PriceRecorder::class)->sources())->map(fn ($source) => $source->label()))
            ->merge([PriceAlertSource::IMPACT_OF_BUDGET, PriceAlertSource::IMPACT_OF_BOQ, 'Material prices']);

        return $strings->merge($dynamic)->unique()->values()->all();
    }

    public function test_every_string_is_translated_into_hindi_and_marathi(): void
    {
        $this->assertGreaterThan(150, count($this->moduleStrings()));

        foreach (['hi', 'mr'] as $locale) {
            $translations = json_decode(File::get(lang_path($locale.'.json')), true);

            foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
                $translations = [...$translations, ...json_decode(File::get($file), true)];
            }

            foreach ($this->moduleStrings() as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
                $this->assertNotSame('', trim((string) $translations[$key]), "Blank {$locale} translation: {$key}");
            }
        }
    }

    public function test_both_languages_cover_the_same_strings_and_keep_their_placeholders(): void
    {
        $hindi = json_decode(File::get(lang_path('modules/price_watch/hi.json')), true, flags: JSON_THROW_ON_ERROR);
        $marathi = json_decode(File::get(lang_path('modules/price_watch/mr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        foreach ([$hindi, $marathi] as $translations) {
            foreach ($translations as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), "Placeholders differ in: {$english}");
            }
        }
    }
}
