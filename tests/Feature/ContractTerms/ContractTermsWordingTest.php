<?php

namespace Tests\Feature\ContractTerms;

use App\Models\ContractTerm;
use App\Services\Access\PermissionCatalog;
use Illuminate\Support\Arr;
use Tests\Feature\Website\BannedWording;

/**
 * What the module says, in three languages, and what it must never say: a percentage, a number of
 * days or a clause of its own, or any of the wording the product does not use.
 */
class ContractTermsWordingTest extends ContractTermsTestCase
{
    /** @return list<string> The module's own source: code, lists, screens, routes and migration. */
    private function sourceFiles(): array
    {
        return [
            ...glob(app_path('Services/ContractTerms/*.php')),
            ...glob(app_path('Http/Controllers/ContractTerms/*.php')),
            ...glob(resource_path('views/modules/contract_terms/*.blade.php')),
            app_path('Models/ContractTerm.php'),
            config_path('modules/contract_terms.php'),
            base_path('routes/modules/contract_terms.php'),
            database_path('migrations/2027_01_14_100001_create_contract_terms_table.php'),
        ];
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("modules/contract_terms/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<string>> Text => every translation of it in the files of the other modules and the core file. */
    private function elsewhere(string $locale): array
    {
        $lines = [];

        foreach ([lang_path($locale.'.json'), ...glob(lang_path("modules/*/{$locale}.json"))] as $file) {
            if (str_contains($file, 'modules/contract_terms/')) {
                continue;
            }

            foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $english => $translated) {
                $lines[$english][] = $translated;
            }
        }

        return $lines;
    }

    /** @return list<string> Every text the module shows: the literals passed to __() and the names in its lists. */
    private function usedTexts(): array
    {
        $texts = [];

        foreach ($this->sourceFiles() as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        $config = config('modules.contract_terms');

        foreach (['kinds', 'codes', 'methods', 'bases', 'natures', 'counted_from', 'combine'] as $list) {
            array_push($texts, ...Arr::flatten($config[$list]));
        }

        foreach ($config['nav'] as $item) {
            array_push($texts, $item['label'], $item['group']);
        }

        // The custom role screen names the module and its two actions from the permission strings.
        array_push($texts, 'Contract Terms', 'View', 'Manage');

        return array_values(array_unique($texts));
    }

    public function test_every_text_has_a_hindi_and_a_marathi_line(): void
    {
        $this->assertGreaterThan(100, count($this->usedTexts()));

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
            }
        }
    }

    public function test_the_module_gives_no_text_of_another_module_a_second_meaning(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $this->assertSame([], array_keys(array_intersect_key($this->own($locale), $this->elsewhere($locale))), "{$locale}: already translated elsewhere, so it is not repeated here.");
        }
    }

    public function test_the_code_holds_no_percentage_no_number_of_days_and_no_clause_of_its_own(): void
    {
        $files = [...$this->sourceFiles(), ...glob(lang_path('modules/contract_terms/*.json'))];
        $this->assertGreaterThanOrEqual(10, count($files));

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = str_replace(base_path().'/', '', $file);

            $this->assertSame(0, preg_match('/[\d०-९]\s?(%|percent|per cent|प्रतिशत|टक्के)/u', $source), "A percentage is written in {$name}.");
            $this->assertSame(0, preg_match('/\b(?!1 day\b)\d+\s+(days?|weeks?|months?)\b/i', $source), "A period is written in {$name}.");
            $this->assertSame(0, preg_match('/\b(clause|section|sub-clause|article|rule)\s+(no\.?\s*)?\d/i', $source), "A clause is named in {$name}.");
            $this->assertSame(0, preg_match('/(खंड|कलम|धारा)\s*[\d०-९]/u', $source), "A clause is named in {$name}.");
            $this->assertSame(0, preg_match('/\b(19\d[A-Z]{0,2}|CPWD|PWD|MoRTH|FIDIC|GCC)\b/', $source), "A law, department or form of contract is named in {$name}.");

            // The migration shows no text to anyone, and a table rightly has a unique key.
            if (! str_starts_with($name, 'database/')) {
                $this->assertSame([], BannedWording::found($source), "Not to be said in {$name}.");
            }
        }

        // The check does find what it looks for. These three lines are made up for the test.
        $this->assertSame(1, preg_match('/[\d०-९]\s?(%|percent|per cent|प्रतिशत|टक्के)/u', 'A head is 3% of the bill'));
        $this->assertSame(1, preg_match('/\b(?!1 day\b)\d+\s+(days?|weeks?|months?)\b/i', 'within 17 days of the event'));
        $this->assertSame(1, preg_match('/\b(clause|section|sub-clause|article|rule)\s+(no\.?\s*)?\d/i', 'as per Clause 999'));
    }

    public function test_the_lists_offer_names_only_and_every_choice_is_one_the_table_can_hold(): void
    {
        $config = config('modules.contract_terms');

        $this->assertSame(ContractTerm::KINDS, array_keys($config['kinds']));
        $this->assertSame(ContractTerm::KINDS, array_keys($config['codes']));
        $this->assertSame(ContractTerm::METHODS, array_keys($config['methods']));
        $this->assertSame(ContractTerm::BASES, array_keys($config['bases']));
        $this->assertSame(ContractTerm::NATURES, array_keys($config['natures']));
        $this->assertSame(ContractTerm::COMBINE, array_keys($config['combine']));
        $this->assertSame(['notice', 'payment_period'], array_keys($config['counted_from']));

        $codes = array_merge(...array_map('array_keys', array_values($config['codes'])));
        $this->assertSame($codes, array_values(array_unique($codes)), 'A code belongs to one kind.');
        $this->assertSame([], array_diff([...array_keys(ContractTerm::BILL_PERCENTAGES), ...ContractTerm::ONE_AT_A_TIME], $codes));
        $this->assertSame(array_keys(ContractTerm::BILL_PERCENTAGES), array_slice(array_keys($config['codes']['deduction']), 0, 4));

        // The keys the bill saves its own percentages under, so a later check can compare like with like.
        $this->assertSame([], array_diff(array_values(ContractTerm::BILL_PERCENTAGES), array_keys($this->project->billingTerms())));

        foreach (Arr::dot(Arr::only($config, ['kinds', 'codes', 'methods', 'bases', 'natures', 'counted_from', 'combine'])) as $key => $name) {
            $this->assertIsString($name, $key);
            $this->assertSame(0, preg_match('/\d/', $name), "{$key} carries a figure.");
            $this->assertLessThanOrEqual(40, strlen(Arr::last(explode('.', $key))), $key);
        }

        foreach ([...array_keys($config['counted_from']['notice']), ...array_keys($config['counted_from']['payment_period'])] as $start) {
            $this->assertLessThanOrEqual(24, strlen($start), $start);
        }
    }

    public function test_the_screen_reads_in_hindi_and_marathi(): void
    {
        $this->confirmed(['cap_amount' => '250000.50']);
        $this->term(['kind' => 'notice', 'code' => 'delay_notice', 'label' => 'Notice of delay', 'method' => null, 'pct' => null, 'base' => null, 'days' => 9, 'counted_from' => 'hindrance_start']);

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('contract-terms.index', $this->project))->assertOk()
            ->assertSee('ठेके की शर्तें')->assertSee('शर्त जोड़ें')->assertSee('मैंने इसे अनुबंध में पढ़ा है')
            ->assertSee('रिटेंशन')->assertSee('देरी की सूचना')->assertSee('9 दिन')->assertSee('गिनती यहाँ से: जिस दिन रुकावट शुरू हुई')
            ->assertSee('कुल मिलाकर ₹2,50,000.50 से ज़्यादा नहीं')->assertSee('पुष्टि हुई')->assertSee('पुष्टि नहीं हुई')
            ->assertSee('ऐप इस पेज पर अपनी ओर से कुछ नहीं भरता।')
            ->assertDontSee('I have read this in the agreement')->assertDontSee('Not confirmed');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('contract-terms.index', $this->project))->assertOk()
            ->assertSee('कराराच्या अटी')->assertSee('अट जोडा')->assertSee('मी हे करारनाम्यात वाचले आहे')
            ->assertSee('रिटेन्शन')->assertSee('विलंबाची सूचना')->assertSee('9 दिवस')->assertSee('मोजणी इथून: अडथळा सुरू झाला तो दिवस')
            ->assertSee('एकूण ₹2,50,000.50 पेक्षा जास्त नाही')->assertSee('निश्चित नाही')
            ->assertSee('ॲप या पानावर स्वतःहून काहीही भरत नाही.')
            ->assertDontSee('I have read this in the agreement')->assertDontSee('Not confirmed');

        // Messages after a save follow the reader's language too.
        $this->actingAs($this->pm)->post(route('contract-terms.store', $this->project), $this->form(['code' => 'tds']))
            ->assertSessionHas('success', 'अट जतन झाली. कोणी ती करारनाम्याशी पडताळून निश्चित केल्यावरच ती धरली जाईल.');
    }

    public function test_the_menu_offers_the_screen_to_those_who_may_open_it(): void
    {
        $link = route('contract-terms.index', $this->project);

        foreach ([$this->pm, $this->qs, $this->accounts, $this->owner] as $user) {
            $this->actingAs($user)->get(route('projects.show', $this->project))->assertOk()->assertSee($link, false)->assertSee('Contract terms');
        }

        $this->actingAs($this->engineer)->get(route('projects.show', $this->project))->assertOk()->assertDontSee($link, false)->assertDontSee('Contract terms');

        // The page also needs money.view, which a custom role can leave out. The menu then leaves the page out too.
        $noMoney = $this->holding(['project.view', 'contract_terms.view', 'contract_terms.manage']);
        $this->actingAs($noMoney)->get($link)->assertForbidden();
        $this->get(route('projects.show', $this->project))->assertOk()->assertDontSee($link, false)->assertDontSee('Contract terms');

        $withMoney = $this->holding(['project.view', 'contract_terms.view', 'money.view']);
        $this->actingAs($withMoney)->get($link)->assertOk();
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee($link, false)->assertSee('Contract terms');

        $free = $this->tenant('Free Co', 'free');
        $project = $this->makeProject($free);
        $this->actingAs($this->member($free, 'owner'))->get(route('projects.show', $project))->assertOk()->assertDontSee(route('contract-terms.index', $project), false);
    }

    public function test_the_roles_get_the_permissions_they_should_and_a_custom_role_can_be_given_them(): void
    {
        foreach ([$this->pm, $this->qs] as $user) {
            $this->assertTrue($user->can('contract_terms.view'));
            $this->assertTrue($user->can('contract_terms.manage'));
        }

        $this->assertTrue($this->accounts->can('contract_terms.view'));
        $this->assertFalse($this->accounts->can('contract_terms.manage'));

        foreach (['engineer', 'store', 'viewer'] as $role) {
            $user = $this->member($this->org, $role);
            $this->assertFalse($user->can('contract_terms.view'), $role);
            $this->assertFalse($user->can('contract_terms.manage'), $role);
        }

        $catalog = app(PermissionCatalog::class);
        $this->assertContains('contract_terms.view', $catalog->all());
        $this->assertContains('contract_terms.manage', $catalog->all());

        app()->setLocale('hi');
        $this->assertSame('ठेके की शर्तें', $catalog->moduleLabel('contract_terms'));

        // The module rides on billing and adds nothing to any plan.
        $this->assertSame('billing', config('modules.contract_terms.feature'));
        $this->assertSame([], config('modules.contract_terms.plans'));
    }
}
