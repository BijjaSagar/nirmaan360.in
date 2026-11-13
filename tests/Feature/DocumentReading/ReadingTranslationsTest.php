<?php

namespace Tests\Feature\DocumentReading;

use Illuminate\Support\Facades\File;
use Tests\Feature\Website\BannedWording;
use Tests\TestCase;

/** Every sentence the reader module shows a person has a Hindi and a Marathi line in the files it loads. */
class ReadingTranslationsTest extends TestCase
{
    /** @return array<string, string> */
    private function lines(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("modules/document_reading/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> Every text literal passed to __() in the module's own files, whichever of them exist yet. */
    private function usedTexts(): array
    {
        $folders = array_filter([
            app_path('Services/DocumentReading'),
            app_path('Http/Controllers/DocumentReading'),
            app_path('Jobs/DocumentReading'),
            resource_path('views/modules/document_reading'),
        ], is_dir(...));

        $files = collect($folders)->flatMap(fn (string $folder) => File::allFiles($folder))->map->getPathname()
            ->merge(array_filter([app_path('Http/Middleware/RequireAiReader.php'), app_path('Console/Commands/TendDocumentReads.php')], is_file(...)))
            // What the privacy policy and the terms say about the AI provider is the module's text too.
            ->merge([resource_path('views/public/privacy.blade.php'), resource_path('views/public/terms.blade.php')]);

        $texts = [];

        foreach ($files as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        return array_values(array_unique($texts));
    }

    public function test_every_text_has_a_hindi_and_a_marathi_line(): void
    {
        $this->assertGreaterThanOrEqual(30, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $lines = $this->lines($locale);
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => trim((string) ($lines[$text] ?? '')) === ''));

            $this->assertSame([], $missing, "Missing {$locale} lines");
        }
    }

    public function test_both_files_cover_the_same_texts_and_keep_their_placeholders(): void
    {
        $hindi = $this->lines('hi');
        $marathi = $this->lines('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        foreach ([$hindi, $marathi] as $lines) {
            foreach ($lines as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
                $this->assertNotSame($english, $translated);
            }
        }
    }

    /**
     * The legal pages are in English. What they say once a reader is offered goes through translation
     * whole; what they say before that stays English like the rest of the page, so a native reader's
     * check of these lines comes before any of them is shown in Hindi or Marathi.
     */
    public function test_the_legal_pages_put_every_sentence_about_readers_through_translation_whole(): void
    {
        foreach (['privacy', 'terms'] as $view) {
            $source = (string) file_get_contents(resource_path("views/public/{$view}.blade.php"));

            // A word spliced into a sentence cannot be put in Hindi or Marathi order.
            $this->assertDoesNotMatchRegularExpression("/\\?\\s*' (and|or) readers?'/", $source, $view);
            $this->assertStringNotContainsString('@if ($readers) Pages', $source, $view);
        }

        $texts = $this->usedTexts();

        foreach ([
            'AI agents and readers can be wrong.',
            'for the AI agents and readers, only when your company uses them.',
            'When you use an AI agent or reader, what it needs',
            'We do not use your records to train AI models.',
            'Pages, photos and document text sent to an AI reader',
            'The text of voice notes you spoke into the app',
        ] as $part) {
            $this->assertNotEmpty(array_filter($texts, fn (string $text) => str_contains($text, $part)), $part);
        }

        foreach ([
            'AI agents can be wrong.',
            'for the AI agents, only when your company uses them.',
            'When you use an AI agent, what it needs',
        ] as $part) {
            $this->assertSame([], array_values(array_filter($texts, fn (string $text) => str_contains($text, $part))), "Shown before a reader is offered, so it stays English: {$part}");

            foreach (['hi', 'mr'] as $locale) {
                $this->assertSame([], array_values(array_filter(array_keys($this->lines($locale)), fn (string $text) => str_contains($text, $part))), "No {$locale} line waits unused for: {$part}");
            }
        }
    }

    public function test_the_lines_are_loaded_with_the_other_module_files(): void
    {
        $this->assertSame('AI क्रेडिट कम बचे हैं', __('AI credits are running low', [], 'hi'));
        $this->assertSame('AI क्रेडिट कमी उरले आहेत', __('AI credits are running low', [], 'mr'));
        $this->assertSame('AI credits are running low', __('AI credits are running low', [], 'en'));
    }

    public function test_nothing_the_module_says_makes_a_promise_the_product_does_not_keep(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            foreach ($this->lines($locale) as $english => $translated) {
                $this->assertSame([], BannedWording::found($english), $english);
                $this->assertSame([], BannedWording::found($translated), $translated);
            }
        }

        // A budget that is used up is said in words, so no notice can ever read as a full percentage.
        foreach ([50, 80] as $percent) {
            $this->assertSame([], BannedWording::found(__(':percent% of today\'s AI budget for :feature is used', ['percent' => $percent, 'feature' => 'x'])));
        }
    }
}
