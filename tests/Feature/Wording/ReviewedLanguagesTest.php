<?php

namespace Tests\Feature\Wording;

use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use App\Services\SiteDiary\SiteLetters;
use App\Services\Wording\FactFormat;
use App\Services\Wording\ReviewedLanguages;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * One rule for every document a client or another party reads that is put together from fixed sentences:
 * on the live site its Hindi and Marathi wait for a native reader's review, and everywhere else every
 * language is offered so that the wording can be read. Every module that writes such a document asks the
 * same class, which reads one list per kind of document from config/wording.php.
 */
class ReviewedLanguagesTest extends TestCase
{
    private function live(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    public function test_every_language_is_offered_anywhere_but_the_live_site(): void
    {
        $this->assertFalse(app()->isProduction());

        foreach (ReviewedLanguages::KINDS as $kind) {
            $this->assertSame(FactFormat::LOCALES, ReviewedLanguages::offered($kind));
            $this->assertSame(ClientQuotation::LANGUAGES, ReviewedLanguages::offered($kind, ClientQuotation::LANGUAGES));
            $this->assertTrue(ReviewedLanguages::allOffered($kind));
            $this->assertSame('mr', ReviewedLanguages::starting($kind, 'mr'));
        }
    }

    public function test_on_the_live_site_only_english_and_the_languages_reviewed_for_that_kind_are_offered(): void
    {
        $this->live();

        // Nothing is marked reviewed until a native reader has read the wording.
        foreach (ReviewedLanguages::KINDS as $kind) {
            $this->assertSame([], config("wording.reviewed_languages.{$kind}"), $kind);
            $this->assertSame(['en'], ReviewedLanguages::offered($kind), $kind);
            $this->assertFalse(ReviewedLanguages::allOffered($kind), $kind);
            $this->assertSame('en', ReviewedLanguages::starting($kind, 'hi'), $kind);
            $this->assertSame('en', ReviewedLanguages::starting($kind, null), $kind);
        }

        // Each kind is reviewed on its own: letters being read says nothing about the owner pack.
        config(['wording.reviewed_languages.site_letters' => ['mr']]);
        $this->assertSame(['en', 'mr'], ReviewedLanguages::offered(ReviewedLanguages::SITE_LETTERS));
        $this->assertSame(['en'], ReviewedLanguages::offered(ReviewedLanguages::OWNER_PACK));

        config(['wording.reviewed_languages.owner_pack' => ['hi', 'mr']]);
        $this->assertSame(ClientQuotation::LANGUAGES, ReviewedLanguages::offered(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES));
        $this->assertTrue(ReviewedLanguages::allOffered(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES));

        // A language the module does not write is never offered, whatever the list says.
        config(['wording.reviewed_languages.owner_pack' => ['ta']]);
        $this->assertSame(['en'], ReviewedLanguages::offered(ReviewedLanguages::OWNER_PACK));

        $this->expectException(InvalidArgumentException::class);
        ReviewedLanguages::offered('rate_helper');
    }

    public function test_a_draft_keeps_the_language_it_has_even_one_no_longer_offered(): void
    {
        $this->live();

        $this->assertSame(['en', 'hi'], ReviewedLanguages::allowed(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES, 'hi'));
        $this->assertSame(['en'], ReviewedLanguages::allowed(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES, 'en'));
        $this->assertSame(['en'], ReviewedLanguages::allowed(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES, 'fr'));
        $this->assertSame(['en'], ReviewedLanguages::allowed(ReviewedLanguages::OWNER_PACK, ClientQuotation::LANGUAGES));
    }

    public function test_the_lists_are_read_from_the_environment_one_per_kind(): void
    {
        $config = (string) file_get_contents(config_path('wording.php'));

        $this->assertStringContainsString("env('SITE_LETTER_LANGUAGES_REVIEWED', '')", $config);
        $this->assertStringContainsString("env('OWNER_PACK_LANGUAGES_REVIEWED', '')", $config);
        $this->assertSame(ReviewedLanguages::KINDS, array_keys(config('wording.reviewed_languages')));

        // The letters' own list moved here, so there is one place and one mechanism.
        $this->assertNull(config('modules.site_diary.letter_languages_reviewed'));
    }

    /**
     * Every module that puts a document together from the fixed sentences of App\Services\Wording asks this
     * class which languages it may be written in, and none keeps a list of its own in a validation rule.
     */
    public function test_every_module_that_writes_such_documents_follows_the_one_rule(): void
    {
        $writers = [];

        foreach (File::directories(app_path('Services')) as $folder) {
            if (basename($folder) === 'Wording') {
                continue;
            }

            foreach (File::allFiles($folder) as $file) {
                if (str_contains($file->getContents(), 'use App\Services\Wording\Renderer;')) {
                    $writers[basename($folder)] = $folder;
                }
            }
        }

        // The bill pre-check explains its findings to the company's own people, in the reader's language, as
        // the class says: it is not a document for a client and is not held. It never asks, and that is checked.
        $internal = ['BillCheck'];

        foreach ($internal as $module) {
            $this->assertArrayHasKey($module, $writers);
            $this->assertStringNotContainsString('ReviewedLanguages::', collect(File::allFiles($writers[$module]))->map(fn ($file) => $file->getContents())->implode("\n"));
        }

        $writers = array_diff_key($writers, array_flip($internal));

        $this->assertEqualsCanonicalizing(['OwnerPack', 'SiteDiary'], array_keys($writers), 'The modules that write documents for a client or another party from fixed sentences.');

        foreach ($writers as $module => $folder) {
            $code = collect([...File::allFiles($folder), ...File::allFiles(app_path("Http/Controllers/{$module}"))])
                ->map(fn ($file) => $file->getContents())->implode("\n");

            $this->assertStringContainsString('ReviewedLanguages::', $code, "{$module} asks ReviewedLanguages.");
            $this->assertDoesNotMatchRegularExpression('/Rule::in\((ClientQuotation|OwnerUpdate)::LANGUAGES\)|Rule::in\(FactFormat::LOCALES\)/', $code, "{$module} keeps no list of its own in a rule.");
            $this->assertStringNotContainsString('isProduction()', $code, "{$module} leaves the live-site test to ReviewedLanguages.");
        }

        // The site letters' form offers exactly what the rule offers.
        $this->live();
        config(['wording.reviewed_languages.site_letters' => ['hi']]);
        $this->assertSame(['en', 'hi'], app(SiteLetters::class)->languages());
        $this->assertSame(OwnerUpdate::LANGUAGES, ClientQuotation::LANGUAGES);
    }
}
