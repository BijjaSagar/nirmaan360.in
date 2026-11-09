<?php

namespace Tests\Feature\SiteAlerts;

use App\Services\SiteAlerts\Spend;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationsTest extends TestCase
{
    /** @return list<string> */
    private function moduleStrings(): array
    {
        $files = collect([
            ...File::allFiles(app_path('Services/SiteAlerts')),
            ...File::allFiles(app_path('Http/Controllers/SiteAlerts')),
            ...File::allFiles(resource_path('views/modules/site_alerts')),
            new \SplFileInfo(app_path('Console/Commands/ScanSiteAlerts.php')),
            new \SplFileInfo(app_path('Http/Presenters/AlertPresenter.php')),
        ]);

        // __('...') calls, and the title: and message: wording of every Finding, which is translated when shown.
        $strings = $files->flatMap(function (\SplFileInfo $file) {
            preg_match_all("/(?:__\(|\\b(?:title|message):)\s*'((?:[^'\\\\]|\\\\.)*)'/", File::get($file->getPathname()), $matches);

            return array_map(fn (string $key) => str_replace("\\'", "'", $key), $matches[1]);
        });

        // Wording that reaches __() as a variable: menu labels, trade names, spend sources and severities.
        $dynamic = collect(config('modules.site_alerts.nav'))->pluck('label')
            ->merge(collect(config('modules.site_alerts.focus_trades'))->pluck('label'))
            ->merge(collect(app(Spend::class)->sources())->map(fn ($source) => $source->label()))
            ->merge(['Info', 'Warning', 'Critical', 'Open', 'Resolved']);

        return $strings->merge($dynamic)->unique()->values()->all();
    }

    public function test_every_string_is_translated_into_hindi_and_marathi(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $translations = [
                ...json_decode(File::get(lang_path($locale.'.json')), true),
                ...json_decode(File::get(lang_path('modules/site_alerts/'.$locale.'.json')), true),
            ];

            foreach ($this->moduleStrings() as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
            }
        }
    }

    public function test_both_languages_cover_the_same_strings(): void
    {
        $this->assertSame(
            array_keys(json_decode(File::get(lang_path('modules/site_alerts/hi.json')), true)),
            array_keys(json_decode(File::get(lang_path('modules/site_alerts/mr.json')), true)),
        );
    }

    public function test_placeholders_survive_translation(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            foreach (json_decode(File::get(lang_path('modules/site_alerts/'.$locale.'.json')), true) as $key => $value) {
                preg_match_all('/:[a-z]+/', $key, $expected);
                preg_match_all('/:[a-z]+/', $value, $actual);
                $expected = array_values(array_unique($expected[0]));
                $actual = array_values(array_unique($actual[0]));
                sort($expected);
                sort($actual);

                $this->assertSame($expected, $actual, "{$locale}: {$key}");
            }
        }
    }
}
