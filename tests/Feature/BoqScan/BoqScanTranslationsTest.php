<?php

namespace Tests\Feature\BoqScan;

use App\Services\BoqScan\BoqReadKind;
use App\Services\BoqScan\ScanFlag;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\Feature\Website\BannedWording;

/** Every line the scanned BOQ reader can show has Hindi and Marathi, and none of it says what must not be said. */
class BoqScanTranslationsTest extends BoqScanTestCase
{
    /** @return list<string> */
    private function moduleFiles(): array
    {
        return [
            ...array_map(fn ($file) => $file->getPathname(), File::allFiles(app_path('Services/BoqScan'))),
            ...array_map(fn ($file) => $file->getPathname(), File::allFiles(app_path('Http/Controllers/BoqScan'))),
            ...array_map(fn ($file) => $file->getPathname(), File::allFiles(resource_path('views/modules/boq_scan'))),
        ];
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
        return json_decode(File::get(lang_path("modules/boq_scan/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_string_is_translated_into_hindi_and_marathi_in_the_modules_own_files(): void
    {
        $strings = $this->moduleStrings();
        $this->assertGreaterThan(200, count($strings));

        foreach (['hi', 'mr'] as $locale) {
            $translations = $this->own($locale);

            foreach ($strings as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
                $this->assertNotSame('', trim((string) $translations[$key]), "Blank {$locale} translation: {$key}");
            }

            $this->assertSame([], array_values(array_diff(array_keys($translations), $strings)), "Unused {$locale} lines");
        }
    }

    public function test_both_languages_cover_the_same_strings_in_byte_order_and_keep_their_placeholders(): void
    {
        $hindi = $this->own('hi');
        $marathi = $this->own('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));
        $sorted = array_keys($hindi);
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, array_keys($hindi));

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
                if (basename(dirname($file)) !== 'boq_scan') {
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

    public function test_every_flag_has_words_of_its_own_and_they_come_in_the_persons_language(): void
    {
        $codes = array_filter((new ReflectionClass(ScanFlag::class))->getConstants(), fn ($value, $name) => is_string($value) && ! in_array($name, ['BLOCK', 'TICK'], true), ARRAY_FILTER_USE_BOTH);

        foreach ($codes as $code) {
            $this->assertNotSame($code, ScanFlag::message(ScanFlag::make($code, ScanFlag::BLOCK)), "{$code} has no words.");
        }

        $read = $this->scan();
        $this->stage($read, 1, [['1', 'Brickwork in CM 1:6', 'cum', '10.000', '100.00']], [['1', '1,500.00']]);

        $this->owner->update(['locale' => 'hi']);
        $hindi = $this->actingAs($this->owner)->getJson(route('boq-scans.rows', $read))->assertOk();
        $this->assertSame('मात्रा गुणा दर 1,000.00 है, लेकिन राशि 1,500.00 पढ़ी गई, यानी 500.00 का अंतर। मात्रा और दर वैसे ही टाइप करें जैसे छपे हैं।', collect($hindi->json('rows.0.flags'))->firstWhere('code', 'amount_mismatch')['message']);

        $this->owner->update(['locale' => 'mr']);
        $marathi = $this->actingAs($this->owner)->getJson(route('boq-scans.rows', $read))->assertOk();
        $this->assertSame('अजून पुष्टी झालेली नाही.', collect($marathi->json('rows.0.flags'))->firstWhere('code', 'unconfirmed_row')['message']);
    }

    public function test_wording_that_must_not_be_used_is_absent_and_the_reader_never_claims_more_than_it_does(): void
    {
        $files = [
            ...$this->moduleFiles(),
            lang_path('modules/boq_scan/hi.json'),
            lang_path('modules/boq_scan/mr.json'),
            config_path('modules/boq_scan.php'),
            base_path('routes/modules/boq_scan.php'),
            base_path('routes/api_modules/boq_scan.php'),
        ];

        foreach ($files as $file) {
            $this->assertSame([], BannedWording::found((string) file_get_contents($file)), 'Not to be said in '.str_replace(base_path().'/', '', $file));
        }

        foreach ($this->moduleStrings() as $line) {
            $this->assertDoesNotMatchRegularExpression('/\b(verified|guarantee|accurate|correct reading|found on the page|free credits?)\b/i', $line, $line);
        }

        // The instructions the reader gets are the same for every company and name none.
        $this->assertStringNotContainsString('Sahyadri', BoqReadKind::ROWS_INSTRUCTIONS.BoqReadKind::AMOUNTS_INSTRUCTIONS);
    }
}
