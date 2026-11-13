<?php

namespace Tests\Feature\BillCheck;

use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\Flag;
use App\Services\BillCheck\FlagCatalog;
use App\Services\BillCheck\FlagText;
use Tests\Feature\Website\BannedWording;

/**
 * What the pre-check says, in three languages, and what it must never say: a percentage, a period or
 * a clause of its own, a rupee figure to someone who may not see money, or any wording the product
 * does not use.
 */
class BillCheckWordingTest extends BillCheckTestCase
{
    /** One made-up value for each kind of figure a finding can carry. */
    private const SAMPLES = [
        'money' => '12345.67', 'pct' => '7.25', 'source' => 'Sample agreement, part Z', 'qty' => '3.500',
        'ratio' => '9.5', 'count' => 4, 'date' => '2026-11-30', 'text' => 'XYZ-9', 'head' => 'retention',
    ];

    /** How each private sample reads once it is printed. */
    private const PRIVATE_SHOWN = ['money' => '12,345.67', 'pct' => '7.25%', 'source' => 'Sample agreement, part Z'];

    /** @return list<string> The module's own source: code, screen, routes, registration and migration. */
    private function sourceFiles(): array
    {
        return [
            ...glob(app_path('Services/BillCheck/*.php')),
            ...glob(app_path('Services/BillCheck/Checks/*.php')),
            ...glob(app_path('Http/Controllers/BillCheck/*.php')),
            ...glob(resource_path('views/modules/bill_check/*.blade.php')),
            app_path('Models/BillCheck.php'),
            app_path('Models/BillCheckAck.php'),
            app_path('Models/BillCheckPolicy.php'),
            config_path('modules/bill_check.php'),
            base_path('routes/modules/bill_check.php'),
            database_path('migrations/2027_01_14_100002_create_bill_check_tables.php'),
        ];
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("modules/bill_check/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, list<string>> Text => every translation of it in the core file and the other modules' files. */
    private function elsewhere(string $locale): array
    {
        $lines = [];

        foreach ([lang_path($locale.'.json'), ...glob(lang_path("modules/*/{$locale}.json"))] as $file) {
            if (str_contains($file, 'modules/bill_check/')) {
                continue;
            }

            foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $english => $translated) {
                $lines[$english][] = $translated;
            }
        }

        return $lines;
    }

    /** @return list<string> Every text the module shows: the literals passed to __(), every sentence of the catalogue and the deduction names. */
    private function usedTexts(): array
    {
        $texts = [];

        foreach ($this->sourceFiles() as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $text) {
                $texts[] = str_replace("\\'", "'", $text);
            }
        }

        return array_values(array_unique([...$texts, ...FlagCatalog::sentences(), ...array_values(FlagCatalog::HEADS)]));
    }

    /** A finding of this kind with a made-up value for every figure it carries. */
    private function sample(string $code): Flag
    {
        $definition = FlagCatalog::definition($code);

        return new Flag($code.':1', $code, $definition['level'], array_map(fn (string $type) => self::SAMPLES[$type], $definition['params']));
    }

    public function test_every_text_has_a_hindi_and_a_marathi_line(): void
    {
        $this->assertGreaterThan(120, count($this->usedTexts()));

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
                preg_match_all('/:[a-z_]+/', $english, $expected);
                preg_match_all('/:[a-z_]+/', $translated, $actual);

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

    public function test_the_catalogue_is_complete_and_every_finding_a_check_makes_is_in_it(): void
    {
        foreach (FlagCatalog::DEFINITIONS as $code => $definition) {
            $this->assertContains($definition['level'], Flag::LEVELS, $code);
            $this->assertLessThanOrEqual(40, strlen($code), 'A key is the code and a row id, and must fit the acknowledgement column.');

            $private = FlagCatalog::privateParams($code);
            $forms = array_keys(array_intersect_key($definition, array_flip(['text', 'money', 'plain'])));

            // One sentence when nothing is private, two when something is.
            $this->assertSame($private === [] ? ['text'] : ['money', 'plain'], $forms, $code);

            foreach ($forms as $form) {
                preg_match_all('/:([a-z_]+)/', $definition[$form], $used);
                $this->assertSame([], array_values(array_diff($used[1], array_keys($definition['params']))), "{$code} {$form} names a figure it does not carry.");

                if ($form === 'plain') {
                    $this->assertSame([], array_values(array_intersect($used[1], $private)), "{$code}: the plain sentence names a private figure.");
                } else {
                    $this->assertEqualsCanonicalizing(array_keys($definition['params']), array_values(array_unique($used[1])), "{$code} {$form} leaves a figure out.");
                }
            }

            foreach ($definition['params'] as $name => $type) {
                $this->assertArrayHasKey($type, self::SAMPLES, "{$code}.{$name} has an unknown type.");
            }
        }

        // Every code a check can make, read from the source, is in the catalogue, and nothing in the catalogue is dead.
        $made = ['X1'];

        foreach (glob(app_path('Services/BillCheck/Checks/*.php')) as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all("/->flag\\('([A-Z][A-Za-z0-9._]*)'/", $source, $literal);
            preg_match_all("/\\? '([A-Z][0-9](?:\\.[a-z_]+)?)' : '([A-Z][0-9](?:\\.[a-z_]+)?)'/", $source, $either);
            array_push($made, ...$literal[1], ...$either[1], ...$either[2]);
        }

        $this->assertEqualsCanonicalizing(array_keys(FlagCatalog::DEFINITIONS), array_values(array_unique($made)));
    }

    public function test_every_finding_reads_in_three_languages_and_the_plain_form_gives_no_private_figure_away(): void
    {
        $text = app(FlagText::class);

        foreach (['en', 'hi', 'mr'] as $locale) {
            app()->setLocale($locale);

            foreach (array_keys(FlagCatalog::DEFINITIONS) as $code) {
                $flag = $this->sample($code);
                $types = FlagCatalog::definition($code)['params'];

                foreach ([true, false] as $money) {
                    $line = $text->line($flag, $money);

                    $this->assertSame(0, preg_match('/:[a-z_]+/', $line), "{$locale} {$code}: a figure was not placed in: {$line}");
                    $this->assertSame($locale !== 'en', (bool) preg_match('/\p{Devanagari}/u', $line), "{$locale} {$code}: {$line}");

                    foreach ($types as $name => $type) {
                        if (isset(self::PRIVATE_SHOWN[$type])) {
                            $this->assertSame($money, str_contains($line, self::PRIVATE_SHOWN[$type]), "{$locale} {$code} money=".var_export($money, true).": {$line}");
                        }
                    }

                    if (! $money) {
                        $this->assertStringNotContainsString('₹', $line);
                        $this->assertSame(array_fill_keys(FlagCatalog::privateParams($code), null), array_intersect_key($text->params($flag, false), array_flip(FlagCatalog::privateParams($code))));
                    }
                }

                $this->assertSame($flag->params, $text->params($flag, true));
            }
        }

        app()->setLocale('en');

        // A finding stored by a later version, which this catalogue does not know, gives nothing away.
        $unknown = new Flag('Z9:1', 'Z9', Flag::TICK, ['amount' => '500.00']);
        $this->assertSame('A finding that this version of the app cannot put into words.', $text->line($unknown, true));
        $this->assertSame(['amount' => null], $text->params($unknown, false));
    }

    public function test_the_code_holds_no_percentage_no_period_and_no_clause_of_its_own(): void
    {
        $files = [...$this->sourceFiles(), ...glob(lang_path('modules/bill_check/*.json'))];
        $this->assertGreaterThanOrEqual(25, count($files));

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = str_replace(base_path().'/', '', $file);

            $this->assertSame(0, preg_match('/[\d०-९]\s?(%|percent|per cent|प्रतिशत|टक्के)/u', $source), "A percentage is written in {$name}.");
            $this->assertSame(0, preg_match('/\b\d+\s+(days?|weeks?|months?)\b/i', $source), "A period is written in {$name}.");
            $this->assertSame(0, preg_match('/\b(clause|section|sub-clause|article|rule)\s+(no\.?\s*)?\d/i', $source), "A clause is named in {$name}.");
            $this->assertSame(0, preg_match('/(खंड|कलम|धारा)\s*[\d०-९]/u', $source), "A clause is named in {$name}.");
            $this->assertSame(0, preg_match('/\b(19\d[A-Z]{0,2}|CPWD|PWD|MoRTH|FIDIC|GCC)\b/', $source), "A law, department or form of contract is named in {$name}.");

        }

        // Wording the product does not use, looked for in everything the module can show: every sentence it
        // passes to a screen, both translation files and the card itself. Code is left out, where a
        // collection is rightly asked for its unique values.
        $shown = [
            'the texts' => implode("\n", $this->usedTexts()),
            'the card' => (string) file_get_contents(resource_path('views/modules/bill_check/_card.blade.php')),
            'the Hindi file' => (string) file_get_contents(lang_path('modules/bill_check/hi.json')),
            'the Marathi file' => (string) file_get_contents(lang_path('modules/bill_check/mr.json')),
        ];

        foreach ($shown as $where => $text) {
            $this->assertSame([], BannedWording::found($text), "Not to be said in {$where}.");
        }

        // The checks do find what they look for. These lines are made up for the test.
        $this->assertSame(1, preg_match('/[\d०-९]\s?(%|percent|per cent|प्रतिशत|टक्के)/u', 'A head is 3% of the bill'));
        $this->assertSame(1, preg_match('/\b\d+\s+(days?|weeks?|months?)\b/i', 'within 17 days of the bill'));
        $phrase = BannedWording::phrases()[1];
        $this->assertSame([$phrase], BannedWording::found("The bill is {$phrase} to pass."));
    }

    public function test_the_module_adds_no_permission_no_plan_feature_and_no_menu_entry(): void
    {
        $config = config('modules.bill_check');

        $this->assertSame('billing', $config['feature']);
        $this->assertSame([], $config['plans']);
        $this->assertArrayNotHasKey('permissions', $config);
        $this->assertArrayNotHasKey('nav', $config);
        $this->assertSame(BillChecks::REMARK_MIN, 5, 'The shortest remark that counts as one.');
    }

    public function test_the_card_reads_in_hindi_and_marathi(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $check = $this->check($bill, $this->pm);
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'Seen and noted', $this->qs);
        $page = route('ra-bills.show', $bill);

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get($page)->assertOk()
            ->assertSee('बिल की पूर्व-जाँच')->assertSee('दोबारा जाँचें')->assertSee('इसे देखें')
            ->assertSee('रिटेंशन 6.5%: यह प्रतिशत वही है जो ठेके के फ़ॉर्म में भरा गया है। किसी ने अनुबंध से मिलाकर इसकी पुष्टि नहीं की है।')
            ->assertSee('जिन बातों पर "देख लिया" दर्ज होना बाकी है: 3')
            ->assertSee('खाली सूची का मतलब है कि इन जाँचों में कुछ नहीं मिला। इसका मतलब यह नहीं कि बिल पास हो जाएगा।')
            ->assertSee('प्रतिशत वही हैं जो इस ठेके के लिए दिखाई गई तारीख़ों पर दर्ज किए गए हैं।')
            ->assertSee('जाँच का हर रुपया ऐप आपके रिकॉर्ड और इस ठेके के लिए दर्ज प्रतिशत से खुद निकालता है।')
            ->assertSee('इस ठेके के लिए कटौती का कोई और मद दर्ज नहीं है।')
            ->assertDontSee('Bill pre-check')->assertDontSee('An empty list means')->assertDontSee('this percentage is the one typed');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get($page)->assertOk()
            ->assertSee('बिलाची पूर्वतपासणी')->assertSee('पुन्हा तपासा')->assertSee('हे पाहा')
            ->assertSee('रिटेन्शन 6.5%: ही टक्केवारी कराराच्या फॉर्ममध्ये भरलेली आहे. कोणीही ती करारनाम्याशी पडताळून निश्चित केलेली नाही.')
            ->assertSee('रिकामी यादी म्हणजे या तपासण्यांमध्ये काहीही आढळले नाही. याचा अर्थ बिल मंजूर होईलच असा नाही.')
            ->assertSee('टक्केवारी तीच आहे जी या करारासाठी दाखवलेल्या तारखांना नोंदवलेली आहे.')
            ->assertSee('तपासणीतील प्रत्येक रुपया ॲप तुमच्या नोंदी आणि या करारासाठी नोंदवलेल्या टक्केवारीवरून स्वतः काढते.')
            ->assertDontSee('Bill pre-check')->assertDontSee('An empty list means')->assertDontSee('this percentage is the one typed');

        // Messages follow the reader's language too, and the JSON carries the sentence in it.
        $this->actingAs($this->pm)->post(route('bill-checks.run', $bill))->assertSessionHas('success', 'पुन्हा तपासणी केली. मागील तपासणीनंतर काहीही बदललेले नाही.');
        $this->assertSame(
            'TDS 1.75%: ही टक्केवारी कराराच्या फॉर्ममध्ये भरलेली आहे. कोणीही ती करारनाम्याशी पडताळून निश्चित केलेली नाही.',
            collect($this->actingAs($this->pm)->getJson(route('bill-checks.show', $bill))->json('check.flags'))->firstWhere('key', 'T1:tds')['text'],
        );

        $this->requireCheck();
        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->post(route('ra-bills.submit', $bill))
            ->assertSessionHasErrors(['check' => 'यह बिल भेजने से पहले जाँच की हर बात पर "देख लिया" दर्ज करें। अभी बाकी: 3।']);
    }
}
