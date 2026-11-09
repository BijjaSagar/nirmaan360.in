<?php

namespace Tests\Feature\FieldOps;

use Tests\TestCase;

/** Everything the module shows has a Hindi and a Marathi line, and nothing is left in English by accident. */
class TranslationsTest extends TestCase
{
    /** Short capital-letter terms read the same in every language. */
    private function mayStayAsIs(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9 \/.#&%-]*s?$/', $text) && strlen($text) <= 8;
    }

    public function test_every_text_has_hindi_and_marathi(): void
    {
        $strings = ModuleStrings::all();
        $this->assertGreaterThan(300, count($strings), 'The scan found the views, controllers and config.');

        $missing = ['hi' => [], 'mr' => []];
        $same = ['hi' => [], 'mr' => []];

        foreach (array_keys($strings) as $text) {
            foreach (['hi', 'mr'] as $locale) {
                $line = app('translator')->defaultLine($text, $locale);

                if ($line === $text && ! $this->mayStayAsIs($text)) {
                    $missing[$locale][] = $text;
                }
            }
        }

        $this->assertSame([], $missing['hi'], 'No Hindi line for: '.implode(' | ', $missing['hi']));
        $this->assertSame([], $missing['mr'], 'No Marathi line for: '.implode(' | ', $missing['mr']));
    }

    public function test_placeholders_survive_translation(): void
    {
        $broken = [];

        foreach (array_keys(ModuleStrings::all()) as $text) {
            preg_match_all('/:[a-z_]+/', $text, $expected);

            foreach (['hi', 'mr'] as $locale) {
                $line = app('translator')->defaultLine($text, $locale);

                foreach ($expected[0] as $placeholder) {
                    if (! str_contains($line, $placeholder)) {
                        $broken[] = "$locale: $text";
                    }
                }
            }
        }

        $this->assertSame([], $broken, 'A placeholder was lost in: '.implode(' | ', $broken));
    }

    public function test_the_module_language_files_are_valid_and_in_step(): void
    {
        foreach (['assets', 'qhs', 'field_ops'] as $part) {
            $hi = json_decode((string) file_get_contents(lang_path("modules/$part/hi.json")), true, flags: JSON_THROW_ON_ERROR);
            $mr = json_decode((string) file_get_contents(lang_path("modules/$part/mr.json")), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(array_keys($hi), array_keys($mr), "lang/modules/$part has the same keys in Hindi and Marathi");

            foreach ($hi + $mr as $key => $line) {
                $this->assertNotSame('', trim((string) $line), "$part: empty line for $key");
            }
        }
    }

    public function test_screens_show_the_chosen_language(): void
    {
        $this->assertSame('संपत्ति', __('Assets', [], 'hi'));
        $this->assertSame('मालमत्ता', __('Assets', [], 'mr'));
    }
}
