<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\ReaderSettings;
use App\Services\Notify\CompanyDeletionService;
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
        'for the AI agents and readers, only when your company uses them',
        'When you use an AI agent or reader, what it needs is processed',
        'Pages and photos sent to a reader are sent as they are, after location and phone details inside the photo file are removed.',
        'Pages, photos and document text sent to an AI reader, and what the reader read from them, are part of the company\'s records.',
    ];

    private const VOICE_SENTENCE = 'The text of voice notes you spoke into the app is deleted with your account, and with the company when it is erased.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Showing a public page must never reach the AI provider.
        Http::fake();
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

    public function test_the_policy_names_the_ai_provider_for_the_agents_before_any_reader_exists(): void
    {
        $this->assertFalse(app(ReaderSettings::class)->offered());

        $text = $this->text('/privacy');

        $this->assertStringContainsString('We do not sell your data. We do not use your records to train AI models. The AI provider states that it does not use what it receives through this service to train its models.', $text);
        $this->assertStringContainsString('phone notifications (Google Firebase Cloud Messaging), and an AI model provider (Anthropic) for the AI agents, only when your company uses them. They handle data only so that we can run the service.', $text);
        $this->assertStringContainsString('When you use an AI agent, what it needs is processed on the provider\'s servers outside India. The provider states that it deletes this within 30 days, except content its safety systems flag, which can be kept longer.', $text);

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
        $this->assertStringContainsString('The provider states that it deletes this within 30 days, except content its safety systems flag, which can be kept longer.', $text);
    }

    public function test_voice_notes_are_named_only_once_they_are_offered(): void
    {
        $this->release();
        $this->deployVoice(false);

        // The voice endpoint alone makes the switch appear, so readers are spoken of; voice notes are not yet.
        $text = $this->text('/privacy');
        $this->assertStringContainsString(self::READER_SENTENCES[0], $text);
        $this->assertStringNotContainsString(self::VOICE_SENTENCE, $text);

        $this->deployVoice(true);
        $this->assertStringContainsString(self::VOICE_SENTENCE, $this->text('/privacy'));

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
        $this->assertStringContainsString('AI agents and readers'.$rest, $this->text('/terms'));
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
            ->assertSee('An owner can export the company\'s data first under Account, Company data, on every plan except the offline plan.', false);
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

    public function test_what_the_pages_say_about_the_ai_provider_is_in_the_visitors_language_whole(): void
    {
        $agents = [
            'privacy' => [
                'We use this information to run the service, keep your account secure, process payments and reply to you. We do not sell your data. We do not use your records to train AI models. The AI provider states that it does not use what it receives through this service to train its models.',
                'Your project records are visible only to people in your company account, and to anyone you send a client sign-off link. We use service providers for hosting, file storage, payments and phone notifications (Google Firebase Cloud Messaging), and an AI model provider (Anthropic) for the AI agents, only when your company uses them. They handle data only so that we can run the service.',
                'Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region. When you use an AI agent, what it needs is processed on the provider\'s servers outside India. The provider states that it deletes this within 30 days, except content its safety systems flag, which can be kept longer.',
            ],
            'terms' => ['AI agents can be wrong. What they prepare is saved as a draft and marked as one. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.'],
        ];
        $readers = [
            'privacy' => [
                'Your project records are visible only to people in your company account, and to anyone you send a client sign-off link. We use service providers for hosting, file storage, payments and phone notifications (Google Firebase Cloud Messaging), and an AI model provider (Anthropic) for the AI agents and readers, only when your company uses them. They handle data only so that we can run the service.',
                'Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region. When you use an AI agent or reader, what it needs is processed on the provider\'s servers outside India. The provider states that it deletes this within 30 days, except content its safety systems flag, which can be kept longer. Pages and photos sent to a reader are sent as they are, after location and phone details inside the photo file are removed.',
                'Pages, photos and document text sent to an AI reader, and what the reader read from them, are part of the company\'s records. They stay with the company when one person\'s account is deleted, and are erased with the company.',
            ],
            'terms' => ['AI agents and readers can be wrong. What they prepare is saved as a draft and marked as one. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.'],
        ];

        $check = function (array $texts, string $state) {
            foreach (['hi', 'mr'] as $locale) {
                foreach ($texts as $view => $sentences) {
                    $this->withSession(['locale' => $locale]);
                    $page = $this->text("/{$view}");

                    foreach ($sentences as $english) {
                        $translated = __($english, [], $locale);
                        $this->assertNotSame($english, $translated, "{$locale}: {$english}");
                        $this->assertStringContainsString($translated, $page, "{$locale} {$view} {$state}");
                        $this->assertStringNotContainsString($english, $page, "{$locale} {$view} {$state}");
                    }
                }
            }

            // In English the same paragraphs are there as they were.
            $this->withSession(['locale' => 'en']);

            foreach ($texts as $view => $sentences) {
                foreach ($sentences as $english) {
                    $this->assertStringContainsString($english, $this->text("/{$view}"), "en {$view} {$state}");
                }
            }
        };

        $check($agents, 'before a reader exists');

        $this->release();
        $this->deployReader();
        $check($readers, 'with a reader');
        // The first paragraph is the same in both states.
        $check(['privacy' => [$agents['privacy'][0]]], 'with a reader');
    }

    public function test_text_saved_by_the_platform_admin_still_replaces_the_built_in_text(): void
    {
        $this->release();
        $this->deployReader();
        app(PlatformSettings::class)->set(['privacy_text' => '## Our policy', 'terms_text' => '## Our terms']);

        // An override is the admin's own text: it must be saved again to say what the built-in text now says.
        $this->get('/privacy')->assertOk()->assertSee('Our policy')->assertDontSee('Anthropic');
        $this->get('/terms')->assertOk()->assertSee('Our terms')->assertDontSee('AI agents and readers can be wrong');
    }
}
