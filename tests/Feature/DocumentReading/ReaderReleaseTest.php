<?php

namespace Tests\Feature\DocumentReading;

use App\Http\Middleware\RequireAiReader;
use App\Models\AiDocumentSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ReaderSettings;
use App\Services\Reports\BoqPdfReader;
use App\Services\Website\AiOffer;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * One test of whether the readers are on (AiSpend::readersOn): released by the platform admin with the
 * provider's monthly cap on record, the AI model connected, a reader deployed and, for a company, a plan
 * that has the AI features and the reader's module. Every place that offers or allows a reader asks it,
 * so taking away any one part turns every place off together, and putting it back turns them on together.
 */
class ReaderReleaseTest extends PageReadingTestCase
{
    /** Stands in for a route that sends a page to a reader. */
    private const GATED = '/_release-test/read';

    private const READER_SENTENCE = 'Pages and photos sent to a reader are sent as they are, after location and phone details inside the photo file are removed.';

    private const TERMS_READERS = 'AI agents, AI readers and the other AI features can be wrong.';

    private const PRICING_READERS = 'AI agents and readers are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        // The BOQ reader's module brings the routes a person reaches it by; these stand in for them.
        Route::get('/_release-test/boq-scans/create', fn () => '')->name('boq-scans.create');
        Route::middleware(['web', 'auth', 'active', RequireAiReader::class.':consent'])->post(self::GATED, fn () => response('sent'));
        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * What each place says, for this company: true where it offers or allows a reader.
     *
     * @return array<string, bool>
     */
    private function places(Organization $organization): array
    {
        $owner = $this->member($organization, 'owner');
        Auth::forgetGuards();

        $public = [
            'website' => app(AiOffer::class)->readersOn(),
            'ai page card' => str_contains((string) $this->get('/ai')->assertOk()->getContent(), 'Scanned BOQ reader'),
            'pricing line' => str_contains((string) $this->get('/pricing')->assertOk()->getContent(), self::PRICING_READERS),
            'privacy policy' => str_contains(html_entity_decode((string) $this->get('/privacy')->assertOk()->getContent(), ENT_QUOTES), self::READER_SENTENCE),
            'terms' => str_contains((string) $this->get('/terms')->assertOk()->getContent(), self::TERMS_READERS),
            'public consent test' => app(ReaderSettings::class)->offered(),
        ];

        $company = [
            'consent switch' => app(ReaderSettings::class)->offered($organization),
            'settings page' => (function () use ($owner) {
                $page = $this->actingAs($owner)->get('/settings/ai-documents');

                // The page offers the readers with the notice to read; without them it says there is no reader.
                return $page->getStatusCode() === 200 && str_contains((string) $page->getContent(), 'Read this before you switch the readers on.') && ! str_contains((string) $page->getContent(), 'No reader is');
            })(),
            ...$this->menu($organization, $owner),
            'scan offer' => (function () use ($owner) {
                $this->actingAs($owner);

                return BoqPdfReader::hasScanReader();
            })(),
            'gate' => $this->actingAs($owner)->from('/dashboard')->post(self::GATED)->getContent() === 'sent',
        ];

        Sanctum::actingAs($owner);
        $company['phone status'] = (bool) $this->getJson('/api/v1/document-reading/status')->assertOk()->json('released');
        Auth::forgetGuards();

        return [...$public, ...$company];
    }

    /**
     * Whether the menu offers the readers: as it shows to a company that has not agreed. A company that has agreed
     * keeps its entry while the readers are off, to switch them off (test_a_company_that_agreed_can_still_switch_the_readers_off).
     *
     * @return array{menu: bool, 'menu on a page': bool}
     */
    private function menu(Organization $organization, User $owner): array
    {
        $agreed = (bool) AiDocumentSettings::for($organization)->enabled;
        $this->consent($organization, false);
        $organization->refresh();

        $menu = [
            'menu' => in_array('ai-documents.edit', array_column(Modules::nav('company', $organization), 'route'), true),
            // A page loads the person and the company afresh.
            'menu on a page' => str_contains((string) $this->actingAs($owner->fresh())->get('/dashboard')->assertOk()->getContent(), route('ai-documents.edit')),
        ];

        $this->consent($organization, $agreed);
        $organization->refresh();

        return $menu;
    }

    private function assertAll(bool $on, Organization $organization, string $state): void
    {
        $this->assertSame($on, app(AiSpend::class)->readersOn($organization), $state);

        foreach ($this->places($organization) as $place => $says) {
            $this->assertSame($on, $says, "{$state}: {$place}");
        }
    }

    public function test_every_place_turns_on_and_off_together_with_each_part_of_the_one_test(): void
    {
        $settings = app(PlatformSettings::class);
        // The company's consent stays given throughout, so the gate refuses only for the release test.
        $this->consent($this->organization);

        $this->assertAll(true, $this->organization, 'everything on');

        $parts = [
            'not released' => fn () => $settings->set([AiOffer::RELEASED => '0']),
            'no monthly cap on record' => fn () => $settings->set(['ai_tier_monthly_cap_usd' => null]),
            'no key saved' => fn () => $settings->set(['anthropic_api_key' => null]),
            'no reader deployed' => fn () => config(['modules.reader_stand_ins.read_kinds' => []]),
        ];

        foreach ($parts as $state => $takeAway) {
            $takeAway();
            $this->assertAll(false, $this->organization, $state);

            // Put back as it was.
            $this->release();
            $this->connect();
            config(['modules.reader_stand_ins.read_kinds' => [Support\FakeKind::class, Support\FakeTextKind::class, Support\FakeSlipKind::class]]);
            $this->assertAll(true, $this->organization, "{$state}, put back");
        }
    }

    public function test_a_company_whose_plan_lacks_the_readers_sees_none_of_them_while_the_website_still_names_them(): void
    {
        $small = $this->tenant('Tapi Builders', 'contractor');
        $places = $this->places($small);

        foreach (['website', 'ai page card', 'pricing line', 'privacy policy', 'terms', 'public consent test'] as $public) {
            $this->assertTrue($places[$public], $public);
        }

        $this->assertFalse(app(AiSpend::class)->readersOn($small));
        $this->assertFalse($places['consent switch']);
        $this->assertFalse($places['menu']);
        $this->assertFalse($places['scan offer']);
        $this->assertFalse($places['phone status']);

        // A plan with the AI features but without the module a reader belongs to is not offered that reader.
        config(['modules.reader_stand_ins.read_kinds' => [Support\FakeKind::class]]);
        config(['modules.document_reading.kinds.boq.feature' => 'feature_no_plan_has']);
        $this->assertFalse(app(AiSpend::class)->readersOn($this->organization));
        $this->assertFalse(app(AiSpend::class)->readersOn($this->organization, 'boq'));
        $this->assertTrue(app(AiSpend::class)->readersOn(null, 'boq'), 'The website has no company and no plan.');
    }

    /**
     * Whichever reader is deployed, alone, the website's Readers group shows with that reader's card, and the
     * pricing line, the privacy policy and the consent switch speak of readers with it. Every kind the readers
     * module knows has a card. The voice endpoint is a reader only once its acceptance test is recorded.
     */
    public function test_each_reader_alone_turns_the_website_readers_on_with_every_other_place(): void
    {
        $settings = app(PlatformSettings::class);
        $this->consent($this->organization);

        $this->assertEqualsCanonicalizing([...array_keys(config('modules.document_reading.kinds')), AiSpend::VOICE], app(AiOffer::class)->readerKinds(), 'Every reader kind, and voice, has a card on the website.');

        $voice = function (bool $accepted) use ($settings) {
            Route::post('/_release-test/voice', fn () => '')->name(AiOffer::VOICE_ROUTE);
            Route::getRoutes()->refreshNameLookups();
            $settings->set([AiOffer::VOICE_ACCEPTED => $accepted ? '1' : '0']);
        };

        $readers = [
            'Scanned BOQ reader' => fn () => config(['modules.reader_stand_ins.read_kinds' => [Support\FakeKind::class]]),
            'Challan and bill photo' => fn () => config(['modules.reader_stand_ins.read_kinds' => [Support\FakeSlipKind::class]]),
            'Tender reader' => fn () => config(['modules.reader_stand_ins.read_kinds' => [Support\FakeTextKind::class]]),
            'Voice DPR' => fn () => $voice(true),
        ];
        // A supplier's bill is read on the same card as a challan.
        $bill = fn () => config(['modules.reader_stand_ins.read_kinds' => [Support\FakeBillKind::class]]);

        $says = function (string $state, bool $on, ?string $card) {
            $ai = (string) $this->get('/ai')->assertOk()->getContent();

            $this->assertSame($on, app(AiSpend::class)->readersOn(), $state);
            $this->assertSame($on, str_contains($ai, '<h2 class="text-[30px]">Readers</h2>'), "{$state}: the AI page's Readers group");
            $this->assertSame($on, app(AiOffer::class)->readers() !== [], "{$state}: a reader card");
            $this->assertSame($on, str_contains((string) $this->get('/pricing')->assertOk()->getContent(), self::PRICING_READERS), "{$state}: the pricing line");
            $this->assertSame($on, str_contains(html_entity_decode((string) $this->get('/privacy')->assertOk()->getContent(), ENT_QUOTES), self::READER_SENTENCE), "{$state}: the privacy policy");
            $this->assertSame($on, app(ReaderSettings::class)->offered($this->organization), "{$state}: the consent switch");

            if ($card !== null) {
                $this->assertStringContainsString($card, $ai, $state);
            }
        };

        foreach ([...$readers, 'Challan and bill photo, for a bill' => $bill] as $state => $deploy) {
            config(['modules.reader_stand_ins.read_kinds' => []]);
            $settings->set([AiOffer::VOICE_ACCEPTED => '0']);
            $says("{$state}: nothing deployed", false, null);

            $deploy();
            $says($state, true, explode(',', $state)[0]);
        }

        // The voice endpoint before its acceptance test: no reader anywhere, and no card.
        config(['modules.reader_stand_ins.read_kinds' => []]);
        $voice(false);
        $says('voice not accepted', false, null);
    }

    public function test_the_menu_entry_asks_no_query_on_a_page(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $fresh = $this->tenant('Godavari Constructions');

        foreach (['1', '0'] as $released) {
            foreach (['a company that agreed' => $this->organization, 'one that did not' => $fresh] as $who => $organization) {
                app(PlatformSettings::class)->set([AiOffer::RELEASED => $released]);
                // The settings are read once for the request, as every page reads them, and the company was loaded with
                // the signed-in person. The menu adds nothing to that, the company's agreement included.
                app(PlatformSettings::class)->get(AiOffer::RELEASED);
                $organization->refresh();
                $queries = 0;

                $shown = in_array('ai-documents.edit', array_column(Modules::nav('company', $organization), 'route'), true);

                $this->assertSame($released === '1' || $organization->is($this->organization), $shown, "released {$released}, {$who}");
                $this->assertSame(0, $queries, "released {$released}, {$who}");
            }
        }
    }

    public function test_a_company_that_agreed_can_still_switch_the_readers_off(): void
    {
        $settings = app(PlatformSettings::class);
        $owner = $this->member($this->organization, 'owner');
        $menu = fn () => in_array('ai-documents.edit', array_column(Modules::nav('company', $this->organization->refresh()), 'route'), true);
        $states = [
            'not released' => fn () => $settings->set([AiOffer::RELEASED => '0']),
            'no monthly cap on record' => fn () => $settings->set(['ai_tier_monthly_cap_usd' => null]),
            'no key saved' => fn () => $settings->set(['anthropic_api_key' => null]),
            'no reader deployed' => fn () => config(['modules.reader_stand_ins.read_kinds' => []]),
        ];

        foreach ($states as $state => $takeAway) {
            $this->release();
            $this->connect();
            config(['modules.reader_stand_ins.read_kinds' => [Support\FakeKind::class, Support\FakeTextKind::class, Support\FakeSlipKind::class]]);
            $this->consent($this->organization);
            $takeAway();
            $this->assertFalse(app(AiSpend::class)->readersOn($this->organization), $state);

            // Nothing is offered or sent, but the agreement on record can be withdrawn from the menu's page.
            $this->assertTrue($menu(), "{$state}: the menu keeps its entry");
            $this->assertFalse(app(ReaderSettings::class)->offered($this->organization), $state);
            $this->actingAs($owner)->get('/settings/ai-documents')->assertOk()
                ->assertSee('No reader is available for your company right now, so no reader sends anything to the AI service.')
                ->assertSee('Switch the readers off')
                ->assertDontSee('No reader is switched on yet.')
                ->assertDontSee('name="accept"', false);

            $this->actingAs($owner)->post('/settings/ai-documents', ['action' => 'off'])->assertRedirect('/settings/ai-documents');
            $this->assertFalse(app(ReaderSettings::class)->consent($this->organization)['enabled'], $state);

            // Withdrawn, the company sees no entry and the page says there is no reader, as one that never agreed.
            $this->assertFalse($menu(), "{$state}: withdrawn");
            $this->actingAs($owner)->get('/settings/ai-documents')->assertOk()->assertSee('No reader is switched on yet.')->assertDontSee('Switch the readers off');
        }
    }
}
