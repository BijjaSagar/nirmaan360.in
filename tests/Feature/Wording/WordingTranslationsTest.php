<?php

namespace Tests\Feature\Wording;

/** Every text the wording service shows a person has a Hindi and a Marathi line in the files it loads. */
class WordingTranslationsTest extends WordingTestCase
{
    /** @return array<string, string> */
    private function lines(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("modules/wording/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> Every text literal passed to __() in the service's own files. */
    private function usedTexts(): array
    {
        $texts = [];

        foreach (glob(app_path('Services/Wording/*.php')) as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        return array_values(array_unique($texts));
    }

    public function test_every_text_has_a_hindi_and_a_marathi_line(): void
    {
        $this->assertGreaterThanOrEqual(3, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $lines = $this->lines($locale);
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => trim((string) ($lines[$text] ?? '')) === ''));

            $this->assertSame([], $missing, "Missing {$locale} lines");
        }
    }

    public function test_both_files_cover_the_same_texts_keep_their_placeholders_and_hold_nothing_unused(): void
    {
        $hindi = $this->lines('hi');
        $marathi = $this->lines('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));
        $this->assertEqualsCanonicalizing($this->usedTexts(), array_keys($hindi));

        foreach ([$hindi, $marathi] as $lines) {
            foreach ($lines as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
                $this->assertNotSame($english, $translated);
            }
        }
    }

    public function test_the_lines_are_loaded_with_the_other_module_files(): void
    {
        $this->assertSame('आपने टाइप किया है, रिकॉर्ड से नहीं लिया गया', __('typed by you, not from the records', [], 'hi'));
        $this->assertSame('तुम्ही टाइप केले आहे, नोंदींमधून घेतलेले नाही', __('typed by you, not from the records', [], 'mr'));
        $this->assertSame('typed by you, not from the records', __('typed by you, not from the records', [], 'en'));
    }

    public function test_the_word_lists_are_complete_for_all_three_languages(): void
    {
        foreach (['months', 'month_names', 'weekday_names', 'percent_words', 'figure_words', 'number_words', 'quantity_words'] as $list) {
            $this->assertSame(['en', 'hi', 'mr'], array_keys(config("wording.{$list}")), $list);

            foreach (config("wording.{$list}") as $language => $entries) {
                $this->assertNotEmpty($entries, "{$list}.{$language}");
                $this->assertSame(array_values(array_unique($entries)), array_values($entries), "{$list}.{$language} repeats an entry");
            }
        }

        $this->assertNotEmpty(config('wording.currency_words'));
        $this->assertGreaterThan(0, config('wording.free_slot_max_chars'));

        // Every month name that is refused only with a capital letter is one the product prints in English.
        foreach (config('wording.month_names_capital_only') as $name) {
            $this->assertTrue(in_array($name, config('wording.months.en'), true) || in_array($name, ['Mar'], true), $name);
        }
    }
}
