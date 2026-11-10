<?php

namespace Tests\Feature\Seo;

use App\Models\User;
use App\Services\Seo\PublicPages;
use App\Services\Seo\SeoSettings;
use App\Services\Seo\SeoText;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Every line this work shows a person has Hindi and Marathi wording, in lang/modules/seo and lang/modules/website. */
class TranslationsTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    /** Product names read the same in every language. They are still listed in the language files, unchanged. */
    private const SAME_EVERYWHERE = ['Google Analytics 4'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return list<string> Text passed through __() while $work runs. */
    private function recorded(callable $work): array
    {
        $translator = app('translator');
        $translator->startRecording();

        try {
            $work();
        } finally {
            return $translator->stopRecording();
        }
    }

    private function admin(): User
    {
        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** @param  list<string>  $texts */
    private function assertTranslated(array $texts, string $what): void
    {
        $missing = [];

        foreach (array_unique($texts) as $text) {
            if (in_array($text, self::SAME_EVERYWHERE, true) || (preg_match('/^[A-Z0-9][A-Z0-9 \/.#&%-]*s?$/', $text) && strlen($text) <= 8)) {
                continue;
            }

            foreach (['hi', 'mr'] as $locale) {
                $line = app('translator')->defaultLine($text, $locale);

                if ($line === $text) {
                    $missing[] = "{$locale}: {$text}";
                }

                preg_match_all('/:[a-z_]+/', $text, $placeholders);

                foreach ($placeholders[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $line, "{$locale} lost {$placeholder} in: {$text}");
                }
            }
        }

        $this->assertSame([], $missing, "{$what} has no translation for: ".implode(' | ', $missing));
    }

    public function test_the_language_files_are_valid_and_in_step(): void
    {
        foreach (['seo', 'website'] as $module) {
            $hi = json_decode((string) file_get_contents(lang_path("modules/{$module}/hi.json")), true, flags: JSON_THROW_ON_ERROR);
            $mr = json_decode((string) file_get_contents(lang_path("modules/{$module}/mr.json")), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(array_keys($hi), array_keys($mr), "lang/modules/{$module} has the same lines in Hindi and Marathi");

            foreach (['hi' => $hi, 'mr' => $mr] as $locale => $lines) {
                foreach ($lines as $key => $line) {
                    $this->assertNotSame('', trim((string) $line), "Empty {$locale} line for {$key}");

                    if (in_array($key, self::SAME_EVERYWHERE, true)) {
                        continue;
                    }

                    $this->assertNotSame($key, $line, "Left in English ({$locale}): {$key}");
                    $this->assertMatchesRegularExpression('/[\x{0900}-\x{097F}]/u', $line, "The {$locale} line for \"{$key}\" is written in Devanagari");
                }
            }
        }
    }

    public function test_the_admin_screen_is_translated(): void
    {
        $this->actingAs($this->admin());

        // What the screen says beyond the frame every admin page shares.
        $frame = $this->recorded(fn () => $this->get('/admin/admins')->assertOk());
        $screen = $this->recorded(fn () => $this->get('/admin/seo')->assertOk());
        $own = array_values(array_diff($screen, $frame));

        $this->assertGreaterThan(30, count($own), 'The recording caught the screen.');
        $this->assertContains('Measurement ID', $own);
        $this->assertContains('Bing Webmaster Tools code', $own);
        $this->assertTranslated([...$own, 'Search and analytics'], 'The search and analytics screen');
    }

    public function test_the_messages_the_screen_answers_with_are_translated(): void
    {
        $this->actingAs($this->admin());

        $texts = $this->recorded(function () {
            app(SeoSettings::class)->messages();
            $this->patch('/admin/seo', ['locale' => 'en'])->assertSessionHasNoErrors();
        });

        $this->assertContains('Search and analytics settings saved.', $texts);
        $this->assertGreaterThanOrEqual(4, count($texts));
        $this->assertTranslated($texts, 'A message from the search and analytics screen');
    }

    public function test_the_consent_question_and_its_footer_link_are_translated(): void
    {
        $without = $this->recorded(fn () => $this->get('/')->assertOk());
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF']);
        $with = $this->recorded(fn () => $this->get('/')->assertOk());

        $own = array_values(array_diff($with, $without));

        $this->assertEqualsCanonicalizing(['Analytics cookies', 'May we use Google Analytics cookies to count visits to this website? Nothing is set unless you accept.', 'Privacy policy', 'Decline', 'Accept', 'Cookie settings'], $own);
        $this->assertTranslated($own, 'The consent question');
    }

    public function test_every_built_in_title_and_description_has_all_three_languages(): void
    {
        $text = app(SeoText::class);
        $pages = app(PublicPages::class);
        $labels = [];

        foreach ($pages->keys() as $page) {
            $labels[] = $pages->label($page);

            foreach (SeoText::FIELDS as $field) {
                $english = $text->builtIn($page, 'en', $field);
                $hindi = $text->builtIn($page, 'hi', $field);
                $marathi = $text->builtIn($page, 'mr', $field);

                $this->assertNotSame($english, $hindi, "{$page} {$field} has Hindi wording");
                $this->assertNotSame($english, $marathi, "{$page} {$field} has Marathi wording");
                $this->assertNotSame($hindi, $marathi, "{$page} {$field}: Hindi and Marathi are not the same line");

                foreach ([$english, $hindi, $marathi] as $line) {
                    $this->assertDoesNotMatchRegularExpression('/:[a-z]+/', $line, "{$page} {$field} has no unfilled placeholder: {$line}");
                }

                // A search result shows about as many characters in any script.
                $limit = $field === 'title' ? SeoText::TITLE_LENGTH : SeoText::DESCRIPTION_LENGTH + 20;
                $this->assertLessThanOrEqual($limit, mb_strlen($field === 'title' ? $text->fullTitle($hindi) : $hindi), "{$page} Hindi {$field} fits");
                $this->assertLessThanOrEqual($limit, mb_strlen($field === 'title' ? $text->fullTitle($marathi) : $marathi), "{$page} Marathi {$field} fits");
            }
        }

        $this->assertTranslated($labels, 'A page name on the admin screen');
    }
}
