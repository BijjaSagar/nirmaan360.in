<?php

namespace Tests\Feature\Workforce;

use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Every sentence this module shows must have a Hindi and a Marathi version. */
class TranslationsTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private const FOLDERS = [
        'app/Http/Controllers/Workforce', 'app/Http/Controllers/Payroll', 'app/Http/Controllers/Sso', 'app/Http/Controllers/PublicApi',
        'app/Services/Payroll', 'app/Services/Workforce', 'app/Services/Sso', 'app/Services/PublicApi',
        'resources/views/modules',
    ];

    /** Looked up by name at run time (status stamps, leave kinds, expense categories), so no literal shows them. */
    private const BY_NAME = [
        'Pending', 'Approved', 'Paid', 'Rejected', 'Cancelled', 'Active', 'Draft',
        'Casual leave', 'Sick leave', 'Earned leave', 'Unpaid leave',
        'Fuel', 'Food', 'Travel', 'Tools', 'Materials', 'Labour', 'Repairs', 'Other',
    ];

    /** @return list<string> */
    private function strings(): array
    {
        $files = [base_path('app/Http/Middleware/AuthenticateApiKey.php')];

        foreach (self::FOLDERS as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = $file->getPathname();
            }
        }

        $strings = self::BY_NAME;

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/s", $code, $single);
            preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/s', $code, $double);
            $strings = [...$strings, ...array_map('stripslashes', $single[1]), ...array_map('stripcslashes', $double[1])];
        }

        foreach (['payroll', 'workforce', 'sso', 'public_api'] as $module) {
            foreach (config("modules.{$module}.nav") as $item) {
                $strings = [...$strings, $item['label'], ...(isset($item['group']) ? [$item['group']] : [])];
            }
        }

        return array_values(array_unique($strings));
    }

    /** @return array<string, string> */
    private function dictionary(string $locale): array
    {
        $dictionary = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach (glob(lang_path('modules/*/'.$locale.'.json')) as $file) {
            $dictionary = [...$dictionary, ...json_decode((string) file_get_contents($file), true)];
        }

        return $dictionary;
    }

    public function test_every_sentence_has_a_hindi_and_a_marathi_version(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            $dictionary = $this->dictionary($locale);
            $missing = array_values(array_filter($this->strings(), fn ($text) => blank($dictionary[$text] ?? null)));

            $this->assertSame([], $missing, "Missing {$locale} translations");
        }
    }

    public function test_translations_keep_their_placeholders(): void
    {
        foreach (['hi', 'mr'] as $locale) {
            foreach ($this->dictionary($locale) as $english => $translated) {
                preg_match_all('/:[a-z_]+/', $english, $expected);
                preg_match_all('/:[a-z_]+/', $translated, $found);

                $this->assertSame([], array_diff($expected[0], $found[0]), "{$locale}: {$english}");
            }
        }
    }

    public function test_module_language_files_cover_the_same_sentences(): void
    {
        $hi = json_decode((string) file_get_contents(lang_path('modules/workforce/hi.json')), true);
        $mr = json_decode((string) file_get_contents(lang_path('modules/workforce/mr.json')), true);

        $this->assertNotEmpty($hi);
        $this->assertSame(array_keys($hi), array_keys($mr));
    }

    public function test_pages_speak_the_users_language(): void
    {
        $this->withoutVite();
        $organization = $this->tenant('Hindi Co', 'business');
        $owner = $this->member($organization, 'owner');
        $owner->update(['locale' => 'hi']);

        $this->actingAs($owner)->get('/payroll/runs')->assertOk()->assertSee('मज़दूरी गणनाएँ')->assertSee('नई मज़दूरी गणना');
        $this->actingAs($owner)->get('/developer/api-keys')->assertOk()->assertSee('API कुंजियाँ');

        $owner->update(['locale' => 'mr']);
        $this->actingAs($owner)->get('/payroll/runs')->assertOk()->assertSee('नवीन मजुरी गणना');
    }
}
