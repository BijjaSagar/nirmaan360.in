<?php

namespace Tests\Feature\OwnerPack;

use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\OwnerUpdate;
use App\Services\Access\TeamAccessService;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\TenantData;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Services\OwnerPack\SharedPhoto;
use App\Services\Seo\PageMeta;
use App\Support\PlatformSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DocumentReading\BuildsPictures;
use Tests\Feature\Seo\ReadsPageHead;
use Tests\TestCase;

/**
 * The weekly update a client opens from a link, with no account, and its photos. It must open only
 * for a live link to an update a named person approved, show that update and nothing of any other
 * project or company, serve only the photos the update names, and stay out of search engines.
 */
class SharedUpdateTest extends TestCase
{
    use BuildsOwnerUpdates, BuildsPictures, ReadsPageHead, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->site();
    }

    private function shares(): OwnerUpdateShareService
    {
        return app(OwnerUpdateShareService::class);
    }

    /**
     * The week's update with two photos and the payment position, approved and shared.
     *
     * @return array{0: OwnerUpdate, 1: string, 2: list<DprPhoto>} The update, the address of its link, and the week's three photos.
     */
    private function sharedWeek(array $with = []): array
    {
        ['photos' => $photos] = $this->week();
        $quotation = $this->quotationWithAStageReached();

        [$update, $url] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate([
            'photo_ids' => [$photos[0]->id, $photos[2]->id],
            'show_money' => true,
            'quotation_id' => $quotation->id,
            'received_amount' => '40000',
            'next_week_text' => 'Plinth beam and backfilling.',
            ...$with,
        ])));

        return [$update, $url, $photos];
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
        foreach (['Sahyadri Homes', 'Deshmukh', 'CQ-', 'Excavation', 'Brick masonry', 'Footing F2', '7.375', '47,354', '₹', 'On agreement', 'Plinth beam', '/photos/'] as $ours) {
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

        $this->assertHiddenByHeaders($response);
    }

    private function assertHiddenByHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /** A photo may stay in the visitor's own browser for a few minutes, never in a shared cache, and stays out of search engines. */
    private function assertPhotoHeaders(TestResponse $response): void
    {
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('max-age=300', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringNotContainsString('s-maxage', $cache);
    }

    /** @return list<string> The address of every picture on the page, as written. */
    private function pictures(TestResponse $page): array
    {
        $sources = [];

        foreach ($this->document($page)->querySelectorAll('main img[src]') as $image) {
            $sources[] = (string) $image->getAttribute('src');
        }

        return $sources;
    }

    public function test_a_signed_link_opens_the_update_without_signing_in(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $this->signOut();
        // Even with analytics switched on for the website and the app.
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF', 'ga4_include_app' => '1']);

        $this->assertStringStartsWith(url('/shared/owner-updates/'.$update->id.'/'.$update->share_token).'?signature=', $url);
        $this->assertSame([40, OwnerUpdate::SHARED], [strlen($update->share_token), $update->status]);
        $this->assertSame(today()->addDays(14)->toDateString(), $update->share_expires_at->toDateString());
        $this->assertNotNull($update->shared_at);

        $page = $this->get($url)->assertOk();
        $this->assertGuest();

        $page->assertSee('Sahyadri Homes')->assertSee('Deshmukh Bungalow')->assertSee('Weekly update')
            ->assertSee('9 November 2026 to 15 November 2026')->assertSee('Approved on 14 November 2026')
            ->assertSee('Here is the update on the work at your site.')
            ->assertSee('This update covers the week from 9 November 2026 to 15 November 2026.')
            ->assertSee('Approved daily reports of that week: 3.')
            ->assertSee('Lines of work reported in them: 3. The table gives the quantity of each.')
            ->assertSee('A-01 Excavation for foundation in all soils')->assertSee('7.375')->assertSee('cum')
            ->assertSee('B-04 Brick masonry in cement mortar')->assertSee('22.500')
            ->assertSee('Curing of plinth beam')
            ->assertSee('The daily worker counts in these reports add up to 23.')
            ->assertSee('Photos with this update: 2. Each is shown with the date of the daily report it belongs to.')
            ->assertSee('Footing F2 before concreting')->assertSee('Brickwork on the east wall')->assertSee('12 November 2026')
            ->assertSee('reached on 10 November 2026.')
            ->assertSee('The stages reached so far add up to ₹47,354.15.')
            ->assertSee('Received so far, as entered by the contractor: ₹40,000.00.')
            ->assertSee('Due on the stages reached, after what was received: ₹7,354.15.')
            ->assertSee('Planned for next week, as written by the contractor:')
            ->assertSee('Plinth beam and backfilling.')
            ->assertSee('Please tell us if you would like anything explained.')
            ->assertSee('Shared by Sahyadri Homes.')
            ->assertDontSee('was replaced by revision');

        // No percentage is printed while nothing has been measured and approved, and no photo is called a measure of anything.
        $page->assertDontSee('%')->assertDontSee('Work measured and approved');

        // Nothing a report holds in free text, nothing of a report that is not approved, and nothing of the app around it.
        foreach (['mixer', 'steel came late', 'Internal remark', '55.555', '44.444', '77.777', '<mark', 'number_ticks', '<form', 'Sign out', $this->manager->name, $this->manager->email, $this->engineer->name] as $private) {
            $page->assertDontSee($private, false);
        }

        $this->assertSame([], $this->bodyLinks($page), 'The page links nowhere.');
        $this->assertSame('en', $this->tags($page)['lang']);
        $this->assertHidden($page);

        // The two photos the update names, each behind an address signed for that photo. The third photo of the week is not there.
        $pictures = $this->pictures($page);
        $this->assertCount(2, $pictures);
        $this->assertStringStartsWith(url('/shared/owner-updates/'.$update->id.'/'.$update->share_token.'/photos/'.$photos[0]->id).'?signature=', $pictures[0]);
        $this->assertStringStartsWith(url('/shared/owner-updates/'.$update->id.'/'.$update->share_token.'/photos/'.$photos[2]->id).'?signature=', $pictures[1]);
        $page->assertDontSee('/photos/'.$photos[1]->id.'?', false)->assertDontSee('org-'.$this->organization->id, false);

        foreach ($pictures as $address) {
            $picture = $this->get($address)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertPhotoHeaders($picture);
            $this->assertSame([120, 80, 'image/jpeg'], array_values(array_intersect_key(getimagesizefromstring($picture->getContent()), [0 => 0, 1 => 0, 'mime' => 0])));
        }

        $this->assertGuest();
    }

    public function test_the_page_states_the_basis_and_the_date_of_the_percentage_and_claims_nothing_for_the_photos(): void
    {
        ['photos' => [$footing]] = $this->week();
        // 40 sqm of brickwork measured and approved: 38,000.00 of a BOQ worth 1,46,834.58.
        $this->actingAs($this->owner);
        $this->approveEntry($this->draftEntry($this->project, $this->lines[1], $this->owner, ['nos' => '1', 'length' => '40', 'breadth' => null, 'depth' => null]), $this->owner, $this->owner);

        [, $url] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['photo_ids' => [$footing->id]])));
        $this->signOut();

        // Read two days later, the page still says which day the share was worked out on.
        $this->travel(2)->days();

        $page = $this->get($url)->assertOk()
            ->assertSee('Work measured and approved so far: 25.88% of the value of the bill of quantities, as on 14 November 2026.')
            ->assertSee('This share is worked out from the quantities measured and approved, at the rates of the bill of quantities. It is not judged from photos.')
            ->assertSee('Photos with this update: 1. Each is shown with the date of the daily report it belongs to.');

        // One percentage on the page, the one the code worked out, and no rupee: this update carries no money.
        $this->assertSame(1, substr_count($page->getContent(), '%'));
        $this->assertStringNotContainsString('₹', $page->getContent());

        foreach (['proof', 'guarantee', 'contract value'] as $claim) {
            $page->assertDontSee($claim);
        }
    }

    public function test_an_address_that_was_not_signed_here_is_refused_for_the_page_and_for_a_photo(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $photoUrl = $this->shares()->photoUrl($update, $photos[0]->id);
        $this->signOut();

        $plain = route('owner-updates.shared', ['id' => $update->id, 'token' => $update->share_token]);
        $plainPhoto = route('owner-updates.shared.photo', ['id' => $update->id, 'token' => $update->share_token, 'photo' => $photos[0]->id]);

        foreach ([$plain, $plain.'?signature='.str_repeat('a', 64), $plainPhoto, $plainPhoto.'?signature='.str_repeat('a', 64)] as $address) {
            $response = $this->get($address)->assertForbidden();
            $this->assertNothingOfOurs($response);
            $this->assertStringNotContainsString("\xFF\xD8", $response->getContent());
        }

        // The signature of one address opens no other: not the photo with the page's signature, not another photo, not another token.
        $pageSignature = Str::after($url, '?');
        $photoSignature = Str::after($photoUrl, '?');
        $this->get($plainPhoto.'?'.$pageSignature)->assertForbidden();
        $this->get(route('owner-updates.shared.photo', ['id' => $update->id, 'token' => $update->share_token, 'photo' => $photos[1]->id]).'?'.$photoSignature)->assertForbidden();
        $this->get(route('owner-updates.shared.photo', ['id' => $update->id, 'token' => Str::random(40), 'photo' => $photos[0]->id]).'?'.$photoSignature)->assertForbidden();
        $this->get(url('/shared/owner-updates/'.$update->id.'/'.Str::random(40)).'?'.$pageSignature)->assertForbidden();
        $this->get($url.'&week=2')->assertForbidden();

        // A token that is not forty letters and digits, or an id that is not a number, is no address at all.
        $this->get(url('/shared/owner-updates/'.$update->id.'/short'))->assertNotFound();
        $this->get(url('/shared/owner-updates/abc/'.$update->share_token))->assertNotFound();
        $this->get(url('/shared/owner-updates/'.$update->id.'/'.$update->share_token.'/photos/abc'))->assertNotFound();
    }

    public function test_a_photo_the_update_does_not_name_is_refused_even_on_a_properly_signed_address(): void
    {
        [$update, , $photos] = $this->sharedWeek();
        $signed = fn (int|string $photo, ?OwnerUpdate $of = null) => URL::signedRoute('owner-updates.shared.photo', ['id' => ($of ?? $update)->id, 'token' => ($of ?? $update)->share_token, 'photo' => $photo]);

        // The week's third photo was not picked. Others belong to a report in review, another project and another company.
        $inReview = $this->photo(Dpr::where('status', 'IN_REVIEW')->sole(), 'Not approved yet');
        $second = $this->makeProject($this->organization);
        $otherProject = $this->photo($this->report('2026-11-10', 'APPROVED', [], [], $second));
        $otherCompany = $this->photo($this->report('2026-11-10', 'APPROVED', [], [], $this->makeProject($this->tenant('Konkan Builders'))));
        $this->signOut();

        $this->get($signed($photos[0]->id))->assertOk();

        foreach ([$photos[1]->id, $inReview->id, $otherProject->id, $otherCompany->id, 999999, '99999999999999999999'] as $photo) {
            $response = $this->get($signed($photo))->assertNotFound();

            $this->assertSame('', $response->getContent());
            $this->assertHiddenByHeaders($response);
        }

        // Even a photo id written into the update's list underneath the app is not served, because the approved facts do not name it.
        DB::table('owner_updates')->where('id', $update->id)->update(['photo_ids' => json_encode([$photos[0]->id, $photos[1]->id, $otherProject->id, $otherCompany->id])]);

        foreach ([$photos[1]->id, $otherProject->id, $otherCompany->id] as $photo) {
            $this->get($signed($photo))->assertNotFound();
        }

        $this->get($signed($photos[0]->id))->assertOk();

        // A photo of another update is not reached with this update's token.
        [$other] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['photo_ids' => [$photos[1]->id]])));
        $this->get($signed($photos[1]->id, $other))->assertOk();
        $this->get($signed($photos[1]->id))->assertNotFound();
        $this->get($signed($photos[0]->id, $other))->assertNotFound();
    }

    public function test_photos_stop_with_the_link(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $photoUrl = $this->shares()->photoUrl($update, $photos[0]->id);

        $this->get($photoUrl)->assertOk();

        // Withdrawn: the page and the photo both show the plain page, at once.
        $this->shares()->revoke($update, $this->manager);
        $this->assertNull($this->shares()->url($update->fresh()));
        $this->assertInactive($this->get($url));
        $this->assertInactive($this->get($photoUrl));

        // Withdrawing twice is harmless. A new link does not revive the old addresses.
        $this->shares()->revoke($update, $this->manager);
        [$update, $new] = $this->sharedUpdate($update->fresh());
        $this->assertNotSame($url, $new);
        $this->get($new)->assertOk();
        $this->get($this->shares()->photoUrl($update, $photos[0]->id))->assertOk();
        $this->assertInactive($this->get($url));
        $this->assertInactive($this->get($photoUrl));

        // Fourteen days on, the new link has run out too.
        $this->travel(13)->days();
        $this->get($new)->assertOk();
        $this->travel(2)->days();
        $this->assertInactive($this->get($new));
        $this->assertInactive($this->get($this->shares()->photoUrl($update, $photos[0]->id)));
        $this->assertFalse($update->fresh()->isShared());
    }

    public function test_a_properly_signed_address_with_another_token_or_no_row_shows_the_same_plain_page(): void
    {
        [$update] = $this->sharedWeek();
        $never = $this->approvedUpdate($this->draftUpdate());

        foreach ([
            ['id' => $update->id, 'token' => Str::random(40)],
            ['id' => $never->id, 'token' => Str::random(40)],
            ['id' => 999999, 'token' => $update->share_token],
            ['id' => '99999999999999999999', 'token' => $update->share_token],
        ] as $parameters) {
            $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared', $parameters)));
            $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared.photo', [...$parameters, 'photo' => 1])));
        }

        // The token of a quotation's link opens no update, and the other way round.
        [$quotation] = $this->shared($this->issued($this->draft(['client_name' => 'Second Client'])));
        $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared', ['id' => $update->id, 'token' => $quotation->share_token])));
        $this->assertSame(410, $this->get(URL::signedRoute('quotations.shared', ['id' => $quotation->id, 'token' => $update->share_token]))->getStatusCode());
    }

    public function test_only_an_update_a_named_person_approved_and_shared_can_be_opened(): void
    {
        $this->week();
        $draft = $this->draftUpdate();

        try {
            $this->shares()->share($draft, $this->manager);
            $this->fail('A draft was shared.');
        } catch (ValidationException $e) {
            $this->assertSame(['share'], array_keys($e->errors()));
        }

        $this->assertSame([OwnerUpdate::DRAFT, null], [$draft->fresh()->status, $draft->fresh()->share_token]);
        $this->actingAs($this->manager)->post(route('owner-updates.share', $draft))->assertSessionHasErrors('share');

        // Even with a token, a date and the status put on a draft by hand, the page does not open: nobody approved it.
        $token = Str::random(40);
        DB::table('owner_updates')->where('id', $draft->id)->update(['share_token' => $token, 'share_expires_at' => now()->addDays(5)]);
        $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared', ['id' => $draft->id, 'token' => $token])));
        DB::table('owner_updates')->where('id', $draft->id)->update(['status' => OwnerUpdate::SHARED]);
        $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared', ['id' => $draft->id, 'token' => $token])));

        // An approved update with a token put on it by hand has not been shared by anyone either.
        $approved = $this->approvedUpdate();
        DB::table('owner_updates')->where('id', $approved->id)->update(['share_token' => $token, 'share_expires_at' => now()->addDays(5)]);
        $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared', ['id' => $approved->id, 'token' => $token])));

        // Sharing needs a person who may see money, whatever the update holds.
        try {
            $this->shares()->share($approved, $this->engineer);
            $this->fail('An engineer shared an update.');
        } catch (AuthorizationException) {
            $this->assertSame(OwnerUpdate::APPROVED, $approved->fresh()->status);
        }
    }

    public function test_sharing_reads_the_records_again_and_refuses_an_update_they_no_longer_match(): void
    {
        ['reports' => [$monday]] = $this->week();
        $approved = $this->approvedUpdate();

        // A quantity of an approved report is changed underneath the app after the approval.
        DB::table('dpr_work_items')->where('dpr_id', $monday->id)->where('qty', '4.250')->update(['qty' => '9.250']);

        try {
            $this->shares()->share($approved, $this->manager);
            $this->fail('An update whose records changed was shared.');
        } catch (ValidationException $e) {
            $this->assertSame(['facts'], array_keys($e->errors()));
            $this->assertStringContainsString('Prepare a new update for the week', $e->errors()['facts'][0]);
        }

        $this->assertSame([OwnerUpdate::APPROVED, null], [$approved->fresh()->status, $approved->fresh()->share_token]);

        // An update that was already shared keeps its open link as approved, but no new link can be made for it.
        DB::table('dpr_work_items')->where('dpr_id', $monday->id)->where('qty', '9.250')->update(['qty' => '4.250']);
        [$shared, $url] = $this->sharedUpdate($approved);
        DB::table('dpr_work_items')->where('dpr_id', $monday->id)->where('qty', '4.250')->update(['qty' => '9.250']);

        $this->get($url)->assertOk()->assertSee('7.375')->assertDontSee('12.375');

        try {
            $this->shares()->share($shared, $this->manager);
            $this->fail('A new link was made for an update whose records changed.');
        } catch (ValidationException $e) {
            $this->assertSame(['facts'], array_keys($e->errors()));
        }

        $this->assertSame($shared->share_token, $shared->fresh()->share_token);
        $this->get($url)->assertOk();
    }

    public function test_a_page_whose_kept_facts_or_text_were_altered_does_not_open(): void
    {
        [$update, $url] = $this->sharedWeek();
        $facts = $update->facts;

        foreach ($facts as $index => $fact) {
            if ($fact['key'] === 'pay_due') {
                $facts[$index]['value'] = '354.15';
            }
        }

        DB::table('owner_updates')->where('id', $update->id)->update(['facts' => json_encode($facts)]);
        $this->assertInactive($this->get($url));

        DB::table('owner_updates')->where('id', $update->id)->update(['facts' => json_encode($update->facts), 'body_rendered' => null]);
        $this->assertInactive($this->get($url));

        DB::table('owner_updates')->where('id', $update->id)->update(['body_rendered' => json_encode($update->body_rendered)]);
        $this->get($url)->assertOk();
    }

    public function test_the_page_shows_the_update_as_it_was_approved_whatever_happens_to_the_records_afterwards(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $this->signOut();

        // A report is approved late, a caption is retyped, a stage is paid and marked, the BOQ line is renamed.
        Dpr::withoutGlobalScopes()->where('status', 'IN_REVIEW')->update(['status' => 'APPROVED']);
        DB::table('dpr_photos')->where('id', $photos[0]->id)->update(['caption' => 'Caption typed after approval']);
        DB::table('boq_items')->where('id', $this->lines[0]->id)->update(['description' => 'Renamed after approval']);
        DB::table('client_payment_stages')->whereNull('reached_on')->update(['reached_on' => '2026-11-14']);

        $this->get($url)->assertOk()
            ->assertSee('Approved daily reports of that week: 3.')
            ->assertSee('A-01 Excavation for foundation in all soils')
            ->assertSee('Footing F2 before concreting')
            ->assertSee('The stages reached so far add up to ₹47,354.15.')
            ->assertDontSee('Caption typed after approval')
            ->assertDontSee('Renamed after approval')
            ->assertDontSee('55.555')
            ->assertDontSee('add up to ₹1,57,847.17');

        // A photo whose row is gone is simply no longer shown; the rest of the page stands.
        DB::table('dpr_photos')->where('id', $photos[0]->id)->delete();
        $page = $this->get($url)->assertOk()->assertSee('Brickwork on the east wall')->assertDontSee('Footing F2 before concreting');
        $this->assertCount(1, $this->pictures($page));
        $this->get(URL::signedRoute('owner-updates.shared.photo', ['id' => $update->id, 'token' => $update->share_token, 'photo' => $photos[0]->id]))->assertNotFound();
    }

    public function test_once_its_quotation_is_replaced_the_payment_position_is_no_longer_shown(): void
    {
        [$update, $url] = $this->sharedWeek();
        $quotation = $update->quotation;

        // While the next revision is only a draft, the update stands as it was shared.
        $revision = $this->quotations()->revise($quotation, $this->manager);
        $this->get($url)->assertOk()->assertSee('Due on the stages reached, after what was received: ₹7,354.15.')->assertDontSee('was replaced by revision');

        $this->issued($revision);
        $page = $this->get($url)->assertOk()
            ->assertSee('The quotation this update referred to was replaced by revision 2, so its payment figures are no longer shown. Ask for a new update.')
            ->assertSee('Approved daily reports of that week: 3.')
            ->assertSee('A-01 Excavation for foundation in all soils')
            ->assertSee('Plinth beam and backfilling.');

        foreach (['Payments', 'Payment position', 'On agreement', 'reached on', 'Received so far', 'Due on the stages', '₹', '47,354', '40,000', '7,354', '1,57,847', $quotation->number] as $money) {
            $page->assertDontSee($money);
        }

        $this->assertHidden($page);
        $this->assertCount(2, $this->pictures($page));

        // And no new link can be made for it: the update has to be prepared on the revision that stands.
        try {
            $this->shares()->share($update, $this->manager);
            $this->fail('A new link was made on a replaced quotation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('replaced by revision 2', $e->errors()['facts'][0]);
        }
    }

    public function test_a_visitor_signed_in_to_another_company_sees_the_whole_update_and_nothing_of_their_own(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();

        // The client runs a firm of their own on the same product, with a week of reports of their own.
        $theirs = $this->tenant('Konkan Builders', 'business');
        $client = $this->member($theirs, 'owner');
        $theirProject = $this->makeProject($theirs, ['name' => 'Their Own Site']);
        $theirPhoto = $this->photo($this->report('2026-11-10', 'APPROVED', [[null, '6.543', 'Their own private work']], ['Mason' => 71], $theirProject), 'Their own caption');
        $this->actingAs($client);
        $this->updates()->create($theirProject, ['week_start' => self::WEEK, 'language' => 'en', 'photo_ids' => [$theirPhoto->id]], $client);
        // Analytics is on for app screens, and the visitor is signed in.
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF', 'ga4_include_app' => '1']);

        $page = $this->get($url)->assertOk();

        // Every work row, both photos and the payment position are there: the tenant scope of the signed-in visitor hid none of them.
        $page->assertSee('Sahyadri Homes')->assertSee('Deshmukh Bungalow')
            ->assertSee('A-01 Excavation for foundation in all soils')->assertSee('B-04 Brick masonry in cement mortar')->assertSee('Curing of plinth beam')
            ->assertSee('The daily worker counts in these reports add up to 23.')
            ->assertSee('Footing F2 before concreting')->assertSee('Brickwork on the east wall')
            ->assertSee('Due on the stages reached, after what was received: ₹7,354.15.');
        $this->assertCount(2, $this->pictures($page));

        foreach ($this->pictures($page) as $address) {
            $this->get($address)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        }

        // And nothing of the visitor, their company or their records.
        foreach (['Konkan Builders', 'Their Own Site', 'Their own private work', 'Their own caption', '6.543', 'add up to 71', $client->name, $client->email] as $theirOwn) {
            $page->assertDontSee($theirOwn, false);
        }

        $this->assertHidden($page, signedIn: true);
        $this->assertAuthenticatedAs($client);

        // For a signed-in visitor the platform's own count of app screens may run. It is handed the
        // pattern of the address and the route's name: no id, no token, no name and no figure.
        $handed = $this->analyticsConfig($page);
        $this->assertSame([url('/shared/owner-updates/:id/:token'), 'owner-updates.shared', []], [$handed['location'], $handed['title'], $handed['events']]);

        foreach ([$update->share_token, 'Sahyadri', 'Deshmukh', 'Konkan', '7354', '7,354', '₹', $client->email] as $private) {
            $this->assertStringNotContainsString($private, json_encode($handed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        // The link does not let them into the app, and their own photo is not reached through our token.
        $this->get(route('owner-updates.show', $update))->assertNotFound();
        $this->get(route('owner-updates.index', $this->project))->assertNotFound();
        $this->get(route('dprs.photo', $photos[0]))->assertNotFound();
        $this->get(URL::signedRoute('owner-updates.shared.photo', ['id' => $update->id, 'token' => $update->share_token, 'photo' => $theirPhoto->id]))->assertNotFound();
    }

    public function test_the_page_shows_nothing_of_another_project_or_another_update_of_the_same_company(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();

        // Another update of the same week with the third photo, an update of last week, and one in another project.
        [$sameWeek] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['photo_ids' => [$photos[1]->id], 'next_week_text' => 'Text of the other update'])));
        $this->photo(Dpr::whereDate('report_date', '2026-11-06')->sole(), 'Caption of last week');
        [$lastWeek] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['week_start' => '2026-11-02'])));

        $second = $this->makeProject($this->organization, ['name' => 'Kulkarni Row House']);
        $this->report('2026-11-10', 'APPROVED', [[$this->line($second, 'K-01', 'Terrace waterproofing for Kulkarni', 'sqm', '55.000', '7777.00'), '31.250']], ['Mason' => 88], $second);
        [$elsewhere] = $this->sharedUpdate($this->approvedUpdate($this->updates()->create($second, ['week_start' => self::WEEK, 'language' => 'en'], $this->manager)));
        $this->signOut();

        $page = $this->get($url)->assertOk()->assertSee('Approved daily reports of that week: 3.');

        foreach (['Text of the other update', 'Caption of last week', '77.777', 'Kulkarni', 'Terrace waterproofing', '31.250', 'add up to 88', $sameWeek->share_token, $lastWeek->share_token, $elsewhere->share_token, $this->owner->name, $this->manager->email] as $other) {
            $page->assertDontSee($other, false);
        }

        $this->assertCount(2, $this->pictures($page));
        $this->assertNotSame($update->share_token, $sameWeek->share_token);
    }

    public function test_someone_of_the_same_company_without_money_view_opens_the_link_like_anyone_else(): void
    {
        [, $url] = $this->sharedWeek();
        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);
        $limited = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);

        // The link is what the contractor chose to send out. Whoever holds it reads what the client reads.
        $page = $this->actingAs($limited->refresh())->get($url)->assertOk()->assertSee('Brick masonry in cement mortar')->assertSee('₹7,354.15');
        $this->get($this->pictures($page)[0])->assertOk();
    }

    /** As for a shared quotation: the gate that runs for every signed-in request turns a portal login round before the page. */
    public function test_a_portal_login_is_turned_round_by_the_existing_gate_and_shown_nothing(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $photoUrl = $this->shares()->photoUrl($update, $photos[0]->id);
        $elsewhere = $this->tenant('Konkan Builders', 'business');

        foreach ([$url, $photoUrl] as $address) {
            $portal = $this->actingAs($this->member($elsewhere, 'contractor'))->get($address);
            $portal->assertRedirect(route('contractor-portal.home'));
            $this->assertNothingOfOurs($portal);
        }

        $this->signOut();
        $this->get($url)->assertOk()->assertSee('Approved daily reports of that week: 3.');
    }

    public function test_the_page_and_its_photos_close_when_the_plan_no_longer_has_the_pack_or_the_project_is_gone(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $photoUrl = $this->shares()->photoUrl($update, $photos[0]->id);
        $this->signOut();
        $this->get($url)->assertOk();

        $this->organization->update(['plan' => 'free']);
        $this->assertInactive($this->get($url));
        $this->assertInactive($this->get($photoUrl));

        $this->organization->update(['plan' => 'contractor']);
        $this->get($url)->assertOk();
        $this->get($photoUrl)->assertOk();

        $this->project->delete();
        $this->assertInactive($this->get($url));
        $this->assertInactive($this->get($photoUrl));
    }

    public function test_the_page_and_its_photos_have_counters_of_their_own_and_never_use_up_the_sign_in_tries(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $photoUrl = $this->shares()->photoUrl($update, $photos[0]->id);
        $this->signOut();
        $limiter = app(RateLimiter::class);
        $plain = sha1('|127.0.0.1');

        // A page with a dozen photos, opened three times: three requests are counted for the page and thirty-six for its photos.
        for ($visit = 1; $visit <= 3; $visit++) {
            $this->get($url)->assertOk();

            for ($photo = 1; $photo <= 12; $photo++) {
                $this->get($photoUrl)->assertOk();
            }
        }

        $this->assertSame(3, $limiter->attempts('owner-share'.$plain));
        $this->assertSame(36, $limiter->attempts('owner-share-photo'.$plain));
        $this->assertSame(0, $limiter->attempts($plain));
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors();

        // The other way round: with the sign-in tries of this address used up, the page and its photos still open.
        for ($try = 1; $try <= 12; $try++) {
            $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
        }

        $this->assertGreaterThanOrEqual(10, $limiter->attempts($plain));
        $this->get($url)->assertOk();
        $this->get($photoUrl)->assertOk();

        // Each has a limit of its own: the photos stop after 120 in a minute, and the page still opens.
        for ($photo = 38; $photo <= 120; $photo++) {
            $this->get($photoUrl)->assertOk();
        }

        $refused = $this->get($photoUrl);
        $this->assertNotSame(200, $refused->getStatusCode());
        $this->assertStringNotContainsString("\xFF\xD8", $refused->getContent());
        $this->get($url)->assertOk();
    }

    public function test_the_shared_routes_leave_the_sign_in_group_and_keep_their_guards(): void
    {
        foreach (['owner-updates.shared' => ['shared/owner-updates/{id}/{token}', 'throttle:30,1,owner-share'], 'owner-updates.shared.photo' => ['shared/owner-updates/{id}/{token}/photos/{photo}', 'throttle:120,1,owner-share-photo']] as $name => [$uri, $throttle]) {
            $route = Route::getRoutes()->getByName($name);
            $middleware = $route->gatherMiddleware();

            $this->assertSame($uri, $route->uri());
            $this->assertContains('signed', $middleware);
            $this->assertContains($throttle, $middleware);
            // The file is loaded inside the sign-in group, so the route names the two it leaves.
            $this->assertEqualsCanonicalizing(['auth', 'active'], $route->excludedMiddleware());
            $this->assertSame([], array_values(array_filter($middleware, fn ($entry) => is_string($entry) && (str_starts_with($entry, 'can:') || str_starts_with($entry, 'feature:')))));
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }
    }

    public function test_the_page_is_written_in_the_language_of_the_update(): void
    {
        ['photos' => $photos] = $this->week();
        $quotation = $this->quotationWithAStageReached();

        $expected = [
            'hi' => ['साप्ताहिक अपडेट', 'यह अपडेट 9 नवंबर 2026 से 15 नवंबर 2026 तक के सप्ताह का है।', 'उस सप्ताह की मंज़ूर दैनिक रिपोर्टें: 3।', 'किया गया काम', 'मात्रा', 'इन रिपोर्टों में रोज़ की मज़दूर संख्या का जोड़ 23 है।', 'On agreement: ₹47,354.15, 10 नवंबर 2026 को पूरा हुआ।', 'मिली रकम घटाने के बाद पूरे हुए चरणों पर बकाया: ₹7,354.15।', 'द्वारा साझा किया गया', '14 नवंबर 2026 को मंज़ूर'],
            'mr' => ['साप्ताहिक अपडेट', 'हे अपडेट 9 नोव्हेंबर 2026 ते 15 नोव्हेंबर 2026 या आठवड्याचे आहे.', 'त्या आठवड्यातील मंजूर दैनिक अहवाल: 3.', 'झालेले काम', 'प्रमाण', 'या अहवालांमधील रोजच्या कामगार संख्येची बेरीज 23 आहे.', 'On agreement: ₹47,354.15, 10 नोव्हेंबर 2026 रोजी गाठला.', 'मिळालेली रक्कम वजा केल्यानंतर गाठलेल्या टप्प्यांवरील देय रक्कम: ₹7,354.15.', 'यांनी शेअर केले', '14 नोव्हेंबर 2026 रोजी मंजूर'],
        ];

        foreach ($expected as $language => $texts) {
            [, $url] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['language' => $language, 'photo_ids' => [$photos[0]->id], 'show_money' => true, 'quotation_id' => $quotation->id, 'received_amount' => '40000'])));
            $this->signOut();
            $this->flushSession();
            app()->setLocale('en');

            $page = $this->get($url)->assertOk();

            foreach ($texts as $text) {
                $page->assertSee($text);
            }

            $page->assertDontSee('Weekly update')->assertDontSee('Work done')->assertDontSee('Approved daily reports')->assertDontSee('Shared by');
            $this->assertSame($language, $this->tags($page)['lang']);
            $this->assertDoesNotMatchRegularExpression('/[\x{0966}-\x{096F}]/u', $page->getContent(), 'Digits stay ASCII.');
            $this->assertHidden($page);
        }
    }

    public function test_the_whatsapp_message_names_the_week_and_carries_no_figure_of_the_work_or_of_money(): void
    {
        [$update] = $this->sharedWeek();
        $text = $this->shares()->whatsappText($update, 'Sahyadri Homes');

        $this->assertStringContainsString("Sahyadri Homes · Weekly update\n9 November 2026 to 15 November 2026\n", $text);
        $this->assertStringContainsString($this->shares()->url($update), $text);

        foreach (['₹', '7,354', '7354', '47,354', '23', '7.375'] as $figure) {
            $this->assertStringNotContainsString($figure, Str::before($text, 'http'));
        }

        $this->assertStringStartsWith('https://wa.me/?text=', $this->shares()->whatsappUrl($update, 'Sahyadri Homes'));

        $this->shares()->revoke($update, $this->manager);
        $this->assertNull($this->shares()->whatsappText($update->fresh(), 'Sahyadri Homes'));
        $this->assertNull($this->shares()->whatsappUrl($update->fresh(), 'Sahyadri Homes'));
    }

    public function test_the_token_is_kept_out_of_arrays_and_out_of_the_company_data_export(): void
    {
        [$update] = $this->sharedWeek();
        $data = app(TenantData::class);
        $columns = $data->tables()['owner_updates'];

        $this->assertContains('share_token', $columns);
        $this->assertNotContains('share_token', $data->exportableColumns($columns));
        $this->assertArrayNotHasKey('share_token', $update->toArray());
        $this->assertStringNotContainsString($update->share_token, $update->toJson());
    }

    public function test_a_photo_leaves_as_an_upright_picture_drawn_again_with_nothing_of_the_stored_file_but_its_pixels(): void
    {
        ['reports' => [$monday]] = $this->week();
        // As a phone stores it: on its side, with the camera's name and a position (an invented one, in open sea).
        $stored = $this->jpeg(1200, 800, $this->exif(6).$this->otherMetadata());
        $photo = $this->photo($monday, 'Taken on a phone', $stored);
        [$update] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['photo_ids' => [$photo->id]])));
        $this->signOut();

        $this->assertStringContainsString('Test camera', $stored);

        $sent = $this->get($this->shares()->photoUrl($update, $photo->id))->assertOk()->getContent();

        $this->assertSame([800, 1200, 'image/jpeg'], array_values(array_intersect_key(getimagesizefromstring($sent), [0 => 0, 1 => 0, 'mime' => 0])));
        $this->assertRed($sent, 750, 50, 'top right');
        $this->assertWhite($sent, 50, 1150, 'bottom left');

        foreach (['Exif', 'Test camera', 'xmpmeta', 'Invented Person', 'Photoshop', 'Invented caption', 'Scanned at the invented site office'] as $carried) {
            $this->assertStringNotContainsString($carried, $sent);
        }

        $this->assertNotSame($stored, $sent);
        $this->assertSame((string) strlen($sent), $this->get($this->shares()->photoUrl($update, $photo->id))->headers->get('Content-Length'));

        // A large photo is made smaller on the way out; the stored file is left as it is.
        config(['modules.owner_pack.photo_edge' => 600]);
        $smaller = getimagesizefromstring($this->get($this->shares()->photoUrl($update, $photo->id))->assertOk()->getContent());
        $this->assertSame([400, 600], [$smaller[0], $smaller[1]]);
        $this->assertSame($stored, Storage::get($photo->path));
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: array{0: int, 1: int}}> Orientation, width, height, where the red block ends up. */
    public static function turns(): array
    {
        return [
            'upright' => [1, 1200, 800, [50, 50]],
            'mirrored' => [2, 1200, 800, [1150, 50]],
            'upside down' => [3, 1200, 800, [1150, 750]],
            'upside down and mirrored' => [4, 1200, 800, [50, 750]],
            'on its side and mirrored' => [5, 800, 1200, [50, 50]],
            'quarter turn' => [6, 800, 1200, [750, 50]],
            'the other side and mirrored' => [7, 800, 1200, [750, 1150]],
            'quarter turn the other way' => [8, 800, 1200, [50, 1150]],
        ];
    }

    #[DataProvider('turns')]
    public function test_each_orientation_a_phone_writes_ends_upright(int $orientation, int $width, int $height, array $redAt): void
    {
        Storage::put('org-1/dprs/1/turned.jpg', $this->jpeg(1200, 800, $this->exif($orientation, 'II')));

        $sent = app(SharedPhoto::class)->jpeg('org-1/dprs/1/turned.jpg');
        $size = getimagesizefromstring($sent);

        $this->assertSame([$width, $height], [$size[0], $size[1]]);
        $this->assertRed($sent, $redAt[0], $redAt[1], "place orientation {$orientation} puts the block");

        foreach ([[50, 50], [$width - 50, 50], [50, $height - 50], [$width - 50, $height - 50]] as $corner) {
            if ($corner !== $redAt) {
                $this->assertWhite($sent, $corner[0], $corner[1], 'other corners');
            }
        }
    }

    public function test_a_file_that_can_no_longer_be_drawn_again_is_not_sent_at_all(): void
    {
        ['reports' => [$monday]] = $this->week();
        $photos = [
            'not a picture' => $this->photo($monday, 'Becomes a text file'),
            'too large' => $this->photo($monday, 'Becomes larger than the server opens'),
            'missing' => $this->photo($monday, 'File leaves the disk'),
            'png' => $this->photo($monday, 'A PNG', $this->png(300, 200)),
        ];

        [$update, $url] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['photo_ids' => array_map(fn (DprPhoto $photo) => $photo->id, array_values($photos))])));
        $this->signOut();

        // After the update went out, three of the stored files change underneath it.
        Storage::put($photos['not a picture']->path, 'GPS 18.5204, 73.8567 and other words that are not a picture');
        Storage::put($photos['too large']->path, $this->pngHeaderOnly(6000, 5000));
        Storage::delete($photos['missing']->path);

        foreach (['not a picture', 'too large', 'missing'] as $which) {
            $response = $this->get($this->shares()->photoUrl($update, $photos[$which]->id))->assertNotFound();

            $this->assertSame('', $response->getContent(), $which);
            $this->assertHiddenByHeaders($response);
        }

        // A PNG leaves as a JPEG like every other photo. The page itself still opens with all four captions.
        $sent = $this->get($this->shares()->photoUrl($update, $photos['png']->id))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->getContent();
        $this->assertSame('image/jpeg', getimagesizefromstring($sent)['mime']);
        $this->get($url)->assertOk()->assertSee('Becomes a text file')->assertSee('A PNG');

        // On a server without the image functions no photo is sent, rather than the stored file as it is. (A copy drawn
        // earlier on a server that had them is a drawn picture and may be sent; this one has none.)
        Storage::deleteDirectory('org-'.$this->organization->id.'/owner-pack');
        $this->app->bind(SharedPhoto::class, fn () => new class extends SharedPhoto
        {
            protected static function canRedraw(): bool
            {
                return false;
            }
        });

        $response = $this->get($this->shares()->photoUrl($update, $photos['png']->id))->assertNotFound();
        $this->assertSame('', $response->getContent());
    }

    public function test_a_photo_that_cannot_be_drawn_again_is_refused_when_it_is_picked(): void
    {
        ['reports' => [$monday], 'photos' => [$footing]] = $this->week();
        $notAPicture = $this->photo($monday, 'A text file with a picture name', 'Words that are not a picture');
        $tooLarge = $this->photo($monday, 'Larger than the server opens', $this->pngHeaderOnly(6000, 5000));
        $missing = $this->photo($monday);
        Storage::delete($missing->path);

        foreach ([[$notAPicture, '9 November 2026 A text file with a picture name'], [$tooLarge, '9 November 2026 Larger than the server opens'], [$missing, '9 November 2026']] as [$photo, $named]) {
            try {
                $this->draftUpdate(['photo_ids' => [$footing->id, $photo->id]]);
                $this->fail('A photo that cannot be sent was picked.');
            } catch (ValidationException $e) {
                $this->assertSame(['photo_ids'], array_keys($e->errors()));
                $this->assertStringContainsString(': '.$named.'. Pick other photos.', $e->errors()['photo_ids'][0]);
                $this->assertStringNotContainsString('Footing F2', $e->errors()['photo_ids'][0]);
            }
        }

        $this->assertSame(0, OwnerUpdate::withoutGlobalScopes()->count());

        // The limit follows the configured memory, not a figure in the code.
        config(['modules.owner_pack.photo_max_pixels' => 40_000_000]);
        $this->assertSame([$tooLarge->id], $this->draftUpdate(['photo_ids' => [$tooLarge->id]])->photoIds());
    }

    /** A SharedPhoto that counts how often a stored photo is decoded and drawn again. */
    private function countingPhotos(): SharedPhoto
    {
        $photos = new class extends SharedPhoto
        {
            public int $drawn = 0;

            public function jpeg(string $path): ?string
            {
                $this->drawn++;

                return parent::jpeg($path);
            }
        };

        $this->app->instance(SharedPhoto::class, $photos);

        return $photos;
    }

    public function test_a_photo_is_drawn_once_and_then_sent_from_the_copy_kept_for_the_company(): void
    {
        ['reports' => [$monday]] = $this->week();
        $stored = $this->jpeg(1200, 800, $this->exif(6).$this->otherMetadata());
        $photo = $this->photo($monday, 'Taken on a phone', $stored);
        [$update] = $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['photo_ids' => [$photo->id]])));
        $this->signOut();
        $photos = $this->countingPhotos();
        $copy = SharedPhoto::copyPath($this->organization->id, $photo->id, $photo->path);
        $this->assertStringStartsWith('org-'.$this->organization->id.'/owner-pack/', $copy);
        $this->assertFalse(Storage::exists($copy));

        $first = $this->get($this->shares()->photoUrl($update, $photo->id))->assertOk();
        $this->assertSame(1, $photos->drawn);
        $this->assertPhotoHeaders($first);
        $this->assertTrue(Storage::exists($copy), 'The drawn copy is kept in the company\'s folder.');
        $this->assertSame($first->getContent(), Storage::get($copy));

        // What is kept is the drawn picture, with nothing of the stored file but its pixels.
        foreach (['Exif', 'Test camera', 'xmpmeta', 'Invented Person'] as $carried) {
            $this->assertStringNotContainsString($carried, (string) Storage::get($copy));
        }

        // Every later request, a reload or another visitor with the link, is sent the copy without decoding the photo again.
        foreach (range(1, 3) as $again) {
            $later = $this->get($this->shares()->photoUrl($update, $photo->id))->assertOk();
            $this->assertSame($first->getContent(), $later->getContent());
            $this->assertSame((string) strlen($first->getContent()), $later->headers->get('Content-Length'));
            $this->assertPhotoHeaders($later);
        }

        $this->assertSame(1, $photos->drawn);
        $this->assertSame($stored, Storage::get($photo->path), 'The stored file is left as it is.');

        // Drawn at another size, the photo is drawn once more and kept beside the first copy.
        config(['modules.owner_pack.photo_edge' => 600]);
        $smaller = $this->get($this->shares()->photoUrl($update, $photo->id))->assertOk()->getContent();
        $this->get($this->shares()->photoUrl($update, $photo->id))->assertOk();
        $this->assertSame(2, $photos->drawn);
        $this->assertSame([400, 600], array_slice(getimagesizefromstring($smaller), 0, 2));

        // The stored file gone: the photo is not sent, even though a copy of it is kept.
        Storage::delete($photo->path);
        $this->assertSame('', $this->get($this->shares()->photoUrl($update, $photo->id))->assertNotFound()->getContent());
    }

    public function test_a_withdrawn_or_expired_link_is_refused_as_before_even_with_the_copy_kept(): void
    {
        [$update, $url, $photos] = $this->sharedWeek();
        $this->signOut();
        $counting = $this->countingPhotos();
        $photoUrl = $this->shares()->photoUrl($update, $photos[0]->id);
        $copy = SharedPhoto::copyPath($this->organization->id, $photos[0]->id, $photos[0]->path);

        $this->get($photoUrl)->assertOk();
        $this->assertTrue(Storage::exists($copy));

        // Withdrawn: the same plain page as before, with nothing read and nothing drawn.
        $this->shares()->revoke($update, $this->manager);
        $this->assertInactive($this->get($photoUrl));
        $this->assertTrue(Storage::exists($copy));
        $this->assertSame(1, $counting->drawn);

        // A new link sends the kept copy; past its date it is refused as before.
        [$update] = $this->sharedUpdate($update->fresh());
        $newPhotoUrl = $this->shares()->photoUrl($update, $photos[0]->id);
        $this->get($newPhotoUrl)->assertOk();
        $this->assertSame(1, $counting->drawn);

        $this->travel(15)->days();
        $this->assertInactive($this->get($newPhotoUrl));
        $this->assertInactive($this->get($photoUrl));

        // A properly signed address with another token, or for a photo the update does not name, is refused too.
        $this->assertInactive($this->get(URL::signedRoute('owner-updates.shared.photo', ['id' => $update->id, 'token' => Str::random(40), 'photo' => $photos[0]->id])));
        $this->assertSame(1, $counting->drawn);
    }

    public function test_the_kept_copies_go_with_the_company(): void
    {
        [$update, , $photos] = $this->sharedWeek();
        $this->signOut();
        $copy = SharedPhoto::copyPath($this->organization->id, $photos[0]->id, $photos[0]->path);
        $this->get($this->shares()->photoUrl($update, $photos[0]->id))->assertOk();
        $this->assertTrue(Storage::exists($copy));

        // Another company's copy stays.
        Storage::put('org-'.($this->organization->id + 1000).'/owner-pack/shared-photos/1-other.jpg', 'another company');

        $owner = $this->member($this->organization, 'owner');
        app(CompanyDeletionService::class)->request($this->organization, $owner, $this->organization->name);
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        Auth::forgetGuards();
        $this->assertSame(1, app(CompanyDeletionService::class)->purgeDue());

        $this->assertFalse(Storage::exists($copy));
        $this->assertSame([], Storage::allFiles('org-'.$this->organization->id));
        $this->assertTrue(Storage::exists('org-'.($this->organization->id + 1000).'/owner-pack/shared-photos/1-other.jpg'));
        $this->assertContains('org-{org}/owner-pack', config('modules.owner_pack.purge_directories'));
    }
}
