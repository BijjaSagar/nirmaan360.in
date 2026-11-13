<?php

namespace Tests\Feature\SiteDiary;

use App\Http\Middleware\RequireAiReader;
use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\SiteLetter;
use App\Services\Access\PermissionCatalog;
use App\Services\SiteDiary\LetterLayout;
use App\Services\SiteDiary\NoticeDeadlineSource;
use App\Services\Website\AiOffer;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Website\BannedWording;

/**
 * What the module says, in three languages, and what it must never say: a notice period, a number
 * of days or a clause of its own, or any of the wording the product does not use.
 */
class SiteDiaryWordingTest extends SiteDiaryTestCase
{
    /** @return list<string> The module's own source: code, lists, screens, routes and migration. */
    private function sourceFiles(): array
    {
        return [
            ...glob(app_path('Services/SiteDiary/*.php')),
            ...glob(app_path('Http/Controllers/SiteDiary/*.php')),
            ...glob(resource_path('views/modules/site_diary/*.blade.php')),
            app_path('Models/Hindrance.php'),
            app_path('Models/HindranceSource.php'),
            app_path('Models/HindranceSuggestion.php'),
            app_path('Models/SiteLetter.php'),
            app_path('Models/SiteLetterHindrance.php'),
            config_path('modules/site_diary.php'),
            base_path('routes/modules/site_diary.php'),
            database_path('migrations/2027_01_16_100001_create_hindrance_tables.php'),
            database_path('migrations/2027_01_16_100002_create_site_letter_tables.php'),
            database_path('migrations/2027_01_16_100003_create_hindrance_suggestions_table.php'),
        ];
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("modules/site_diary/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<string>> Text => every translation of it in the files of the other modules and the core file. */
    private function elsewhere(string $locale): array
    {
        $lines = [];

        foreach ([lang_path($locale.'.json'), ...glob(lang_path("modules/*/{$locale}.json"))] as $file) {
            if (str_contains($file, 'modules/site_diary/')) {
                continue;
            }

            foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $english => $translated) {
                $lines[$english][] = $translated;
            }
        }

        return $lines;
    }

    /** @return list<string> Every text the module shows: the literals passed to __() or trans_choice(), the names in its lists and the reminders. */
    private function usedTexts(): array
    {
        $texts = [];

        foreach ($this->sourceFiles() as $file) {
            preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        $config = config('modules.site_diary');
        array_push($texts, ...array_values($config['categories']), ...array_values($config['statuses']), ...array_values($config['letter_types']), ...array_values($config['letter_statuses']));

        foreach ($config['nav'] as $item) {
            $texts[] = $item['label'];
        }

        // A reminder's title and message are kept in English and translated when shown.
        array_push($texts, ...NoticeDeadlineSource::wording());

        // Every sentence a letter is put together from, and the label of every figure in it.
        array_push($texts, ...LetterLayout::wording());

        // The custom role screen names the module and its three actions from the permission strings.
        array_push($texts, 'Site Diary', 'View', 'Manage', 'Letters');

        return array_values(array_unique($texts));
    }

    public function test_every_text_has_a_hindi_and_a_marathi_line(): void
    {
        $this->assertGreaterThan(250, count($this->usedTexts()));

        foreach (['hi', 'mr'] as $locale) {
            $lines = [...array_map(fn (array $found) => $found[0], $this->elsewhere($locale)), ...$this->own($locale)];
            $missing = array_values(array_filter($this->usedTexts(), fn (string $text) => trim((string) ($lines[$text] ?? '')) === ''));

            $this->assertSame([], $missing, "Missing {$locale} lines");
        }
    }

    public function test_both_files_cover_the_same_texts_keep_their_placeholders_and_hold_nothing_unused(): void
    {
        $hindi = $this->own('hi');
        $marathi = $this->own('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));
        $this->assertSame([], array_values(array_diff(array_keys($hindi), $this->usedTexts())), 'Lines no screen uses');

        foreach ([$hindi, $marathi] as $lines) {
            foreach ($lines as $english => $translated) {
                preg_match_all('/:[a-z]+/', $english, $expected);
                preg_match_all('/:[a-z]+/', $translated, $actual);

                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "Placeholders differ in: {$english}");
                $this->assertNotSame($english, $translated);
                $this->assertSame(1, preg_match('/\p{Devanagari}/u', $translated), "Not translated: {$english}");
                // Figures are placed by the app, so no line carries one of its own.
                $this->assertSame(0, preg_match('/[\d०-९]/u', $translated), "A figure is written in: {$english}");
            }
        }
    }

    public function test_the_module_gives_no_text_of_another_module_a_second_meaning(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $this->assertSame([], array_keys(array_intersect_key($this->own($locale), $this->elsewhere($locale))), "{$locale}: already translated elsewhere, so it is not repeated here.");
        }
    }

    public function test_the_code_holds_no_notice_period_no_number_of_days_and_no_clause_of_its_own(): void
    {
        $files = [...$this->sourceFiles(), ...glob(lang_path('modules/site_diary/*.json'))];
        $this->assertGreaterThanOrEqual(36, count($files));

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = str_replace(base_path().'/', '', $file);

            $this->assertSame(0, preg_match('/[\d०-९]\s?(%|percent|per cent|प्रतिशत|टक्के)/u', $source), "A percentage is written in {$name}.");
            $this->assertSame(0, preg_match('/\b(?!1 day\b)\d+\s+(days?|weeks?|months?)\b/i', $source), "A period is written in {$name}.");
            $this->assertSame(0, preg_match('/[\d०-९]+\s*(दिन|दिवस|हफ़्ते|आठवडे|महीने|महिने)/u', $source), "A period is written in {$name}.");
            $this->assertSame(0, preg_match('/\b(clause|section|sub-clause|article|rule)\s+(no\.?\s*)?\d/i', $source), "A clause is named in {$name}.");
            $this->assertSame(0, preg_match('/(खंड|कलम|धारा)\s*[\d०-९]/u', $source), "A clause is named in {$name}.");
            $this->assertSame(0, preg_match('/\b(19\d[A-Z]{0,2}|CPWD|PWD|MoRTH|FIDIC|GCC)\b/', $source), "A law, department or form of contract is named in {$name}.");

            // The migration shows no text to anyone, and a table rightly has a unique key.
            if (! str_starts_with($name, 'database/')) {
                $this->assertSame([], BannedWording::found($source), "Not to be said in {$name}.");
            }
        }

        // The check does find what it looks for. These lines are made up for the test.
        $this->assertSame(1, preg_match('/\b(?!1 day\b)\d+\s+(days?|weeks?|months?)\b/i', 'within 17 days of the event'));
        $this->assertSame(1, preg_match('/[\d०-९]+\s*(दिन|दिवस|हफ़्ते|आठवडे|महीने|महिने)/u', '१७ दिवसांच्या आत'));
        $this->assertSame(1, preg_match('/\b(clause|section|sub-clause|article|rule)\s+(no\.?\s*)?\d/i', 'as per Clause 999'));
    }

    public function test_the_only_numbers_the_module_holds_are_its_own_reminder_and_list_settings(): void
    {
        $config = config('modules.site_diary');

        // When the app reminds, and how many reports it lists. Neither is a period from an agreement, and no date shown depends on them.
        $numbers = array_filter($config, 'is_int');
        $this->assertSame(['remind_days_before', 'remind_days_after', 'candidates_shown'], array_keys($numbers));

        // How much one request to the AI to group notes may carry. Limits on a request, not a period of any kind.
        $this->assertSame(['most_reports', 'note_chars', 'title_chars'], array_keys($config['grouping']));
        $this->assertContainsOnly('int', $config['grouping']);

        // Nothing else in the module's code states a number of days: a date is always a term's own days added by
        // HindranceMath. Beyond 0 and 1, the numbers written in the code are the shape of a date (4 and 2 digits, 10
        // characters), the length of a fingerprint, the fallback for the length of the list, and the width of the
        // column that keeps a letter's subject line.
        $others = ['HindranceMath.php' => ['2', '4'], 'HindranceRegister.php' => ['10', '200'], 'Hindrances.php' => ['16'], 'SiteLetters.php' => ['255']];

        foreach (glob(app_path('Services/SiteDiary/*.php')) as $file) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
            preg_match_all('/(?<![\w.$\'>-])\d+(?![\w\'.-])/', $code, $found);

            $this->assertSame([], array_values(array_diff($found[0], ['0', '1', ...($others[basename($file)] ?? [])])), basename($file));
        }

        // The check does find a number of days written into code. These lines are made up for the test.
        foreach (['$date->addDays(14);', '$days = 28;', "['days' => 7]"] as $line) {
            $this->assertSame(1, preg_match('/(?<![\w.$\'>-])\d+(?![\w\'.-])/', $line), $line);
        }

        foreach ($config['categories'] as $key => $name) {
            $this->assertSame(0, preg_match('/\d/', $name), "{$key} carries a figure.");
            $this->assertLessThanOrEqual(40, strlen($key), $key);
        }

        $this->assertSame(Hindrance::STATUSES, array_keys($config['statuses']));

        foreach ([...Hindrance::STATUSES, ...SiteLetter::STATUSES] as $status) {
            $this->assertLessThanOrEqual(12, strlen($status));
        }

        // The kinds of letter and their two states are named in the list the screens read, and fit their columns.
        $this->assertSame(SiteLetter::TYPES, array_keys($config['letter_types']));
        $this->assertSame(SiteLetter::STATUSES, array_keys($config['letter_statuses']));

        foreach (SiteLetter::TYPES as $type) {
            $this->assertLessThanOrEqual(24, strlen($type));
        }

        foreach (Hindrance::PROPOSERS as $proposer) {
            $this->assertLessThanOrEqual(8, strlen($proposer));
        }
    }

    public function test_the_screen_reads_in_hindi_and_marathi(): void
    {
        $report = $this->dpr('2026-03-12', 'Podium slab drawings not received');
        $this->cite($this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]), $report);
        $this->notice();

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('site-diary.hindrances.index', $this->project))->assertOk()
            ->assertSee('रुकावट रजिस्टर')->assertSee('प्रविष्टि बनाएँ')->assertSee('ड्रॉइंग या निर्णय का इंतज़ार')
            ->assertSee('12 Mar 2026 से, अभी जारी')->assertSee('आज तक 9 दिन')
            ->assertSee('1 का हवाला, 0 मंज़ूर, 1 अभी बदली जा सकती हैं')
            ->assertSee('देरी की सूचना')->assertSee('23 Mar 2026')->assertSee('3 दिन में')
            ->assertSee('गिनती यहाँ से: जिस दिन रुकावट शुरू हुई')
            ->assertSee('12 Mar 2026 से गिनने पर 23 Mar 2026 आती है।')
            ->assertSee('उसने आपका अनुबंध नहीं पढ़ा।')
            ->assertSee('ऐप अपनी ओर से कोई सूचना अवधि नहीं जानता।')
            ->assertSee('Podium slab drawings not received')
            ->assertDontSee('Make an entry')->assertDontSee('Still going on')->assertDontSee('Notice dates')->assertDontSee('Counted from');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('site-diary.hindrances.index', $this->project))->assertOk()
            ->assertSee('अडथळा नोंदवही')->assertSee('नोंद करा')->assertSee('ड्रॉइंग किंवा निर्णयांची प्रतीक्षा')
            ->assertSee('12 Mar 2026 पासून, अजून सुरू')->assertSee('आजपर्यंत 9 दिवस')
            ->assertSee('विलंबाची सूचना')->assertSee('23 Mar 2026')
            ->assertSee('मोजणी इथून: अडथळा सुरू झाला तो दिवस')
            ->assertSee('12 Mar 2026 पासून मोजल्यास 23 Mar 2026 येते.')
            ->assertSee('त्याने तुमचा करारनामा वाचलेला नाही.')
            ->assertSee('ॲपला स्वतःचा कोणताही सूचना कालावधी माहीत नाही.')
            ->assertDontSee('Make an entry')->assertDontSee('Still going on')->assertDontSee('Notice dates')->assertDontSee('Counted from');

        // Messages after a save follow the reader's language too, and so does a refusal.
        $this->actingAs($this->pm)->post(route('site-diary.hindrances.store', $this->project), $this->form([$report]))
            ->assertSessionHas('success', 'नोंद नोंदवहीत जतन झाली.');
        $this->post(route('site-diary.hindrances.store', $this->project), $this->form([$report], ['started_on' => '2026-03-15']))
            ->assertSessionHasErrors(['ended_on' => 'निवडलेल्या शेवटच्या अहवालाची तारीख सुरुवातीच्या तारखेच्या आधीची आहे. अडथळा संपला तो दिवस टाइप करा, किंवा तो अजून सुरू आहे अशी खूण करा.']);

        // With no notice period entered, the page says so in the reader's language.
        ContractTerm::query()->delete();
        $this->get(route('site-diary.hindrances.index', $this->project))->assertOk()
            ->assertSee('या करारासाठी कोणताही सूचना कालावधी नोंदवलेला नाही. अंतिम तारखा पाहण्यासाठी तो कराराच्या अटी मध्ये नोंदवा.');
    }

    public function test_the_roles_get_the_permissions_they_should_and_a_custom_role_can_be_given_them(): void
    {
        $this->assertTrue($this->pm->can('site_diary.view'));
        $this->assertTrue($this->pm->can('site_diary.manage'));
        $this->assertTrue($this->pm->can('site_diary.letters'), 'The project manager holds the whole module, letters included.');

        $this->assertTrue($this->engineer->can('site_diary.view'));
        $this->assertTrue($this->engineer->can('site_diary.manage'));
        $this->assertFalse($this->engineer->can('site_diary.letters'));

        $this->assertTrue($this->qs->can('site_diary.view'));
        $this->assertFalse($this->qs->can('site_diary.manage'));

        $this->assertFalse($this->qs->can('site_diary.letters'));

        foreach (['accounts', 'store', 'viewer'] as $role) {
            $user = $this->member($this->org, $role);
            $this->assertFalse($user->can('site_diary.view'), $role);
            $this->assertFalse($user->can('site_diary.manage'), $role);
            $this->assertFalse($user->can('site_diary.letters'), $role);
        }

        $catalog = app(PermissionCatalog::class);
        $this->assertContains('site_diary.view', $catalog->all());
        $this->assertContains('site_diary.manage', $catalog->all());
        $this->assertContains('site_diary.letters', $catalog->all(), 'A custom role can be given letters by themselves.');

        app()->setLocale('mr');
        $this->assertSame('साइट डायरी', $catalog->moduleLabel('site_diary'));
        $this->assertSame('पत्रे', $catalog->actionLabel('site_diary.letters'));

        // The module rides on site control and adds no plan feature of its own.
        $this->assertSame('site_control', config('modules.site_diary.feature'));
        $this->assertSame(config('modules.site_control.plans'), config('modules.site_diary.plans'));
    }

    public function test_every_route_of_the_module_is_guarded_and_the_register_opens_the_website_card(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with((string) $route->getName(), 'site-diary.'));

        $this->assertSame(
            [
                'site-diary.hindrances.confirm', 'site-diary.hindrances.dismiss', 'site-diary.hindrances.index', 'site-diary.hindrances.store', 'site-diary.hindrances.suggest', 'site-diary.hindrances.update',
                'site-diary.letters.draft', 'site-diary.letters.finalise', 'site-diary.letters.index', 'site-diary.letters.show', 'site-diary.letters.store', 'site-diary.letters.update',
            ],
            $routes->map->getName()->sort()->values()->all(),
        );

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $name = $route->getName();

            $this->assertContains('auth', $middleware, $name);
            $this->assertContains('feature:site_control', $middleware, $name);
            $this->assertContains(match (true) {
                str_starts_with($name, 'site-diary.letters.') => 'can:site_diary.letters',
                $name === 'site-diary.hindrances.index' => 'can:site_diary.view',
                default => 'can:site_diary.manage',
            }, $middleware, $name);
            $this->assertSame(in_array($name, ['site-diary.hindrances.store', 'site-diary.hindrances.update', 'site-diary.hindrances.suggest'], true), in_array('can:dpr.view', $middleware, true), $name);
            $aiGuards = array_values(array_filter($middleware, fn ($entry) => is_string($entry) && (str_contains($entry, 'ai_agents') || str_contains($entry, 'RequireAiReader') || str_contains($entry, 'agents.use') || str_starts_with($entry, 'throttle:'))));

            // Two routes call an AI model. Grouping sends what staff wrote in daily reports, so it waits for the consent switch;
            // a letter's sentences are drafted from what each is for and the names of its figures. Each has the counter of text calls.
            $this->assertSame(match ($name) {
                'site-diary.hindrances.suggest' => ['feature:ai_agents', 'can:agents.use', RequireAiReader::class.':consent', 'throttle:20,1,ai-text'],
                'site-diary.letters.draft' => ['feature:ai_agents', 'can:agents.use', RequireAiReader::class.':text', 'throttle:20,1,ai-text'],
                default => [],
            }, $aiGuards, $name);
            $this->assertSame(in_array($name, ['site-diary.hindrances.suggest', 'site-diary.letters.draft'], true), $route->methods() === ['POST'] && $aiGuards !== [], $name);
        }

        // The public AI page shows "Hindrance register and letters" while the register's route exists, by its real name.
        $this->assertContains(['flag', 'Hindrance register and letters', 'Keeps a dated register from your daily reports and drafts extension, delay and payment letters with every date and figure taken from your records.'], app(AiOffer::class)->helpers());
    }
}
