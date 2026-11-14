<?php

namespace Tests\Feature\VoiceDpr;

use App\Services\VoiceDpr\VoiceDraftCall;
use Illuminate\Support\Facades\File;
use Tests\Feature\Website\BannedWording;
use Tests\TestCase;

/** Every sentence the voice endpoints can say has Hindi and Marathi, and none of it says what must not be said. */
class TranslationsTest extends TestCase
{
    /** @return list<string> */
    private function moduleStrings(): array
    {
        $files = [...File::allFiles(app_path('Services/VoiceDpr')), ...File::allFiles(app_path('Http/Controllers/VoiceDpr'))];

        return collect($files)->flatMap(function (\SplFileInfo $file) {
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", File::get($file->getPathname()), $matches);

            return array_map(fn (string $key) => str_replace("\\'", "'", $key), $matches[1]);
        })->unique()->values()->all();
    }

    /** @return array<string, string> */
    private function own(string $locale): array
    {
        return json_decode(File::get(lang_path("modules/voice_dpr/{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_sentence_is_in_hindi_and_marathi(): void
    {
        $strings = $this->moduleStrings();
        $this->assertGreaterThan(5, count($strings));

        foreach (['hi', 'mr'] as $locale) {
            foreach ($strings as $key) {
                $translated = __($key, [], $locale);
                $this->assertNotSame($key, $translated, "Missing {$locale} translation: {$key}");
                $this->assertMatchesRegularExpression('/\p{Devanagari}/u', $translated, $key);
            }

            // The module's own file keeps no line it does not say.
            $this->assertSame([], array_values(array_diff(array_keys($this->own($locale)), $strings)), "Unused {$locale} lines");
        }
    }

    public function test_both_files_hold_the_same_lines_in_order_and_agree_with_every_other_screen(): void
    {
        $hindi = $this->own('hi');
        $marathi = $this->own('mr');

        $this->assertSame(array_keys($hindi), array_keys($marathi));

        $sorted = array_keys($hindi);
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, array_keys($hindi), 'The lines are kept in order, so two people adding lines do not collide.');

        foreach (['hi' => $hindi, 'mr' => $marathi] as $locale => $own) {
            $elsewhere = json_decode(File::get(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

            foreach (glob(lang_path("modules/*/{$locale}.json")) as $file) {
                if (basename(dirname($file)) !== 'voice_dpr') {
                    $elsewhere = [...$elsewhere, ...json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR)];
                }
            }

            foreach ($own as $english => $translated) {
                if (isset($elsewhere[$english])) {
                    $this->assertSame($elsewhere[$english], $translated, "{$locale}: \"{$english}\" would change on another module's screen.");
                }
            }
        }
    }

    public function test_nothing_says_what_must_not_be_said(): void
    {
        $texts = [VoiceDraftCall::INSTRUCTIONS, ...$this->moduleStrings(), ...array_values($this->own('hi')), ...array_values($this->own('mr'))];

        foreach ($texts as $text) {
            $this->assertSame([], BannedWording::found($text), $text);
            $this->assertDoesNotMatchRegularExpression('/\bupgrade\b|\bbuy\b/i', $text);
        }
    }
}
