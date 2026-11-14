<?php

namespace Tests\Feature\DocumentReading;

use App\Http\Middleware\RequireAiReader;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ReaderSettings;
use App\Services\Notify\CompanyDeletionService;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Services\OwnerPack\QuotationShareService;
use App\Services\Reports\DprReportService;
use App\Services\Website\AiOffer;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\Website\BannedWording;

/**
 * What the privacy policy and the terms say about the AI provider. The agents are named always.
 * A reader is named only once a company can use one, and voice notes only once they are offered,
 * so neither page promises anything about a feature that is not there.
 */
class LegalPagesTest extends ReadingTestCase
{
    private const READER_SENTENCES = [
        'The AI agents on the website, the AI readers and the other AI features use an AI model provider, Anthropic',
        'When you use an AI agent, a reader or another AI feature, what it needs is processed',
        'Pages and photos sent to a reader are sent as they are, after location and phone details inside the photo file are removed.',
        'Pages, photos and document text sent to an AI reader, and what the reader read from them, are part of the company\'s records.',
    ];

    private const VOICE_SENTENCE = 'The text of voice notes you spoke into the app is deleted with your account, and with the company when it is erased.';

    /**
     * What the provider says about training on what it receives, as its model-training page says it
     * (privacy.claude.com, "Is my data used for model training?", read 13 Nov 2026): by default it does not.
     */
    private const USE = 'We use this information to run the service, keep your account secure, process payments and reply to you. We do not sell your data. We do not use your records to train AI models. The AI provider states that, by default, it does not use what it receives through its commercial service to train its models.';

    /**
     * How long the provider keeps what it receives, as its commercial retention page says it for the API
     * (privacy.claude.com, "How long do you store my organization's data?", read 13 Nov 2026): deleted
     * within 30 days, except in the cases it lists, among them keeping it longer to enforce its usage
     * policy and as the law requires.
     */
    private const RETENTION = 'The provider states that it deletes what it receives through its API within 30 days, except in the cases its policy lists, such as when it needs to keep something longer to enforce its usage policy or the law requires it.';

    /**
     * The paragraph shown until a reader is offered: the live site's words since the agents were named, with every
     * kind of link a company can share named in its first sentences, since quotation and weekly update links go live with it.
     */
    private const WHO_AGENTS = 'Your project records are visible only to people in your company account, and to anyone who has a link your company shares: a client sign-off link for a bill, or a link to a daily report, a quotation or a weekly update. Anyone with such a link can open what it shows until the link stops working after a set number of days, and a quotation or weekly update link can be withdrawn sooner. We use service providers for hosting, file storage, payments and phone notifications (Google Firebase Cloud Messaging), and they handle data only so that we can run the service. The AI agents on the website use an AI model provider, Anthropic: when a company uses the agents, what is asked of them and the records they read to answer are sent to Anthropic. Nothing is sent to it for a company that does not use the agents.';

    private const WHO_READERS = 'Your project records are visible only to people in your company account, and to anyone who has a link your company shares: a client sign-off link for a bill, or a link to a daily report, a quotation or a weekly update. Anyone with such a link can open what it shows until the link stops working after a set number of days, and a quotation or weekly update link can be withdrawn sooner. We use service providers for hosting, file storage, payments and phone notifications (Google Firebase Cloud Messaging), and they handle data only so that we can run the service. The AI agents on the website, the AI readers and the other AI features use an AI model provider, Anthropic: when a company uses them, what is asked of them, the pages, photos and text sent to a reader, and the records they read to answer are sent to Anthropic. The other AI features suggest rates, translate quotation lines and write sentences for letters, quotations, weekly updates and bill checks when someone in the company asks for that; they send the descriptions and labels they work on, but not the rates, quantities or amounts of the company\'s records. Nothing is sent to Anthropic for a company that uses none of them.';

    private const WHERE_AGENTS = 'Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region. When you use an AI agent, what it needs is processed on the provider\'s servers outside India. '.self::RETENTION;

    private const WHERE_READERS = 'Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region. When you use an AI agent, a reader or another AI feature, what it needs is processed on the provider\'s servers outside India. '.self::RETENTION.' Pages and photos sent to a reader are sent as they are, after location and phone details inside the photo file are removed.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // The stand-in reader below is the only one, so each test decides whether a reader is deployed.
        $this->withoutShippedReaders();
        // Showing a public page must never reach the AI provider.
        Http::fake();
        // A key is saved in these tests, so a reader is named once it is deployed and released. Without one the readers
        // are not on and no page names them: ReaderReleaseTest flips each part of the test.
        $this->connect();
    }

    private function deployReader(): void
    {
        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class]]);
    }

    private function deployVoice(bool $accepted): void
    {
        Route::post('/_voice-test', fn () => '')->name(AiOffer::VOICE_ROUTE);
        Route::getRoutes()->refreshNameLookups();
        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => $accepted ? '1' : '0']);
    }

    private function text(string $path): string
    {
        return html_entity_decode((string) preg_replace('/\s+/', ' ', strip_tags((string) $this->get($path)->assertOk()->getContent())), ENT_QUOTES);
    }

    /** The text of the policy or the terms alone, without the site's header and footer. */
    private function article(string $path): string
    {
        $html = (string) $this->get($path)->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<article\b[^>]*>(.*)</article>#s', $html, $match), "{$path} has its text in an article");

        return html_entity_decode((string) preg_replace('/\s+/', ' ', strip_tags($match[1])), ENT_QUOTES);
    }

    public function test_every_kind_of_link_the_policy_names_opens_without_an_account_and_stops_working(): void
    {
        // A client sign-off link, a daily report, a quotation and a weekly update: each is a signed address anyone holding it can open.
        foreach (['client.bill', 'dprs.shared', 'quotations.shared', 'owner-updates.shared', 'owner-updates.shared.photo'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('signed', $route->gatherMiddleware(), $name);
        }

        // Each stops working after a set number of days. Quotation and weekly update links can be withdrawn sooner.
        $this->assertGreaterThan(0, DprReportService::LINK_DAYS);
        $this->assertGreaterThan(0, (int) config('modules.owner_pack.share_link_days'));
        $this->assertTrue(method_exists(QuotationShareService::class, 'revoke') && method_exists(OwnerUpdateShareService::class, 'revoke'));
    }

    public function test_the_policy_names_the_ai_provider_for_the_agents_before_any_reader_exists(): void
    {
        $this->assertFalse(app(ReaderSettings::class)->offered());

        $text = $this->text('/privacy');

        $this->assertStringContainsString(self::USE, $text);
        // The live site's own paragraph about who sees the records, with the links a company shares.
        $this->assertStringContainsString(self::WHO_AGENTS, $text);
        $this->assertStringContainsString(self::WHERE_AGENTS, $text);

        // The old sentence offered an opt-in that never existed.
        $this->assertStringNotContainsString('unless you opt in', $text);
        $this->assertStringNotContainsString('shared AI models', $text);

        foreach ([...self::READER_SENTENCES, self::VOICE_SENTENCE] as $sentence) {
            $this->assertStringNotContainsString($sentence, $text);
        }

        $this->assertStringNotContainsStringIgnoringCase('reader', $text, 'No reader is deployed, so the policy does not speak of one.');
        $this->assertStringNotContainsStringIgnoringCase('voice note', $text);
    }

    public function test_a_reader_is_named_only_once_it_is_deployed_and_released(): void
    {
        // Deployed, not released.
        $this->deployReader();
        $this->assertStringNotContainsStringIgnoringCase('reader', $this->text('/privacy'));
        $this->assertStringNotContainsStringIgnoringCase('reader', $this->text('/terms'));

        // Released, but with no reader deployed.
        config(['modules.reader_stand_ins.read_kinds' => []]);
        $this->release();
        $this->assertStringNotContainsStringIgnoringCase('reader', $this->text('/privacy'));
        $this->assertStringNotContainsStringIgnoringCase('reader', $this->text('/terms'));

        // Both: the same moment the company's own switch appears.
        $this->deployReader();
        $this->assertTrue(app(ReaderSettings::class)->offered());
        $text = $this->text('/privacy');

        foreach (self::READER_SENTENCES as $sentence) {
            $this->assertStringContainsString($sentence, $text);
        }

        $this->assertStringContainsString('They stay with the company when one person\'s account is deleted, and are erased with the company.', $text);
        $this->assertStringNotContainsString(self::VOICE_SENTENCE, $text);
        // What was said without a reader is still said.
        $this->assertStringContainsString('We do not use your records to train AI models.', $text);
        $this->assertStringContainsString(self::RETENTION, $text);
        $this->assertStringContainsString(self::USE, $text);
    }

    /**
     * Once the readers are released, the AI features that are neither agents nor readers can be used too. Every
     * route that has the AI write or rank something from a company's text is named in the policy with what it
     * sends, and the policy no longer says that nothing is sent for a company that uses neither agents nor readers.
     */
    public function test_the_policy_names_every_other_ai_feature_that_sends_text_once_they_can_be_used(): void
    {
        $named = [
            'rate-suggestions.rank' => 'suggest rates',
            'quotations.translate' => 'translate quotation lines',
            'quotations.draft' => 'quotations',
            'owner-updates.draft' => 'weekly updates',
            'site-diary.letters.draft' => 'letters',
            'bill-checks.explain' => 'bill checks',
        ];
        $textCalls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array(RequireAiReader::class.':'.RequireAiReader::TEXT, $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->getName())->values()->all();
        $this->assertEqualsCanonicalizing(array_keys($named), $textCalls, 'Each route that makes a text call is named in the policy.');

        $this->release();
        $this->deployReader();
        $text = $this->text('/privacy');
        $features = 'The other AI features suggest rates, translate quotation lines and write sentences for letters, quotations, weekly updates and bill checks when someone in the company asks for that; they send the descriptions and labels they work on, but not the rates, quantities or amounts of the company\'s records.';

        $this->assertStringContainsString($features, $text);

        foreach ($named as $route => $words) {
            $this->assertStringContainsString($words, $features, $route);
        }

        $this->assertStringContainsString('Nothing is sent to Anthropic for a company that uses none of them.', $text);
        $this->assertStringNotContainsString('uses neither', $text);
        $this->assertStringContainsString('When you use an AI agent, a reader or another AI feature, what it needs is processed on the provider\'s servers outside India.', $text);
        $this->assertStringContainsString('AI agents, AI readers and the other AI features can be wrong.', $this->text('/terms'));
    }

    /**
     * Every sentence about how long the provider keeps what it receives, and whether it trains on it, says
     * what its own pages say (see RETENTION and USE), in each state of the page, in each language, and in
     * the notice a company agrees to. The older line named only flagged content as kept longer, and said
     * nothing of the law or of the other cases the provider lists, so it is gone everywhere.
     */
    public function test_what_the_provider_keeps_and_trains_on_is_said_as_its_own_pages_say_it(): void
    {
        $older = ['safety systems flag', 'deletes this within 30 days', 'through this service to train', 'through this service within 30 days'];

        $before = $this->text('/privacy');
        $this->assertSame(1, substr_count($before, self::RETENTION));
        $this->assertSame(1, substr_count($before, 'by default, it does not use what it receives through its commercial service to train its models.'));

        $this->release();
        $this->deployReader();

        foreach (['en', 'hi', 'mr'] as $locale) {
            $this->withSession(['locale' => $locale]);
            $page = $this->article('/privacy');
            $where = __(self::WHERE_READERS, [], $locale);
            $use = __(self::USE, [], $locale);

            $this->assertStringContainsString($where, $page, $locale);
            $this->assertStringContainsString($use, $page, $locale);
            $this->assertStringContainsString('30', $where, $locale);

            foreach ($older as $phrase) {
                $this->assertStringNotContainsString($phrase, $page, $locale);
            }
        }

        // The Hindi and Marathi lines name the same cases: the provider's own policy, its usage policy, the law, and "by default" for training.
        $named = [
            'hi' => ['उसकी नीति', 'उपयोग नीति', 'कानून', 'डिफ़ॉल्ट रूप से'],
            'mr' => ['त्यांच्या धोरणात', 'वापर धोरण', 'कायद्याने', 'डिफॉल्टनुसार'],
        ];
        $consent = 'Anthropic states that it deletes what it receives through its API within 30 days, except in the cases its policy lists, such as when it needs to keep something longer to enforce its usage policy or the law requires it, and that by default it does not use it to train its models.';

        $this->assertContains($consent, AiDocumentConsent::sentences('en'));

        foreach ($named as $locale => $words) {
            $lines = [__(self::WHERE_READERS, [], $locale), __($consent, [], $locale)];

            foreach ($lines as $line) {
                $this->assertStringContainsString('30', $line, $locale);

                foreach (array_slice($words, 0, 3) as $word) {
                    $this->assertStringContainsString($word, $line, "{$locale}: {$line}");
                }
            }

            $this->assertStringContainsString($words[3], __(self::USE, [], $locale));
            $this->assertStringContainsString($words[3], __($consent, [], $locale));
            $this->assertContains(__($consent, [], $locale), AiDocumentConsent::sentences($locale));

            // No line of the module still says the older thing in any language.
            $file = (string) file_get_contents(lang_path("modules/document_reading/{$locale}.json"));

            foreach ($older as $phrase) {
                $this->assertStringNotContainsString($phrase, $file, $locale);
            }

            $this->assertStringNotContainsString('सुरक्षा प्रणाली', $file, $locale);
        }

        // A new wording of the notice is a new version, so no company stays agreed to the older one.
        $this->assertSame('ai-docs-v3', AiDocumentConsent::VERSION);
    }

    public function test_voice_notes_are_named_only_once_they_are_offered(): void
    {
        $this->release();
        $this->deployVoice(false);

        // The voice endpoint before the acceptance test is no reader: neither readers nor voice notes are spoken of.
        $text = $this->text('/privacy');
        $this->assertStringNotContainsString(self::VOICE_SENTENCE, $text);
        $this->assertStringNotContainsStringIgnoringCase('reader', $text);

        // Accepted, it is a reader, and the only one: the switch appears, and both are spoken of.
        $this->deployVoice(true);
        $text = $this->text('/privacy');
        $this->assertStringContainsString(self::READER_SENTENCES[0], $text);
        $this->assertStringContainsString(self::VOICE_SENTENCE, $text);

        // Accepted for voice, but the readers were switched off again: nothing about either.
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $text = $this->text('/privacy');
        $this->assertStringNotContainsString(self::VOICE_SENTENCE, $text);
        $this->assertStringNotContainsStringIgnoringCase('reader', $text);
    }

    public function test_the_terms_say_that_what_the_ai_prepares_is_a_draft_until_a_person_confirms_it(): void
    {
        $rest = ' can be wrong. What they prepare is saved as a draft and marked as one. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.';

        $text = $this->text('/terms');
        $this->assertStringContainsString('AI agents'.$rest, $text);
        $this->assertStringNotContainsStringIgnoringCase('reader', $text);
        // It sits with the sentence about checking figures, which stays.
        $this->assertStringContainsString('You remain responsible for checking them against your contracts, drawings, codes and tax rules before you rely on them. AI agents can be wrong.', $text);

        $this->release();
        $this->deployReader();
        $this->assertStringContainsString('AI agents, AI readers and the other AI features can be wrong. What they prepare is saved as a draft or shown as a suggestion, and marked as such. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.', $this->text('/terms'));
    }

    public function test_what_the_pages_said_about_deleting_an_account_is_still_there(): void
    {
        $this->release();
        $this->deployReader();
        $this->deployVoice(true);

        $page = route('account-deletion');

        $this->get('/privacy')->assertOk()
            ->assertSee('<h2 id="deleting-your-account">Deleting your account</h2>', false)
            ->assertSee($page, false)
            ->assertSee('If you are a member of a company, your account is deleted straight away: your sign-in, name, email address, mobile number, notification settings, phone notification tokens and questions to the AI agents.')
            ->assertSee('stay with the company and show "Deleted user"', false)
            ->assertSee('with the network address each action came from')
            ->assertSee('deleting your account deletes the company. It keeps working for '.CompanyDeletionService::GRACE_DAYS.' days, and any owner can cancel by signing in during that time: in the app under Settings, Delete account, or on the website under Account, Delete my account.')
            ->assertSee('we email you when it is done')
            ->assertSee('are kept as Indian tax law requires')
            ->assertSee('Backup copies are overwritten on a rolling schedule.');

        // The new lines sit inside that list, after the two that say who is deleted and before what is kept.
        $text = $this->text('/privacy');
        $this->assertLessThan(strpos($text, 'Pages, photos and document text sent to an AI reader'), strpos($text, 'If you are your company\'s only owner'));
        $this->assertLessThan(strpos($text, 'Records of payments made to Nirmaan360'), strpos($text, self::VOICE_SENTENCE));

        $this->get('/terms')->assertOk()
            ->assertSee($page, false)
            ->assertSee('You can delete your account, or your company if you are its only owner, yourself in the app or on the website')
            // There is no offline plan any more, so every owner can export.
            ->assertSee('An owner can export the company\'s data first under Account, Company data.', false)
            ->assertDontSee('offline plan');
    }

    public function test_neither_page_says_what_must_not_be_said_in_any_state(): void
    {
        $check = function (string $state) {
            foreach (['/privacy', '/terms'] as $path) {
                $this->assertSame([], BannedWording::found($this->text($path)), "{$path} {$state}");
            }
        };

        $check('with nothing deployed');

        $this->release();
        $this->deployReader();
        $check('with a reader');

        $this->deployVoice(true);
        $check('with voice notes');

        // The files themselves, whichever branch is drawn.
        foreach (['privacy', 'terms'] as $view) {
            $this->assertSame([], BannedWording::found((string) file_get_contents(resource_path("views/public/{$view}.blade.php"))), $view);
        }
    }

    /**
     * The legal pages are in English. What they say about the AI provider is in Hindi or Marathi only
     * once a reader is offered, which the platform admin does after a native reader has checked those
     * lines. Before that a visitor whose language is Hindi or Marathi reads both pages in English, whole.
     */
    public function test_the_pages_stay_in_english_whole_until_a_reader_is_offered(): void
    {
        $agents = [
            'privacy' => [
                self::USE,
                self::WHO_AGENTS,
                self::WHERE_AGENTS,
            ],
            'terms' => ['AI agents can be wrong. What they prepare is saved as a draft and marked as one. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.'],
        ];

        $this->assertFalse(app(ReaderSettings::class)->offered());

        // Deployed but not released, and released with nothing deployed, are the same: no reader is offered.
        foreach (['nothing deployed' => fn () => null, 'deployed, not released' => fn () => $this->deployReader()] as $state => $arrange) {
            $arrange();

            foreach (['hi', 'mr', 'en'] as $locale) {
                $this->withSession(['locale' => $locale]);

                foreach ($agents as $view => $paragraphs) {
                    $policy = $this->article("/{$view}");

                    $this->assertDoesNotMatchRegularExpression('/\p{Devanagari}/u', $policy, "{$locale} {$view} {$state}: one language");

                    foreach ($paragraphs as $english) {
                        $this->assertStringContainsString($english, $policy, "{$locale} {$view} {$state}");
                    }
                }
            }
        }
    }

    public function test_once_a_reader_is_offered_what_the_pages_say_about_the_ai_provider_is_in_the_visitors_language_whole(): void
    {
        $readers = [
            'privacy' => [
                self::USE,
                self::WHO_READERS,
                self::WHERE_READERS,
                'Pages, photos and document text sent to an AI reader, and what the reader read from them, are part of the company\'s records. They stay with the company when one person\'s account is deleted, and are erased with the company.',
            ],
            'terms' => ['AI agents, AI readers and the other AI features can be wrong. What they prepare is saved as a draft or shown as a suggestion, and marked as such. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.'],
        ];

        $this->release();
        $this->deployReader();

        foreach (['hi', 'mr'] as $locale) {
            foreach ($readers as $view => $paragraphs) {
                $this->withSession(['locale' => $locale]);
                $page = $this->article("/{$view}");

                foreach ($paragraphs as $english) {
                    $translated = __($english, [], $locale);
                    $this->assertNotSame($english, $translated, "{$locale}: {$english}");
                    $this->assertStringContainsString($translated, $page, "{$locale} {$view}");
                    $this->assertStringNotContainsString($english, $page, "{$locale} {$view}");
                }
            }
        }

        // In English the same paragraphs are there as they were.
        $this->withSession(['locale' => 'en']);

        foreach ($readers as $view => $paragraphs) {
            foreach ($paragraphs as $english) {
                $this->assertStringContainsString($english, $this->article("/{$view}"), "en {$view}");
            }
        }
    }

    public function test_text_saved_by_the_platform_admin_still_replaces_the_built_in_text(): void
    {
        $this->release();
        $this->deployReader();
        app(PlatformSettings::class)->set(['privacy_text' => '## Our policy', 'terms_text' => '## Our terms']);

        // An override is the admin's own text: it must be saved again to say what the built-in text now says.
        $this->get('/privacy')->assertOk()->assertSee('Our policy')->assertDontSee('Anthropic');
        $this->get('/terms')->assertOk()->assertSee('Our terms')->assertDontSee('AI agents, AI readers and the other AI features can be wrong');
    }
}
