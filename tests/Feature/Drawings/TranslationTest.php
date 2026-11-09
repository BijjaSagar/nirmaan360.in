<?php

namespace Tests\Feature\Drawings;

use App\Services\Drawings\BbsCalculator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function languages(): array
    {
        return ['Hindi' => ['hi'], 'Marathi' => ['mr']];
    }

    #[DataProvider('languages')]
    public function test_every_string_the_module_shows_is_translated(string $language): void
    {
        $known = [
            ...json_decode(File::get(lang_path("{$language}.json")), true),
            ...json_decode(File::get(lang_path("modules/drawings/{$language}.json")), true),
        ];

        $missing = array_values(array_filter($this->keys(), fn (string $key) => ! isset($known[$key])));

        $this->assertSame([], $missing);
    }

    #[DataProvider('languages')]
    public function test_placeholders_survive_translation(string $language): void
    {
        foreach (json_decode(File::get(lang_path("modules/drawings/{$language}.json")), true) as $key => $value) {
            preg_match_all('/:[a-z]+/', $key, $expected);
            preg_match_all('/:[a-z]+/', $value, $actual);
            sort($expected[0]);
            sort($actual[0]);

            $this->assertSame($expected[0], $actual[0], $key);
        }
    }

    /** @return list<string> */
    private function keys(): array
    {
        $files = [
            ...File::allFiles(resource_path('views/modules/drawings')),
            ...File::allFiles(app_path('Services/Drawings')),
            ...File::allFiles(app_path('Http/Controllers/Drawings')),
        ];

        $keys = [];

        foreach ($files as $file) {
            // PDFs follow the other printed documents and stay in English.
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'pdf'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);
            $keys = [...$keys, ...array_map('stripslashes', $matches[1])];
        }

        $config = config('modules.drawings');
        $keys = [...$keys, ...array_column($config['nav'], 'label'), ...$config['disciplines'], 'Drawing', 'Document'];

        foreach ([...$config['version_statuses'], ...$config['transmittal_purposes'], 'issued', 'acknowledged', 'cancelled'] as $status) {
            $keys[] = Str::headline($status);
        }

        foreach (BbsCalculator::shapes() as $shape) {
            $keys[] = $shape['name'];

            if (isset($shape['condition'])) {
                $keys[] = $shape['condition'];
            }
        }

        return array_values(array_unique([...$keys, ...array_values(BbsCalculator::steelTypes())]));
    }
}
