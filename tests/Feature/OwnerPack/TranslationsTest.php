<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientQuotation;
use App\Services\OwnerPack\QuotationDocument;
use App\Services\Wording\DocumentType;
use App\Services\Wording\FactSheet;
use App\Services\Wording\SlotGuard;
use App\Services\Wording\TemplateLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Website\BannedWording;
use Tests\TestCase;

/**
 * Everything the quotation screens, the shared page and the cover note say has a Hindi and a Marathi
 * line, the sentences of the cover note are complete and state no figure of their own, and none of
 * it uses wording the product may not use.
 */
class TranslationsTest extends TestCase
{
    use BuildsOwnerPack, RefreshDatabase;

    /** The amount in words is written in English only, so its sentence has no other language. */
    private const ENGLISH_ONLY = ['In words: {{words}}.'];

    /** @return list<\SplFileInfo|string> */
    private function moduleFiles(): array
    {
        return [
            ...File::allFiles(app_path('Services/OwnerPack')),
            ...File::allFiles(app_path('Http/Controllers/OwnerPack')),
            ...File::allFiles(resource_path('views/modules/owner_pack')),
            app_path('Models/ClientQuotation.php'),
        ];
    }

    /** @return list<string> Every text passed to __() or trans_choice(), the menu, and the sentences of the cover note. */
    private function moduleStrings(): array
    {
        $strings = collect($this->moduleFiles())->flatMap(function (\SplFileInfo|string $file) {
            preg_match_all('/(?:__|trans_choice)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', File::get((string) $file), $matches);

            return array_map(fn (string $text) => stripcslashes($text), $matches[2]);
        });

        $menu = collect(config('modules.owner_pack.nav'))->flatMap(fn (array $item) => [$item['label'], $item['group']]);

        return $strings->merge($menu)->merge($this->sentences())->unique()->values()->all();
    }

    /** @return list<string> The English of every sentence of the cover note that has words to translate. */
    private function sentences(): array
    {
        $sentences = [];

        foreach ($this->documentTypes('hi') as $type) {
            foreach ($type->slots as $slot) {
                if (preg_match('/\p{L}/u', (string) preg_replace('/\{\{[a-z0-9_]+\}\}/', '', $slot->english())) === 1) {
                    $sentences[] = $slot->english();
                }
            }
        }

        return array_values(array_unique($sentences));
    }

    /** @return array<string, FactSheet> A quotation with everything on it, and one with as little as a quotation can have. */
    private function sheets(): array
    {
        $this->house();

        $full = $this->draft();
        $this->stage($full, 'On agreement', '30', null, ['condition_text' => 'Within 7 days of the agreement']);
        $this->stage($full, 'Advance for steel', null, '20000');

        $bare = $this->draft(['gst_pct' => null, 'valid_until' => null]);

        return [
            'full' => FactSheet::fromArray($full->fresh()->facts),
            'bare' => FactSheet::fromArray($bare->facts),
        ];
    }

    /** @return list<DocumentType> */
    private function documentTypes(string $language): array
    {
        static $sheets;
        $sheets ??= $this->sheets();
        $document = app(QuotationDocument::class);

        return [
            $document->type($language, $sheets['full']),
            $document->type($language, $sheets['bare']),
            $document->type($language, $sheets['full'], withSchedule: false),
        ];
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

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode(File::get(lang_path("modules/owner_pack/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_string_has_a_hindi_and_a_marathi_line(): void
    {
        $strings = array_values(array_diff($this->moduleStrings(), self::ENGLISH_ONLY));
        $this->assertGreaterThan(150, count($strings), 'The scan found the views, controllers, services and sentences.');

        foreach (['hi', 'mr'] as $locale) {
            $lines = $this->lines($locale);
            $missing = array_values(array_filter($strings, fn (string $text) => trim((string) ($lines[$text] ?? '')) === ''));

            $this->assertSame([], $missing, "No {$locale} line for: ".implode(' | ', $missing));
        }
    }

    public function test_both_files_cover_the_same_strings_keep_their_placeholders_and_hold_nothing_unused(): void
    {
        $hindi = $this->own('hi');
        $marathi = $this->own('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));
        $this->assertSame([], array_values(array_diff(array_keys($hindi), $this->moduleStrings())), 'Lines nothing uses.');

        $sorted = array_keys($hindi);
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, array_keys($hindi), 'The lines are kept in order.');

        foreach ([$hindi, $marathi] as $lines) {
            foreach ($lines as $english => $translated) {
                $this->assertNotSame('', trim((string) $translated), "Empty line for: {$english}");

                // :name placeholders, and the {{tokens}} a figure is put into, are the same on both sides.
                foreach (['/(?<![:\w]):[a-z_]+/', '/\{\{[a-z0-9_]+\}\}/'] as $pattern) {
                    preg_match_all($pattern, $english, $expected);
                    preg_match_all($pattern, $translated, $actual);
                    $this->assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), "Placeholders differ in: {$english}");
                }

                $this->assertSame(substr_count($english, '|'), substr_count($translated, '|'), "Plural forms differ in: {$english}");
            }
        }
    }

    public function test_no_line_of_the_module_says_something_else_in_another_file(): void
    {
        // Language files are merged in load order. A line that reads differently elsewhere would quietly change
        // this module's words or another screen's, whichever file loads last. Today the module repeats no line at all.
        foreach (['hi', 'mr'] as $locale) {
            $own = $this->own($locale);
            $others = [lang_path($locale.'.json'), ...array_filter(glob(lang_path("modules/*/{$locale}.json")), fn (string $file) => basename(dirname($file)) !== 'owner_pack')];

            $this->assertGreaterThan(100, count($own));
            $this->assertGreaterThan(20, count($others), 'The other language files were found.');

            foreach ($others as $file) {
                foreach (array_intersect_key(json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR), $own) as $english => $theirs) {
                    $this->assertSame($theirs, $own[$english], "'{$english}' reads differently in ".str_replace(base_path().'/', '', $file));
                }
            }
        }
    }

    public function test_the_cover_note_has_every_sentence_in_all_three_languages_and_none_states_a_figure_of_its_own(): void
    {
        $loader = app(TemplateLoader::class);
        $guard = app(SlotGuard::class);

        foreach (ClientQuotation::LANGUAGES as $language) {
            foreach ($this->documentTypes($language) as $type) {
                // Throws when a sentence is missing, drops or adds a token, or carries a numeral, a month or a number word.
                $templates = $loader->load($type, $language);

                $this->assertSame(array_map(fn ($slot) => $slot->id, $type->slots), array_keys($templates));

                foreach ($type->freeSlots() as $slot) {
                    $this->assertSame([], $guard->check($templates[$slot->id], $language), "The '{$language}' text of '{$slot->id}' must pass every rule a model's text has to pass.");
                }
            }
        }

        // The amount in words is offered in English only.
        $document = app(QuotationDocument::class);
        $sheet = $this->sheets()['full'];
        $this->assertNotNull($document->type('en', $sheet)->slot('total_words'));
        $this->assertNull($document->type('hi', $sheet)->slot('total_words'));
        $this->assertNull($document->type('mr', $sheet)->slot('total_words'));
    }

    public function test_every_figure_has_a_label_a_person_can_read_in_each_language(): void
    {
        $sheets = $this->sheets();
        $keys = [...$sheets['full']->keys(), ...$sheets['bare']->keys(), 'line_1', 'line_27'];

        foreach (['en', 'hi', 'mr'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $label = QuotationDocument::label($key);
                $this->assertNotSame($key, $label, "No label for {$key}.");
                $this->assertStringNotContainsString(':n', $label);

                if ($locale !== 'en') {
                    $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $label, "The {$locale} label of {$key} is not translated.");
                }
            }
        }

        app()->setLocale('en');
        $this->assertSame('Line 27', QuotationDocument::label('line_27'));
        $this->assertSame('Stage 2: amount', QuotationDocument::label('stage_2_amount'));
    }

    public function test_nothing_the_module_says_uses_wording_the_product_may_not_use(): void
    {
        foreach ([...array_map('strval', $this->moduleFiles()), lang_path('modules/owner_pack/hi.json'), lang_path('modules/owner_pack/mr.json'), config_path('modules/owner_pack.php')] as $file) {
            $this->assertSame([], BannedWording::found(File::get($file)), 'Not to be said in '.str_replace(base_path().'/', '', $file));
        }

        // No sentence claims to know a tax rate: the percentage is always the one typed for the quotation.
        foreach ($this->moduleStrings() as $text) {
            $this->assertDoesNotMatchRegularExpression('/\b\d+(\.\d+)?\s?%/', $text, "A percentage is written into: {$text}");
        }
    }
}
