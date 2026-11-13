<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use App\Services\OwnerPack\OwnerUpdateDocument;
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
 * Everything the quotation and weekly-update screens, the shared pages, the cover note and the update
 * say has a Hindi and a Marathi line, the sentences of both documents are complete and state no
 * figure of their own, and none of it uses wording the product may not use.
 */
class TranslationsTest extends TestCase
{
    use BuildsOwnerUpdates, RefreshDatabase;

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
            app_path('Models/OwnerUpdate.php'),
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

        foreach ([...$this->documentTypes('hi'), ...$this->updateTypes('hi')] as $type) {
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

    /** @return list<DocumentType> The weekly update in every shape it can take, with and without the contractor's own text and the payment position. */
    private function updateTypes(string $language): array
    {
        $document = app(OwnerUpdateDocument::class);
        $types = [];

        foreach ($this->everySentenceSheets() as $sheet) {
            $types[] = $document->type($language, $sheet, withNextWeek: true);
            $types[] = $document->type($language, $sheet, withNextWeek: false, withMoney: false);
        }

        return $types;
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
            foreach ([...$this->documentTypes($language), ...$this->updateTypes($language)] as $type) {
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

    public function test_the_update_has_its_sentences_for_what_the_week_held_and_no_others(): void
    {
        $document = app(OwnerUpdateDocument::class);
        $ids = fn (string $sheet, bool $nextWeek = false, bool $money = true) => array_map(
            fn ($slot) => $slot->id,
            $document->type('en', $this->everySentenceSheets()[$sheet], $nextWeek, $money)->slots,
        );

        $this->assertSame(OwnerUpdate::LANGUAGES, ClientQuotation::LANGUAGES);
        $this->assertSame(['opening', 'period', 'reports', 'closing'], $ids('bare'));
        $this->assertSame(
            ['opening', 'period', 'reports', 'work', 'work_more', 'workers', 'progress', 'progress_basis', 'photos', 'pay_quotation', 'pay_stage_1', 'pay_stage_2', 'pay_reached', 'pay_received', 'pay_due', 'next_week_intro', 'next_week', 'closing'],
            $ids('full', true),
        );
        // Without the payment position, and without the contractor's own text.
        $this->assertSame(['opening', 'period', 'reports', 'work', 'work_more', 'workers', 'progress', 'progress_basis', 'photos', 'closing'], $ids('full', false, false));
        // Nothing is said about what is due unless a person typed what was received.
        $this->assertSame(['opening', 'period', 'reports', 'pay_quotation', 'pay_stage_1', 'pay_reached', 'closing'], $ids('nothing typed'));

        // The sentence about what is due follows the figures: due, received ahead of the stages, or neither.
        $sentence = fn (string $sheet, string $slot) => $document->render('en', $this->everySentenceSheets()[$sheet], null, null)->slot($slot)?->text;
        $this->assertSame('Due on the stages reached, after what was received: ₹50,000.00.', $sentence('full', 'pay_due'));
        $this->assertSame('What was received is more than the stages reached so far by ₹20,000.00.', $sentence('none reached', 'pay_due'));
        $this->assertSame('No payment stage has been reached yet.', $sentence('none reached', 'pay_reached'));
        $this->assertSame('Nothing is due on the stages reached so far.', $sentence('settled', 'pay_due'));
        $this->assertSame('No daily report of that week has been approved yet, so no work is listed here.', $sentence('bare', 'reports'));
        $this->assertSame('Work measured and approved so far: 25.88% of the value of the bill of quantities, as on 14 November 2026.', $sentence('full', 'progress'));

        // Only the opening and the closing may be rewritten; the contractor's own text is a slot of its own and is left out of what a model would be briefed on.
        $this->assertSame(['opening', 'closing'], OwnerUpdateDocument::FREE_SLOTS);
        $this->assertSame(['opening', 'closing'], $document->type('en', $this->everySentenceSheets()['full'])->freeIds());
        $this->assertSame(['opening', 'next_week', 'closing'], $document->type('en', $this->everySentenceSheets()['full'], true)->freeIds());

        foreach ($ids('full', true) as $id) {
            $this->assertSame(str_starts_with($id, 'pay_'), OwnerUpdateDocument::isMoneySlot($id), $id);
        }
    }

    public function test_every_figure_of_an_update_has_a_label_a_person_can_read_in_each_language(): void
    {
        $keys = [...$this->everySentenceSheets()['full']->keys(), 'pay_ahead', 'work_1', 'work_14', 'photo_1', 'photo_12'];

        foreach (['en', 'hi', 'mr'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $label = OwnerUpdateDocument::label($key);
                $this->assertNotSame($key, $label, "No label for {$key}.");
                $this->assertStringNotContainsString(':n', $label);

                if ($locale !== 'en') {
                    $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $label, "The {$locale} label of {$key} is not translated.");
                }
            }
        }

        app()->setLocale('en');
        $this->assertSame('Work row 14', OwnerUpdateDocument::label('work_14'));
        $this->assertSame('Photo 12', OwnerUpdateDocument::label('photo_12'));
        $this->assertSame('Stage reached 2: amount', OwnerUpdateDocument::label('pay_stage_2_amount'));
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
