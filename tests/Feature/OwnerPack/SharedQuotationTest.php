<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientQuotation;
use App\Models\Party;
use App\Services\Access\TeamAccessService;
use App\Services\Notify\TenantData;
use App\Services\OwnerPack\QuotationShareService;
use App\Services\Seo\PageMeta;
use App\Support\PlatformSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Seo\ReadsPageHead;
use Tests\TestCase;

/**
 * The page a client opens from a link, with no account. It must open only for a live link to an
 * issued quotation, show that quotation and nothing of any other project or company, and stay out
 * of search engines.
 */
class SharedQuotationTest extends TestCase
{
    use BuildsOwnerPack, ReadsPageHead, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->house();
    }

    private function shares(): QuotationShareService
    {
        return app(QuotationShareService::class);
    }

    /** The page for a link that does not open: the same for every reason, and naming nothing. */
    private function assertInactive(TestResponse $response): void
    {
        $response->assertStatus(410)->assertSee('This link is no longer active');
        $this->assertNothingOfOurs($response);
        $this->assertHidden($response);
    }

    private function assertNothingOfOurs(TestResponse $response): void
    {
        foreach (['Sahyadri Homes', 'Deshmukh', 'CQ-', 'Excavation', 'Brick masonry', '157', '146', '₹', 'On agreement', 'plinth'] as $ours) {
            $response->assertDontSee($ours, false);
        }
    }

    /** Kept out of search engines and caches, by the page and by the response. */
    private function assertHidden(TestResponse $response, bool $signedIn = false): void
    {
        $tags = $this->tags($response);

        $this->assertSame([PageMeta::HIDDEN], $tags['meta']['robots'] ?? []);
        $this->assertSame(['no-referrer'], $tags['meta']['referrer'] ?? []);
        $this->assertNull($tags['canonical']);
        $this->assertSame([], $tags['alternates']);
        $this->assertArrayNotHasKey('description', $tags['meta']);
        $this->assertArrayNotHasKey('og:title', $tags['meta']);
        $this->assertSame([], $this->structuredData($response));

        if (! $signedIn) {
            $this->assertNull($this->analyticsConfig($response), 'A visitor with no account is never counted.');
        }

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_signed_link_opens_the_quotation_without_signing_in(): void
    {
        [$quotation, $url] = $this->shared();
        $this->signOut();
        // Even with analytics switched on for the website and the app.
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF', 'ga4_include_app' => '1']);

        $this->assertStringStartsWith(url('/shared/quotations/'.$quotation->id.'/'.$quotation->share_token).'?signature=', $url);
        $this->assertSame(40, strlen($quotation->share_token));
        $this->assertSame(today()->addDays(14)->toDateString(), $quotation->share_expires_at->toDateString());

        $page = $this->get($url)->assertOk();
        $this->assertGuest();

        $page->assertSee('Sahyadri Homes')->assertSee('Deshmukh Bungalow')->assertSee('Anil Deshmukh')->assertSee($quotation->number)->assertSee('Revision 1')
            ->assertSee('Excavation for foundation in all soils')->assertSee('Brick masonry in cement mortar')->assertSee('Site clearance and levelling')
            ->assertSee('12.500')->assertSee('₹4,200.10')->assertSee('₹52,501.25')->assertSee('₹76,000.00')->assertSee('₹18,333.33')
            ->assertSee('₹1,46,834.58')->assertSee('GST at 7.5%')->assertSee('₹11,012.59')->assertSee('₹1,57,847.17')
            ->assertSee('In words: Rupees One Lakh Fifty Seven Thousand Eight Hundred Forty Seven and Seventeen Paise Only.')
            ->assertSee('On agreement: 30% of the total, which is ₹47,354.15.')
            ->assertSee('Condition for this stage: Plinth beam cast and cured')
            ->assertSee('The stages add up to ₹1,57,847.17.')
            ->assertSee('Thank you for asking us to quote for your work.')
            ->assertSee('Shared by Sahyadri Homes.')
            ->assertDontSee('Replaced by revision');

        // Nothing of the app around it: no menu, no way in, no marks from the review screen, no form.
        $page->assertDontSee('<mark', false)->assertDontSee('number_ticks', false)->assertDontSee('<form', false)->assertDontSee('Sign out')->assertDontSee($this->manager->name)->assertDontSee($this->manager->email);
        $this->assertSame([], $this->bodyLinks($page), 'The page links nowhere.');
        $this->assertSame('en', $this->tags($page)['lang']);
        $this->assertHidden($page);
    }

    public function test_an_address_that_was_not_signed_here_is_refused(): void
    {
        [$quotation, $url] = $this->shared();
        $this->signOut();

        $plain = route('quotations.shared', ['id' => $quotation->id, 'token' => $quotation->share_token]);
        $this->get($plain)->assertForbidden();
        $this->get($plain.'?signature='.str_repeat('a', 64))->assertForbidden();

        // The signature of one address does not open another: another id, another token, an added word.
        $signature = Str::after($url, '?');
        $other = $this->issued($this->draft(['client_name' => 'Second Client']));
        $this->get(url('/shared/quotations/'.$other->id.'/'.$quotation->share_token).'?'.$signature)->assertForbidden();
        $this->get(url('/shared/quotations/'.$quotation->id.'/'.Str::random(40)).'?'.$signature)->assertForbidden();
        $this->get($url.'&revision=2')->assertForbidden();

        foreach ([$plain, $plain.'?signature='.str_repeat('a', 64)] as $address) {
            $this->assertNothingOfOurs($this->get($address));
        }

        // A token that is not forty letters and digits is no address at all.
        $this->get(url('/shared/quotations/'.$quotation->id.'/short'))->assertNotFound();
        $this->get(url('/shared/quotations/abc/'.$quotation->share_token))->assertNotFound();
    }

    public function test_a_link_stops_opening_after_fourteen_days(): void
    {
        [, $url] = $this->shared();
        $this->signOut();

        $this->travel(13)->days();
        $this->get($url)->assertOk();

        $this->travel(2)->days();
        $this->assertInactive($this->get($url));
    }

    public function test_a_withdrawn_link_stops_opening_at_once_and_a_new_link_does_not_revive_it(): void
    {
        [$quotation, $old] = $this->shared();
        $this->get($old)->assertOk();

        $this->shares()->revoke($quotation, $this->manager);
        $this->assertNull($this->shares()->url($quotation->fresh()));
        $this->assertInactive($this->get($old));

        // Withdrawing twice is harmless.
        $this->shares()->revoke($quotation, $this->manager);

        [, $new] = $this->shared($quotation->fresh());
        $this->assertNotSame($old, $new);
        $this->get($new)->assertOk();
        $this->assertInactive($this->get($old));
    }

    public function test_a_new_link_replaces_the_earlier_one(): void
    {
        [$quotation, $first] = $this->shared();
        [$quotation, $second] = $this->shared($quotation);

        $this->assertNotSame($first, $second);
        $this->get($second)->assertOk();
        $this->assertInactive($this->get($first));
    }

    public function test_a_properly_signed_address_with_another_token_or_no_row_shows_the_same_plain_page(): void
    {
        [$quotation] = $this->shared();
        $never = $this->issued($this->draft(['client_name' => 'Never Shared']));

        foreach ([
            ['id' => $quotation->id, 'token' => Str::random(40)],
            ['id' => $never->id, 'token' => Str::random(40)],
            ['id' => 999999, 'token' => $quotation->share_token],
            ['id' => '99999999999999999999', 'token' => $quotation->share_token],
        ] as $parameters) {
            $this->assertInactive($this->get(URL::signedRoute('quotations.shared', $parameters)));
        }
    }

    public function test_only_an_issued_quotation_can_be_shared_or_opened(): void
    {
        $draft = $this->withStages($this->draft());

        try {
            $this->shares()->share($draft, $this->manager);
            $this->fail('A draft was shared.');
        } catch (ValidationException $e) {
            $this->assertSame(['share'], array_keys($e->errors()));
        }

        $this->assertNull($draft->fresh()->share_token);

        // Even with a token put on a draft by hand, the page does not open.
        $token = Str::random(40);
        DB::table('client_quotations')->where('id', $draft->id)->update(['share_token' => $token, 'share_expires_at' => now()->addDays(5)]);
        $this->assertInactive($this->get(URL::signedRoute('quotations.shared', ['id' => $draft->id, 'token' => $token])));

        $this->actingAs($this->manager)->post(route('quotations.share', $draft))->assertSessionHasErrors('share');
    }

    public function test_sharing_works_the_figures_out_again_and_refuses_a_quotation_whose_rows_changed(): void
    {
        $issued = $this->issued();
        DB::table('client_quotation_items')->where('quotation_id', $issued->id)->where('code', 'A-01')->update(['qty' => '13.500']);

        try {
            $this->shares()->share($issued, $this->manager);
            $this->fail('A quotation whose rows no longer give the issued figures was shared.');
        } catch (ValidationException $e) {
            $this->assertSame(['facts'], array_keys($e->errors()));
        }

        $this->assertNull($issued->fresh()->share_token);
    }

    public function test_a_page_whose_kept_facts_were_altered_does_not_open(): void
    {
        [$quotation, $url] = $this->shared();
        $facts = $quotation->facts;

        foreach ($facts as $index => $fact) {
            if ($fact['key'] === 'total') {
                $facts[$index]['value'] = '57847.17';
            }
        }

        DB::table('client_quotations')->where('id', $quotation->id)->update(['facts' => json_encode($facts)]);

        $this->assertInactive($this->get($url));
    }

    public function test_an_old_revision_says_it_was_replaced_and_shows_no_payment_schedule(): void
    {
        [$first, $oldUrl] = $this->shared();
        $revision = $this->quotations()->revise($first, $this->manager);

        // While the next revision is only a draft, the issued one still stands as it was shared.
        $this->get($oldUrl)->assertOk()->assertDontSee('Replaced by revision')->assertSee('On agreement: 30% of the total, which is ₹47,354.15.');

        $this->quotations()->update($revision, ['client_name' => 'Anil Deshmukh', 'language' => 'en', 'gst_pct' => '2'], $this->manager);
        [$second, $newUrl] = $this->shared($this->issued($revision));

        $old = $this->get($oldUrl)->assertOk()
            ->assertSee('Replaced by revision 2.')
            ->assertSee('Revision 1')
            ->assertSee('Excavation for foundation in all soils')
            ->assertSee('₹1,57,847.17');

        foreach (['Payment stages', 'On agreement', 'On completion of plinth', 'On handing over', 'Plinth beam cast and cured', '₹47,354.15', '₹52,615.67', '₹57,877.35', 'Number of payment stages', 'The stages add up to'] as $schedule) {
            $old->assertDontSee($schedule);
        }

        // It shows nothing of the revision that replaced it but its number.
        $old->assertDontSee('₹1,49,771.27')->assertDontSee('₹2,936.69')->assertDontSee($second->share_token);
        $this->assertHidden($old);

        $this->get($newUrl)->assertOk()->assertSee('Revision 2')->assertDontSee('Replaced by revision')->assertSee('On agreement: 30% of the total, which is ₹44,931.38.')->assertSee('₹1,49,771.27');
    }

    public function test_a_visitor_signed_in_to_another_company_sees_the_whole_quotation_and_nothing_of_their_own(): void
    {
        [$quotation, $url] = $this->shared();

        // The client runs a firm of their own on the same product, with a quotation of their own.
        $theirs = $this->tenant('Konkan Builders', 'business');
        $client = $this->member($theirs, 'owner');
        $theirProject = $this->makeProject($theirs, ['name' => 'Their Own Site']);
        $theirLine = $this->line($theirProject, 'T-01', 'Their own private line', 'nos', '2.000', '654321.00');
        $this->actingAs($client);
        $this->quotations()->create($theirProject, ['client_name' => 'Their Own Client', 'language' => 'en', 'boq_item_ids' => [$theirLine->id]], $client);
        // Analytics is on for app screens, and the visitor is signed in.
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF', 'ga4_include_app' => '1']);

        $page = $this->get($url)->assertOk();

        // Every line and every stage is there: the tenant scope of the signed-in visitor hid none of them.
        $page->assertSee('Sahyadri Homes')->assertSee('Deshmukh Bungalow')
            ->assertSee('Excavation for foundation in all soils')->assertSee('Brick masonry in cement mortar')->assertSee('Site clearance and levelling')
            ->assertSee('₹1,57,847.17')
            ->assertSee('On agreement')->assertSee('On completion of plinth')->assertSee('On handing over')->assertSee('₹57,877.35');
        $this->assertSame(3, substr_count($page->getContent(), '<td class="num text-xs">'), 'Three lines are printed.');

        // And nothing of the visitor, their company or their records.
        foreach (['Konkan Builders', 'Their Own Site', 'Their own private line', 'Their Own Client', '654321', '6,54,321', '13,08,642', $client->name, $client->email] as $theirOwn) {
            $page->assertDontSee($theirOwn, false);
        }

        $this->assertHidden($page, signedIn: true);
        $this->assertAuthenticatedAs($client);

        // For a signed-in visitor the platform's own count of app screens may run. It is handed the
        // pattern of the address and the route's name: no id, no token, no name and no amount.
        $handed = $this->analyticsConfig($page);
        $this->assertSame([url('/shared/quotations/:id/:token'), 'quotations.shared', []], [$handed['location'], $handed['title'], $handed['events']]);

        foreach ([$quotation->share_token, $quotation->number, 'Sahyadri', 'Deshmukh', 'Konkan', '157847', '1,57,847', '₹', $client->email] as $private) {
            $this->assertStringNotContainsString($private, json_encode($handed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // The link does not let them into the app: the screens behind it stay closed to another company.
        $this->get(route('quotations.show', $quotation))->assertNotFound();
        $this->get(route('quotations.index', $this->project))->assertNotFound();
    }

    public function test_the_page_shows_nothing_of_another_project_or_another_quotation_of_the_same_company(): void
    {
        // A second quotation in the same project, a quotation in another project, and one of another company.
        $sameProject = $this->issued($this->draft(['client_name' => 'Second Client', 'boq_item_ids' => [$this->line($this->project, 'S-01', 'Compound wall for the neighbour', 'rmt', '40.000', '2222.00', 5)->id]]));
        $this->stage($extraStages = $this->draft(['client_name' => 'Third Client']), 'Hidden stage of a draft', null, '4321');

        $otherProject = $this->makeProject($this->organization, ['name' => 'Kulkarni Row House']);
        $otherLine = $this->line($otherProject, 'K-01', 'Terrace waterproofing for Kulkarni', 'sqm', '55.000', '7777.00');
        $inOtherProject = $this->issued($this->quotations()->create($otherProject, ['client_name' => 'Kulkarni', 'language' => 'en', 'boq_item_ids' => [$otherLine->id]], $this->manager));
        Party::create(['organization_id' => $this->organization->id, 'type' => 'client', 'name' => 'Private Party Name']);

        [$quotation, $url] = $this->shared();
        $this->shares()->share($sameProject, $this->manager);
        $this->shares()->share($inOtherProject, $this->manager);
        $this->signOut();

        $page = $this->get($url)->assertOk()->assertSee('Anil Deshmukh')->assertSee('₹1,57,847.17');

        foreach (['Second Client', 'Third Client', 'Compound wall', '2,222.00', '88,880', 'Hidden stage', '4,321', 'Kulkarni', 'Terrace waterproofing', '7,777.00', '4,27,735', 'Private Party Name',
            $sameProject->number, $inOtherProject->number, $sameProject->fresh()->share_token, $inOtherProject->fresh()->share_token, $this->owner->name, $this->manager->email] as $other) {
            $page->assertDontSee($other, false);
        }

        // The line added to the BOQ for the neighbour is in the BOQ of this project, but not in this quotation.
        $this->assertSame(3, substr_count($page->getContent(), '<td class="num text-xs">'));
    }

    public function test_someone_of_the_same_company_limited_to_another_project_opens_the_link_like_anyone_else(): void
    {
        [, $url] = $this->shared();
        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);
        $limited = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);

        $this->actingAs($limited->refresh())->get($url)->assertOk()->assertSee('Brick masonry in cement mortar')->assertSee('On handing over');
    }

    /**
     * The link is for people with no account. A subcontractor's portal login never reaches the page,
     * because the gate that runs for every signed-in request turns it round first: it is sent to its
     * own start page and shown nothing. Signed out, the same link opens for it. (There is no offline
     * plan any more, so its gate is gone: a member of a company left on it is on the free plan.)
     */
    public function test_a_portal_login_is_turned_round_by_the_existing_gate_and_shown_nothing(): void
    {
        [, $url] = $this->shared();
        $elsewhere = $this->tenant('Konkan Builders', 'business');

        $portal = $this->actingAs($this->member($elsewhere, 'contractor'))->get($url);
        $portal->assertRedirect(route('contractor-portal.home'));
        $this->assertNothingOfOurs($portal);

        $this->signOut();
        $this->get($url)->assertOk()->assertSee('Anil Deshmukh');
    }

    public function test_the_page_closes_when_the_plan_no_longer_has_the_pack_or_the_project_is_gone(): void
    {
        [, $url] = $this->shared();
        $this->signOut();
        $this->get($url)->assertOk();

        $this->organization->update(['plan' => 'free']);
        $this->assertInactive($this->get($url));

        $this->organization->update(['plan' => 'contractor']);
        $this->get($url)->assertOk();

        $this->project->delete();
        $this->assertInactive($this->get($url));
    }

    public function test_opening_shared_pages_never_uses_up_the_sign_in_tries_of_that_address(): void
    {
        [, $url] = $this->shared();
        $this->signOut();
        $limiter = app(RateLimiter::class);
        $plain = sha1('|127.0.0.1');

        for ($visit = 1; $visit <= 12; $visit++) {
            $this->get($url)->assertOk();
        }

        // Twelve visits were counted on the page's own counter and none on the one /login reads.
        $this->assertSame(12, $limiter->attempts('owner-share'.$plain));
        $this->assertSame(0, $limiter->attempts($plain));
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors();

        // The other way round: with the sign-in tries of this address used up, the shared page still opens.
        for ($try = 1; $try <= 12; $try++) {
            $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
        }

        $this->assertGreaterThanOrEqual(10, $limiter->attempts($plain));
        $this->get($url)->assertOk();

        // The page has a limit of its own.
        for ($visit = 14; $visit <= 30; $visit++) {
            $this->get($url)->assertOk();
        }

        $this->assertNotSame(200, $this->get($url)->getStatusCode());
        $this->assertNothingOfOurs($this->get($url));
    }

    public function test_the_shared_route_leaves_the_sign_in_group_and_keeps_its_guards(): void
    {
        $route = Route::getRoutes()->getByName('quotations.shared');
        $middleware = $route->gatherMiddleware();

        $this->assertSame('shared/quotations/{id}/{token}', $route->uri());
        $this->assertContains('signed', $middleware);
        $this->assertContains('throttle:30,1,owner-share', $middleware);
        // The file is loaded inside the sign-in group, so the route names the two it leaves.
        $this->assertEqualsCanonicalizing(['auth', 'active'], $route->excludedMiddleware());
        $this->assertSame([], array_values(array_filter($middleware, fn ($name) => is_string($name) && (str_starts_with($name, 'can:') || str_starts_with($name, 'feature:')))));
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_the_page_is_written_in_the_language_of_the_quotation(): void
    {
        $expected = [
            'hi' => ['कोटेशन', 'संशोधन 1', 'किसके लिए तैयार', 'विवरण', 'मात्रा', 'भुगतान के चरण', 'इस कोटेशन का कुल योग ₹1,57,847.17 है।', 'On agreement: कुल का 30%, यानी ₹47,354.15।', 'द्वारा साझा किया गया'],
            'mr' => ['कोटेशन', 'सुधारणा 1', 'कोणासाठी तयार', 'वर्णन', 'प्रमाण', 'पेमेंटचे टप्पे', 'या कोटेशनची एकूण रक्कम ₹1,57,847.17 आहे.', 'On agreement: एकूण रकमेच्या 30%, म्हणजे ₹47,354.15.', 'यांनी शेअर केले'],
        ];

        foreach ($expected as $language => $texts) {
            [, $url] = $this->shared($this->issued($this->withStages($this->draft(['language' => $language]))));
            $this->signOut();
            $this->flushSession();
            app()->setLocale('en');

            $page = $this->get($url)->assertOk();

            foreach ($texts as $text) {
                $page->assertSee($text);
            }

            $page->assertDontSee('Prepared for')->assertDontSee('Payment stages')->assertDontSee('In words');
            $this->assertSame($language, $this->tags($page)['lang']);
            $this->assertDoesNotMatchRegularExpression('/[\x{0966}-\x{096F}]/u', $page->getContent(), 'Digits stay ASCII.');
            $this->assertHidden($page);
        }
    }

    public function test_the_whatsapp_message_names_the_quotation_and_carries_no_amount(): void
    {
        [$quotation] = $this->shared();
        $text = $this->shares()->whatsappText($quotation, 'Sahyadri Homes');

        $this->assertStringContainsString('Sahyadri Homes · Quotation '.$quotation->number, $text);
        $this->assertStringContainsString($this->shares()->url($quotation), $text);
        $this->assertStringNotContainsString('₹', $text);
        $this->assertStringNotContainsString('157', str_replace(',', '', Str::before($text, 'http')));
        $this->assertStringStartsWith('https://wa.me/?text=', $this->shares()->whatsappUrl($quotation, 'Sahyadri Homes'));

        $this->shares()->revoke($quotation, $this->manager);
        $this->assertNull($this->shares()->whatsappText($quotation->fresh(), 'Sahyadri Homes'));
    }

    public function test_the_token_is_kept_out_of_the_company_data_export(): void
    {
        [$quotation] = $this->shared();
        $data = app(TenantData::class);
        $columns = $data->tables()['client_quotations'];

        $this->assertContains('share_token', $columns);
        $this->assertNotContains('share_token', $data->exportableColumns($columns));
        $this->assertSame(ClientQuotation::ISSUED, $quotation->status);
    }
}
