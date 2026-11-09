<?php

namespace Tests\Feature\Crm;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Every word the module shows goes through __() and has a Hindi and a Marathi translation. */
class CrmTranslationsTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return list<string> */
    private function moduleFiles(): array
    {
        $files = [base_path('config/modules/crm.php'), app_path('Notifications/CrmReminder.php')];

        foreach (['app/Services/Crm', 'app/Http/Controllers/Crm', 'resources/views/modules/crm'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (glob(app_path('Models/Crm*.php')) as $model) {
            $files[] = $model;
        }

        return $files;
    }

    /** @return list<string> */
    private function usedKeys(): array
    {
        $keys = [];

        foreach ($this->moduleFiles() as $file) {
            $source = file_get_contents($file);
            preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s', $source, $single);
            preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/s', $source, $double);

            foreach ($single[1] as $key) {
                $keys[str_replace("\\'", "'", $key)] = true;
            }

            foreach ($double[1] as $key) {
                $keys[stripcslashes($key)] = true;
            }
        }

        foreach (config('modules.crm.nav') as $item) {
            $keys[$item['label']] = true;
            $keys[$item['group'] ?? 'Sales'] = true;
        }

        return array_keys($keys);
    }

    /** @return array<string,string> */
    private function translations(string $locale): array
    {
        return [
            ...json_decode(file_get_contents(lang_path($locale.'.json')), true),
            ...json_decode(file_get_contents(lang_path('modules/crm/'.$locale.'.json')), true),
        ];
    }

    public function test_the_scan_finds_the_text_the_module_uses(): void
    {
        $keys = $this->usedKeys();

        $this->assertGreaterThan(300, count($keys));
        $this->assertContains('Add tender', $keys);
        $this->assertContains('Sales overview', $keys);
        $this->assertContains('Tender follow-up', $keys);
    }

    public function test_every_word_has_a_hindi_and_marathi_translation(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $translations = $this->translations($locale);
            $missing = array_values(array_filter($this->usedKeys(), fn ($key) => ! isset($translations[$key]) || trim($translations[$key]) === ''));

            $this->assertSame([], array_map(fn ($key) => str_replace("\n", '\\n', $key), $missing), "Missing {$locale} translations");
        }
    }

    public function test_translations_keep_the_placeholders_of_the_english_text(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $own = json_decode(file_get_contents(lang_path('modules/crm/'.$locale.'.json')), true);

            foreach ($own as $english => $translated) {
                preg_match_all('/:[a-z_]+|\{[a-z_]+\}/', $english, $expected);
                preg_match_all('/:[a-z_]+|\{[a-z_]+\}/', $translated, $actual);
                sort($expected[0]);
                sort($actual[0]);

                $this->assertSame($expected[0], $actual[0], "{$locale}: ".str_replace("\n", '\\n', $english));
            }
        }
    }

    public function test_the_module_files_hold_only_texts_the_module_uses(): void
    {
        $used = array_flip($this->usedKeys());

        foreach (['hi', 'mr'] as $locale) {
            $own = json_decode(file_get_contents(lang_path('modules/crm/'.$locale.'.json')), true);
            $unused = array_values(array_filter(array_keys($own), fn ($key) => ! isset($used[$key])));

            $this->assertSame([], $unused, "Unused {$locale} translations");
        }
    }

    public function test_the_screens_appear_in_hindi_and_marathi(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['title' => 'Mula bridge']);

        $this->get('/locale/hi');
        $this->get(route('crm.tenders.index'))->assertOk()->assertSee('टेंडर जोड़ें')->assertSee('पूछताछ')->assertDontSee('Add tender');
        $this->get(route('crm.tenders.show', $tender))->assertOk()->assertSee('EMD और शुल्क')->assertSee('फॉलो-अप');

        $this->get('/locale/mr');
        $this->get(route('crm.tenders.index'))->assertOk()->assertSee('टेंडर जोडा')->assertSee('चौकशी')->assertDontSee('Add tender');
        $this->get(route('crm.leads.index'))->assertOk()->assertSee('लीड जोडा');
        $this->get('/dashboard')->assertOk()->assertSee('सेल्स सारांश');
    }

    public function test_message_templates_made_for_a_marathi_user_are_in_marathi(): void
    {
        $user = $this->crmUser();
        $this->get('/locale/mr');

        $this->get(route('crm.setup'))->assertOk()->assertSee('टेंडर फॉलो-अप')->assertSee('{contact}', false);
    }
}
