<?php

namespace Tests\Feature\TenderReading;

use App\Models\CrmTenderFact;
use App\Services\TenderReading\TenderFields;
use App\Services\TenderReading\TenderValue;
use Illuminate\Support\Facades\File;
use Tests\Feature\Website\BannedWording;

/** Every line of the tender reader has Hindi and Marathi, and none of it says what must not be said. */
class TranslationsTest extends TenderReadingTestCase
{
    /** @return list<string> */
    private function moduleFiles(): array
    {
        return array_map(fn (\SplFileInfo $file) => $file->getPathname(), [
            ...File::allFiles(app_path('Services/TenderReading')),
            ...File::allFiles(app_path('Http/Controllers/TenderReading')),
            ...File::allFiles(resource_path('views/modules/tender_reading')),
        ]);
    }

    /** @return list<string> */
    private function moduleStrings(): array
    {
        $strings = collect($this->moduleFiles())->flatMap(function (string $file) {
            $source = File::get($file);
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $single);
            preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $double);

            return [...array_map(fn (string $key) => str_replace("\\'", "'", $key), $single[1]), ...array_map(stripcslashes(...), $double[1])];
        });

        // The labels of the fields and the headings of the groups reach __() from the config.
        return $strings
            ->merge(array_map(fn (string $field) => TenderFields::label($field), TenderFields::keys()))
            ->merge(array_values(TenderFields::groups()))
            ->unique()->values()->all();
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode(File::get(lang_path("modules/tender_reading/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_line_is_translated_in_the_modules_own_files_and_nothing_unused_is_kept(): void
    {
        $strings = $this->moduleStrings();
        $this->assertGreaterThan(100, count($strings));

        foreach (['hi', 'mr'] as $locale) {
            $translations = $this->own($locale);

            foreach ($strings as $key) {
                $this->assertArrayHasKey($key, $translations, "Missing {$locale} translation: {$key}");
                $this->assertNotSame('', trim((string) $translations[$key]), "Blank {$locale} translation: {$key}");
            }

            $this->assertSame([], array_values(array_diff(array_keys($translations), $strings)), "Unused {$locale} lines");
        }
    }

    public function test_both_languages_hold_the_same_lines_in_order_with_their_placeholders(): void
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
                if (basename(dirname($file)) !== 'tender_reading') {
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

    public function test_the_sheet_and_the_reading_screen_are_shown_in_the_persons_language(): void
    {
        $read = $this->preparedRead();
        $kind = TenderFields::kind('emd_amount');
        CrmTenderFact::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id, 'tender_id' => $this->tender->id, 'field' => 'emd_amount',
            'value_raw' => 'Rs. 61,500/-', 'value_norm' => TenderValue::normalise($kind, 'Rs. 61,500/-'), 'value_kind' => $kind,
            'page' => 2, 'quote' => 'Earnest Money Deposit: Rs. 61,500/- by online payment', 'quote_found' => true, 'source' => 'text',
        ]);

        $this->owner->update(['locale' => 'hi']);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('टेंडर शीट')
            ->assertSee('यह शीट पढ़ने में सहायता के लिए है।')
            ->assertSee('पन्ना 2 पर PDF की टेक्स्ट परत से मेल खाता है।')
            ->assertSee('EMD राशि')
            ->assertDontSee('This sheet is a reading aid.');
        $this->actingAs($this->owner)->get(route('tender-reads.view', $read))->assertOk()
            ->assertSee('क्या बाईं ओर का टेक्स्ट दाईं ओर के पन्ने से मेल खाता है?')
            ->assertDontSee('Does the text on the left match');

        $this->owner->update(['locale' => 'mr']);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('ही शीट वाचनासाठी मदत आहे.')
            ->assertSee('पान 2 वरील PDF च्या मजकूर स्तराशी जुळते.')
            ->assertSee('EMD रक्कम');
        $this->actingAs($this->owner)->get(route('crm.tenders.show', $this->tender))->assertOk()
            ->assertSee('हा दस्तऐवज वाचा')
            ->assertSee('टेंडर शीट उघडा');
    }

    public function test_wording_that_must_not_be_used_is_absent(): void
    {
        $files = [
            ...$this->moduleFiles(),
            lang_path('modules/tender_reading/hi.json'),
            lang_path('modules/tender_reading/mr.json'),
            config_path('modules/tender_reading.php'),
            base_path('routes/modules/tender_reading.php'),
        ];

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);

            $this->assertSame([], BannedWording::found($text), 'Not to be said in '.str_replace(base_path().'/', '', $file));
            // Text matched against a PDF's text layer was never seen on the page itself.
            $this->assertDoesNotMatchRegularExpression('/(found|verified) on the page/i', $text, $file);
        }
    }
}
