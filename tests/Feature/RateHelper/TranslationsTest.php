<?php

namespace Tests\Feature\RateHelper;

use App\Services\RateHelper\RateSources;
use App\Services\RateHelper\Sources\RateLibrarySource;
use App\Services\RateHelper\TextGuards;
use Illuminate\Support\Facades\File;
use Tests\Feature\Website\BannedWording;

/** Every line the rate helper can show has Hindi and Marathi, and none of it says what must not be said. */
class TranslationsTest extends RateHelperTestCase
{
    /** @return list<\SplFileInfo> */
    private function moduleFiles(): array
    {
        return [
            ...File::allFiles(app_path('Services/RateHelper')),
            ...File::allFiles(app_path('Http/Controllers/RateHelper')),
            ...File::allFiles(resource_path('views/modules/rate_helper')),
        ];
    }

    /** @return list<string> */
    private function moduleStrings(): array
    {
        // __('...') calls, and the two labels a figure carries, which are translated where they are shown.
        $strings = collect($this->moduleFiles())->flatMap(function (\SplFileInfo $file) {
            preg_match_all("/(?:__\(|\\b(?:dateLabel|basis):)\s*'((?:[^'\\\\]|\\\\.)*)'/", File::get($file->getPathname()), $matches);

            return array_map(fn (string $key) => str_replace("\\'", "'", $key), $matches[1]);
        });

        // Wording that reaches __() as a variable: the sources, the kinds of warning and the parts of a rate analysis.
        return $strings
            ->merge(array_map(fn ($source) => $source->label(), app(RateSources::class)->open()))
            ->merge(TextGuards::KINDS)
            ->merge(RateLibrarySource::PARTS)
            ->unique()->values()->all();
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode(File::get(lang_path("modules/rate_helper/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_string_is_translated_into_hindi_and_marathi_in_the_modules_own_files(): void
    {
        $strings = $this->moduleStrings();
        $this->assertGreaterThan(70, count($strings));

        foreach (['hi', 'mr'] as $locale) {
            $translations = $this->own($locale);

            foreach ($strings as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
                $this->assertNotSame('', trim((string) $translations[$key]), "Blank {$locale} translation: {$key}");
            }

            // And nothing is kept that the module no longer says.
            $this->assertSame([], array_values(array_diff(array_keys($translations), $strings)), "Unused {$locale} lines");
        }
    }

    public function test_both_languages_cover_the_same_strings_in_the_same_order_and_keep_their_placeholders(): void
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

                // Apart from the one line that is only placeholders, a translation is in Devanagari.
                if (preg_replace('/:[a-z]+|[^a-z]/i', '', $english) !== '') {
                    $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $translated, $english);
                }
            }
        }
    }

    public function test_a_line_shared_with_another_screen_is_worded_the_same_there(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $elsewhere = json_decode(File::get(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);

            foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
                if (basename(dirname($file)) !== 'rate_helper') {
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

    public function test_the_screens_are_shown_in_the_persons_language(): void
    {
        $line = $this->line('RCC M25 in slab 150 mm thick');
        $this->libraryRate('RCC M20 in slab 150 mm thick', 'cum', '5800.00');
        $run = $this->startRun($this->pm, $line);

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('सुझाए गए दर')
            ->assertSee('सरकारी दर अनुसूची: कुछ भी लोड नहीं है।')
            ->assertSee('कंक्रीट ग्रेड')
            ->assertSee('यह लाइन: M25 · वह दर: M20')
            ->assertSee('मैंने ग्रेड, मिश्रण और मोटाई जाँच ली है')
            ->assertSee('5,800.00')
            ->assertDontSee('Government schedule')->assertDontSee('Accept this rate')->assertDontSee('From your own records');
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()->assertSee('दर सुझाएँ')->assertDontSee('Suggest rates');
        $this->accept($this->pm, $run, $line, 'C1')->assertSessionHasErrors(['checked_warnings' => 'यह दर स्वीकार करने से पहले "मैंने ग्रेड, मिश्रण और मोटाई जाँच ली है" पर निशान लगाएँ।']);

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('सुचवलेले दर')
            ->assertSee('शासकीय दरसूची: काहीही लोड केलेले नाही.')
            ->assertSee('काँक्रीट ग्रेड')
            ->assertSee('हा दर स्वीकारा')
            ->assertDontSee('Government schedule')->assertDontSee('Accept this rate');
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()->assertSee('दर सुचवा')->assertDontSee('Suggest rates');
    }

    public function test_wording_that_must_not_be_used_is_absent(): void
    {
        $files = [
            ...array_map(fn (\SplFileInfo $file) => $file->getPathname(), $this->moduleFiles()),
            lang_path('modules/rate_helper/hi.json'),
            lang_path('modules/rate_helper/mr.json'),
            config_path('modules/rate_helper.php'),
            base_path('routes/modules/rate_helper.php'),
        ];

        $this->assertGreaterThan(15, count($files));

        foreach ($files as $file) {
            $this->assertSame([], BannedWording::found((string) file_get_contents($file)), 'Not to be said in '.str_replace(base_path().'/', '', $file));
        }

        foreach ($this->moduleStrings() as $line) {
            // The helper never says a rate is right, approved or current in the market: only where it was read from.
            $this->assertDoesNotMatchRegularExpression('/\b(market rate|correct rate|recommended|best rate|approved rate|live rate)\b/i', $line, $line);
        }
    }
}
