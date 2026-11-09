<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ScheduleService;
use App\Support\Modules;

class TranslationsAndMenuTest extends ReportsTestCase
{
    /** @return array<string,string> */
    private function strings(string $locale): array
    {
        return [
            ...json_decode((string) file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR),
            ...json_decode((string) file_get_contents(lang_path("modules/reports/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    /** Every text literal passed to __() in the module's own files, and the texts it passes by variable. */
    private function usedTexts(): array
    {
        $files = [
            ...glob(app_path('Services/Reports/*.php')),
            ...glob(app_path('Http/Controllers/Reports/*.php')),
            ...glob(app_path('Console/Commands/SendScheduledReports.php')),
            ...glob(resource_path('views/modules/reports/*.blade.php')),
            ...glob(resource_path('views/modules/reports/pdf/*.blade.php')),
            base_path('config/modules/reports.php'),
        ];
        $texts = [];

        foreach ($files as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }

            // Report column headings are passed to a helper that translates them.
            preg_match_all("/\\\$this->col\\('[a-z_]+',\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $columns);
            array_push($texts, ...$columns[1]);
        }

        array_push($texts, 'Material', 'Labour', 'Plant', 'Overhead', 'Profit', 'Draft', 'In Review', 'Returned', 'Approved', 'Rejected', 'Paid', 'Cancelled');

        foreach (ReportBuilder::DEFINITIONS as $definition) {
            array_push($texts, $definition['label'], $definition['note']);
        }

        array_push($texts, ...array_values(ReportPeriod::LABELS), ...array_values(ScheduleService::DAYS));

        foreach (Modules::nav('company', $this->org) as $item) {
            array_push($texts, $item['label'], $item['group']);
        }

        // The single letters L, B and D are the usual dimension marks and stay as they are.
        return array_values(array_unique(array_filter($texts, fn (string $text) => mb_strlen($text) > 1)));
    }

    public function test_every_text_in_the_module_has_a_hindi_and_a_marathi_translation(): void
    {
        $this->assertGreaterThan(200, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $strings = $this->strings($locale);
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => ! array_key_exists($text, $strings) || trim((string) $strings[$text]) === ''));

            $this->assertSame([], $missing, "Missing {$locale} translations");
        }
    }

    public function test_the_two_language_files_cover_the_same_texts_and_keep_their_placeholders(): void
    {
        $hindi = json_decode((string) file_get_contents(lang_path('modules/reports/hi.json')), true);
        $marathi = json_decode((string) file_get_contents(lang_path('modules/reports/mr.json')), true);

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        foreach ([$hindi, $marathi] as $translations) {
            foreach ($translations as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
            }
        }
    }

    public function test_the_screens_read_in_hindi_and_marathi(): void
    {
        $project = $this->makeProject($this->org);

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('reports.index'))->assertOk()->assertSee('रिपोर्ट चुनें')->assertSee('BOQ खाता')->assertSee('तय समय पर ईमेल');
        $this->actingAs($this->pm)->get(route('ratelib.index'))->assertOk()->assertSee('दर सूची');
        $this->actingAs($this->pm)->get(route('boq.excel.index', $project))->assertOk()->assertSee('Excel में BOQ');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('reports.index'))->assertOk()->assertSee('अहवाल निवडा')->assertSee('BOQ खाते');
        $this->actingAs($this->pm)->get(route('reports.schedules.index'))->assertOk()->assertSee('ठरलेल्या वेळी ईमेल');
    }

    public function test_the_menu_shows_the_module_in_its_own_group_for_those_with_access(): void
    {
        $this->actingAs($this->pm)->get(route('dashboard'))->assertOk()->assertSee('Reports &amp; rates', false)->assertSee('Rate library');

        // The estimator may use both, accounts only reads reports, an engineer sees neither.
        $this->actingAs($this->member($this->org, 'qs'))->get(route('dashboard'))->assertSee('Rate library')->assertSee(route('reports.index'), false);
        $this->actingAs($this->member($this->org, 'accounts'))->get(route('dashboard'))->assertSee(route('reports.index'), false)->assertDontSee(route('ratelib.index'), false);
        $this->actingAs($this->member($this->org, 'engineer'))->get(route('dashboard'))->assertDontSee('Reports &amp; rates', false);
    }

    public function test_the_module_is_on_every_paid_plan_and_not_on_the_free_one(): void
    {
        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $this->assertTrue($this->tenant("Co {$plan}", $plan)->allowsFeature('reports'), $plan);
        }

        $this->assertFalse($this->tenant('Free Co', 'free')->allowsFeature('reports'));
    }
}
