<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ReaderSettings;
use App\Services\DocumentReading\ReadWords;
use App\Services\Website\AiOffer;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The company's own switch for sending documents to the AI service, and its spending limits:
 * who may open and change them, what is kept when the switch is turned on, and what stops when it
 * is turned off.
 */
class AiDocumentSettingsTest extends PageReadingTestCase
{
    private const PAGE = '/settings/ai-documents';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Every test starts with a company that has never decided anything.
        AiDocumentSettings::withoutGlobalScopes()->delete();
    }

    private function row(?Organization $organization = null): ?AiDocumentSettings
    {
        return AiDocumentSettings::withoutGlobalScopes()->where('organization_id', ($organization ?? $this->organization)->id)->first();
    }

    /** Sets aside the text calls real modules register (such as the pre-check's explanation), so only the stand-ins count. */
    private function withoutRealTextCalls(): void
    {
        foreach (array_keys(config('modules', [])) as $module) {
            config(["modules.{$module}.text_calls" => []]);
        }
    }

    private function settings(): ReaderSettings
    {
        return app(ReaderSettings::class);
    }

    /** What the form posts when the box is ticked, in the language the page was shown in. */
    private function switchOn(?User $by = null, array $overrides = [], string $locale = 'en')
    {
        return $this->actingAs($by ?? $this->owner)->post(self::PAGE, [
            'action' => 'on',
            'accept' => '1',
            'notice_locale' => $locale,
            'notice_hash' => AiDocumentConsent::hash($this->settings()->notice($locale)),
            ...$overrides,
        ]);
    }

    private function switchOff(?User $by = null)
    {
        return $this->actingAs($by ?? $this->owner)->post(self::PAGE, ['action' => 'off']);
    }

    private function deployVoice(bool $accepted): void
    {
        Route::post('/_voice-test', fn () => '')->name(AiOffer::VOICE_ROUTE);
        Route::getRoutes()->refreshNameLookups();
        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => $accepted ? '1' : '0']);
    }

    public function test_only_owners_and_admins_on_a_plan_with_ai_can_open_or_change_it(): void
    {
        // A project manager may manage the agents, but this switch is not theirs.
        foreach (['pm', 'qs', 'engineer', 'store', 'accounts', 'viewer'] as $role) {
            $member = $this->member($this->organization, $role);

            $this->actingAs($member)->get(self::PAGE)->assertForbidden();
            $this->switchOn($member)->assertForbidden();
            $this->switchOff($member)->assertForbidden();
            $this->actingAs($member)->post(self::PAGE, ['action' => 'limits', 'max_credits_per_read' => '10'])->assertForbidden();
        }

        $this->assertNull($this->row());

        foreach (['owner', 'admin'] as $role) {
            $this->actingAs($this->member($this->organization, $role))->get(self::PAGE)->assertOk()->assertSee('Sending documents to the AI service');
        }

        // A role the company made itself may be given the settings. Limited to chosen projects, the same
        // person never holds a company-wide permission, whatever the role says.
        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Office admin', 'permissions' => ['settings.manage'], 'created_by' => $this->owner->id]);
        $office = $this->member($this->organization, 'engineer');
        $office->forceFill(['custom_role_id' => $role->id])->save();
        $this->actingAs($office)->get(self::PAGE)->assertOk();

        $office->forceFill(['limited_to_projects' => true])->save();
        $this->actingAs($office->fresh())->get(self::PAGE)->assertForbidden();
        $this->switchOn($office->fresh())->assertForbidden();

        // A plan without the AI features has no such screen.
        $small = $this->tenant('Small Works', 'contractor');
        $smallOwner = $this->member($small, 'owner');
        $this->actingAs($smallOwner)->get(self::PAGE)->assertForbidden();
        $this->switchOn($smallOwner)->assertForbidden();
        $this->assertNull($this->row($small));

        // A contractor's own login is kept to its portal.
        $party = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
        $this->assertNotSame(200, $this->actingAs($contractor)->get(self::PAGE)->getStatusCode());
        $this->assertNotSame(200, $this->switchOn($contractor)->getStatusCode());
        $this->assertNull($this->row());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(self::PAGE)->assertRedirect(route('login'));
        $this->post(self::PAGE, ['action' => 'off'])->assertRedirect(route('login'));
    }

    public function test_the_menu_offers_it_to_owners_and_admins_on_a_plan_with_ai(): void
    {
        $entry = fn (Organization $organization) => array_values(array_filter(Modules::nav('company', $organization), fn ($item) => $item['route'] === 'ai-documents.edit'));

        $this->assertCount(1, $entry($this->organization));
        $this->assertSame(['AI', 'settings.manage'], [$entry($this->organization)[0]['group'], $entry($this->organization)[0]['can']]);
        $this->assertSame([], $entry($this->tenant('Small Works', 'contractor')));

        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee(route('ai-documents.edit'), false)->assertSee('AI document readers');
        $this->actingAs($this->member($this->organization, 'pm'))->get('/dashboard')->assertOk()->assertDontSee(route('ai-documents.edit'), false);

        $this->assertSame('AI दस्तावेज़ रीडर', __('AI document readers', [], 'hi'));
        $this->assertSame('AI दस्तऐवज रीडर', __('AI document readers', [], 'mr'));

        // With no reader deployed, as on the day this branch goes live, no owner or admin is shown the entry.
        config(['modules.reader_stand_ins.read_kinds' => []]);
        $this->assertSame([], $entry($this->organization));
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertDontSee(route('ai-documents.edit'), false)->assertDontSee('AI document readers');
        $this->actingAs($this->member($this->organization, 'admin'))->get('/dashboard')->assertOk()->assertDontSee('AI document readers');

        // Nor with a reader deployed but the readers not released.
        config(['modules.reader_stand_ins.read_kinds' => [Support\FakeKind::class]]);
        $this->assertCount(1, $entry($this->organization));
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $this->assertSame([], $entry($this->organization));
        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertDontSee('AI document readers');
    }

    public function test_with_no_reader_deployed_the_page_says_so_and_nothing_can_be_accepted(): void
    {
        config(['modules.reader_stand_ins.read_kinds' => []]);
        $this->withoutRealTextCalls();

        $this->assertFalse($this->settings()->readerDeployed());
        $this->assertFalse($this->settings()->offered());

        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('No reader is switched on yet.')
            ->assertDontSee('Anthropic')
            ->assertDontSee('Switch the readers on')
            ->assertDontSee('name="accept"', false)
            ->assertDontSee('Spending limits');

        $this->from(self::PAGE)->post(self::PAGE, ['action' => 'on', 'accept' => '1', 'notice_locale' => 'en', 'notice_hash' => str_repeat('a', 64)])
            ->assertRedirect(self::PAGE)->assertSessionHasErrors('accept');
        // With nothing deployed that could spend, there is no limit to set either.
        $this->post(self::PAGE, ['action' => 'limits', 'max_credits_per_read' => '100'])->assertSessionHasErrors('budgets');

        $this->assertNull($this->row());
        $this->assertSame([], $this->audits('ai_documents.consent_on'));
    }

    public function test_readers_that_are_not_released_are_not_offered_either(): void
    {
        // Deployed, but the platform admin has not switched them on.
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);

        $this->assertTrue($this->settings()->readerDeployed());
        $this->assertFalse($this->settings()->offered());
        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()->assertSee('No reader is switched on yet.')->assertDontSee('name="accept"', false)->assertDontSee('Spending limits');
        $this->switchOn()->assertSessionHasErrors('accept');
        $this->actingAs($this->owner)->post(self::PAGE, ['action' => 'limits', 'max_credits_per_read' => '100'])->assertSessionHasErrors('budgets');

        // Switched on, but without the provider's monthly cap on record there is still no release.
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => null]);
        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()->assertSee('No reader is switched on yet.');
        $this->switchOn()->assertSessionHasErrors('accept');

        $this->assertNull($this->row());
    }

    public function test_the_page_shows_the_notice_and_the_switch_is_off_until_someone_agrees(): void
    {
        $page = $this->actingAs($this->owner)->get(self::PAGE)->assertOk();

        foreach (AiDocumentConsent::sentences('en') as $sentence) {
            $page->assertSee($sentence);
        }

        $page->assertSee('processed on its servers outside India')
            ->assertSee('names, phone numbers, GSTINs and amounts on the page are not hidden')
            ->assertSee('What the reader returns can be wrong')
            ->assertSee('name="accept"', false)
            ->assertSee('Switch the readers on')
            ->assertDontSee('Switch the readers off')
            ->assertDontSee('No reader is switched on yet.');

        // Looking at the page decides nothing and makes no row.
        $this->assertNull($this->row());
        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->assertSame(['enabled' => false, 'version' => null, 'current' => false], $this->settings()->consent($this->organization));
    }

    public function test_switching_on_needs_the_box_ticked(): void
    {
        $this->from(self::PAGE)->switchOn(overrides: ['accept' => null])->assertRedirect(self::PAGE)->assertSessionHasErrors('accept');
        $this->from(self::PAGE)->switchOn(overrides: ['accept' => '0'])->assertSessionHasErrors('accept');

        $this->assertNull($this->row());
        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->assertSame([], $this->audits('ai_documents.consent_on'));
    }

    public function test_switching_on_keeps_the_words_shown_and_the_audit_trail_keeps_only_their_fingerprint(): void
    {
        $notice = AiDocumentConsent::notice('en');

        $this->switchOn()->assertRedirect(route('ai-documents.edit'))->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertTrue($row->enabled);
        $this->assertSame([AiDocumentConsent::VERSION, $notice, 'en', $this->owner->id], [$row->consent_version, $row->consent_text, $row->consent_locale, $row->consented_by]);
        $this->assertNotNull($row->consented_at);
        $this->assertNull($row->withdrawn_at);
        $this->assertTrue(AiDocumentConsent::current($this->organization));
        $this->assertSame(['enabled' => true, 'version' => 'ai-docs-v2', 'current' => true], $this->settings()->consent($this->organization));

        $audit = collect($this->audits('ai_documents.consent_on'))->sole();
        $this->assertSame(['enabled' => false, 'version' => null], $audit->old_values);
        $this->assertSame(['enabled' => true, 'version' => 'ai-docs-v2', 'locale' => 'en', 'sha256' => hash('sha256', $notice)], $audit->new_values);
        $this->assertSame([$this->organization->id, $this->owner->id, $row->id], [$audit->organization_id, $audit->user_id, $audit->auditable_id]);
        $this->assertStringNotContainsString('Anthropic', (string) json_encode($audit->getAttributes()));

        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('Switched on by '.$this->owner->name)
            ->assertSee('Notice version: ai-docs-v2.')
            ->assertSee('Switch the readers off')
            ->assertDontSee('name="accept"', false);
    }

    public function test_agreeing_twice_changes_nothing_the_second_time(): void
    {
        $this->switchOn()->assertSessionHasNoErrors();
        $first = $this->row()->consented_at;

        $this->travel(5)->minutes();
        $this->switchOn($this->member($this->organization, 'admin'))->assertSessionHasNoErrors();

        $this->assertTrue($first->equalTo($this->row()->consented_at));
        $this->assertSame($this->owner->id, $this->row()->consented_by);
        $this->assertCount(1, $this->audits('ai_documents.consent_on'));
    }

    public function test_the_notice_is_kept_in_the_language_it_was_read_in(): void
    {
        $this->owner->forceFill(['locale' => 'hi'])->save();

        $page = $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('रीडर आपके चुने हुए पन्ने, फोटो या टेक्स्ट Anthropic को भेजता है')
            ->assertSee('name="notice_locale" value="hi"', false)
            ->assertDontSee('The reader sends the pages');

        $this->assertStringContainsString(AiDocumentConsent::hash(AiDocumentConsent::notice('hi')), $page->getContent());

        $this->switchOn(locale: 'hi')->assertSessionHasNoErrors();

        $row = $this->row();
        $this->assertSame('hi', $row->consent_locale);
        $this->assertSame(AiDocumentConsent::notice('hi'), $row->consent_text);
        $this->assertStringStartsWith('रीडर आपके चुने हुए पन्ने', $row->consent_text);
        $this->assertSame('hi', collect($this->audits('ai_documents.consent_on'))->sole()->new_values['locale']);

        // The three notices are three texts, each complete.
        $texts = array_map(fn (string $locale) => AiDocumentConsent::notice($locale), ['en', 'hi', 'mr']);
        $this->assertCount(3, array_unique($texts));
        $this->assertStringStartsWith('रीडर तुम्ही निवडलेली पाने', $texts[2]);

        foreach (['hi', 'mr'] as $locale) {
            $this->assertCount(count(AiDocumentConsent::sentences('en', true)), AiDocumentConsent::sentences($locale, true));

            foreach (AiDocumentConsent::sentences($locale, true) as $index => $sentence) {
                $this->assertNotSame(AiDocumentConsent::sentences('en', true)[$index], $sentence, "Sentence {$index} has no {$locale} line.");
            }
        }
    }

    public function test_a_notice_that_changed_after_the_page_was_opened_is_not_agreed_to(): void
    {
        $shown = AiDocumentConsent::hash($this->settings()->notice('en'));

        // Voice notes are released while the page is open, so the notice gains a sentence.
        $this->deployVoice(true);

        $this->from(self::PAGE)->switchOn(overrides: ['notice_hash' => $shown])->assertRedirect(self::PAGE)->assertSessionHasErrors('accept');
        $this->assertNull($this->row());

        // An unknown language or a missing fingerprint is refused as well.
        $this->switchOn(overrides: ['notice_locale' => 'fr'])->assertSessionHasErrors('notice_locale');
        $this->switchOn(overrides: ['notice_hash' => null])->assertSessionHasErrors('notice_hash');
        // The fingerprint of the Marathi notice does not agree to the English one.
        $this->switchOn(overrides: ['notice_hash' => AiDocumentConsent::hash($this->settings()->notice('mr'))])->assertSessionHasErrors('accept');
        $this->assertNull($this->row());

        $this->switchOn()->assertSessionHasNoErrors();
        $this->assertStringContainsString('no audio leaves the phone', $this->row()->consent_text);
    }

    public function test_the_sentence_about_voice_notes_waits_until_it_was_shown_to_be_true(): void
    {
        $voice = 'no audio leaves the phone';

        $this->assertStringNotContainsString($voice, $this->settings()->notice('en'));
        $this->actingAs($this->owner)->get(self::PAGE)->assertDontSee($voice);

        // The voice endpoint is deployed, but nobody has recorded that speech stays on the phone.
        $this->deployVoice(false);
        $this->assertFalse($this->settings()->voiceOffered());
        $this->actingAs($this->owner)->get(self::PAGE)->assertDontSee($voice);

        $this->deployVoice(true);
        $this->assertTrue($this->settings()->voiceOffered());
        $this->actingAs($this->owner)->get(self::PAGE)->assertSee($voice);
        $this->assertCount(10, $this->settings()->sentences('en'));
        $this->assertCount(9, AiDocumentConsent::sentences('en'));

        // Voice alone is a reader too: with no document reader deployed the switch is still offered.
        config(['modules.reader_stand_ins.read_kinds' => []]);
        $this->assertTrue($this->settings()->offered());
    }

    public function test_switching_off_cancels_every_read_that_is_not_finished_and_keeps_the_finished_ones(): void
    {
        $this->switchOn()->assertSessionHasNoErrors();
        $this->setCredits($this->organization, '500');

        $reads = [];

        foreach ([...DocumentRead::OPEN, DocumentRead::READY, DocumentRead::FAILED, DocumentRead::CONFIRMED, DocumentRead::DISCARDED, DocumentRead::CANCELLED, DocumentRead::EXPIRED] as $status) {
            $reads[$status] = $this->makeRead($this->organization, $this->owner, [
                'status' => $status,
                'consent_version' => AiDocumentConsent::VERSION,
                'credits_cap' => '40',
                'credits_held' => $status === DocumentRead::RUNNING ? '40' : '0',
            ]);
            $this->makePage($reads[$status], 1, ['status' => DocumentReadPage::READ]);
            $this->makePage($reads[$status], 2, ['status' => DocumentReadPage::WAITING]);
        }

        $queued = $this->makePage($reads[DocumentRead::RUNNING], 3, ['status' => DocumentReadPage::QUEUED]);
        $withReader = $this->makePage($reads[DocumentRead::RUNNING], 4, ['status' => DocumentReadPage::RUNNING]);

        // Another company's read is none of this company's business.
        $other = $this->tenant('Godavari Infra');
        $otherOwner = $this->member($other, 'owner');
        $this->consent($other);
        $this->setCredits($other, '500');
        $theirs = $this->runningRead($other, $otherOwner, '25', ['consent_version' => AiDocumentConsent::VERSION]);
        $theirPage = $this->makePage($theirs, 1, ['status' => DocumentReadPage::WAITING]);

        $this->assertSame('40.0000', $this->credits()->held($this->organization));

        $this->switchOff()->assertRedirect(route('ai-documents.edit'))
            ->assertSessionHas('success', 'The readers are switched off. Nothing new is sent. Documents that were still being read and are now cancelled: '.count(DocumentRead::OPEN).'.');

        $row = $this->row();
        $this->assertFalse($row->enabled);
        $this->assertSame($this->owner->id, $row->withdrawn_by);
        $this->assertNotNull($row->withdrawn_at);
        // What was agreed to stays on record.
        $this->assertSame([AiDocumentConsent::VERSION, AiDocumentConsent::notice('en')], [$row->consent_version, $row->consent_text]);
        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->assertSame(['enabled' => false, 'version' => 'ai-docs-v2', 'current' => false], $this->settings()->consent($this->organization));

        foreach (DocumentRead::OPEN as $status) {
            $read = $reads[$status]->fresh();

            $this->assertSame([DocumentRead::CANCELLED, ReadWords::CONSENT_WITHDRAWN, '0.0000', null], [$read->status, $read->error, $read->credits_held, $read->pause_reason], $status);
            $this->assertSame(
                [DocumentReadPage::READ, DocumentReadPage::SKIPPED],
                DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->whereIn('page_no', [1, 2])->orderBy('page_no')->pluck('status')->all(),
                $status,
            );
        }

        $this->assertSame(DocumentReadPage::SKIPPED, $queued->fresh()->status);
        // A page that is with the reader at this moment comes back and is saved, but is never charged.
        $this->assertSame(DocumentReadPage::RUNNING, $withReader->fresh()->status);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        $this->assertSame('500.0000', $this->credits()->free($this->organization));
        $this->assertSame('Your company switched off the AI readers, so it was cancelled.', ReadWords::error($reads[DocumentRead::RUNNING]->fresh()->error));

        // What was already read can still be checked and saved; what was closed stays as it was.
        foreach ([DocumentRead::READY, DocumentRead::FAILED, DocumentRead::CONFIRMED, DocumentRead::DISCARDED, DocumentRead::CANCELLED, DocumentRead::EXPIRED] as $status) {
            $this->assertSame([$status, null], [$reads[$status]->fresh()->status, $reads[$status]->fresh()->error], $status);
            $this->assertSame(DocumentReadPage::WAITING, DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $reads[$status]->id)->where('page_no', 2)->value('status'), $status);
        }

        $this->assertSame([DocumentRead::RUNNING, '25.0000'], [$theirs->fresh()->status, $theirs->fresh()->credits_held]);
        $this->assertSame(DocumentReadPage::WAITING, $theirPage->fresh()->status);
        $this->assertTrue(AiDocumentConsent::current($other));

        $off = collect($this->audits('ai_documents.consent_off'))->sole();
        $this->assertSame(['enabled' => true, 'version' => 'ai-docs-v2'], $off->old_values);
        $this->assertSame(['enabled' => false, 'version' => 'ai-docs-v2'], $off->new_values);
        $this->assertSame([$this->organization->id, $this->owner->id], [$off->organization_id, $off->user_id]);

        $cancels = collect($this->audits('ai.read_cancelled'));
        $this->assertCount(count(DocumentRead::OPEN), $cancels);
        $this->assertSame([$this->owner->id], $cancels->pluck('user_id')->unique()->all());
        $this->assertSame([$this->organization->id], $cancels->pluck('organization_id')->unique()->all());
    }

    public function test_switching_off_twice_or_before_anything_was_agreed_changes_nothing(): void
    {
        $this->switchOff()->assertRedirect(route('ai-documents.edit'))->assertSessionHas('success', 'The readers are switched off. Nothing new is sent.');
        $this->assertNull($this->row());
        $this->assertSame([], $this->audits('ai_documents.consent_off'));

        $this->switchOn();
        $this->switchOff();
        $this->switchOff($this->member($this->organization, 'admin'));

        $this->assertCount(1, $this->audits('ai_documents.consent_off'));
        $this->assertSame($this->owner->id, $this->row()->withdrawn_by);

        // And it can be switched on again, which is a new agreement.
        $this->switchOn()->assertSessionHasNoErrors();
        $this->assertTrue(AiDocumentConsent::current($this->organization));
        $this->assertNull($this->row()->withdrawn_at);
        $this->assertCount(2, $this->audits('ai_documents.consent_on'));
    }

    public function test_a_read_that_was_being_prepared_while_the_switch_went_off_is_swept_up_by_a_second_try(): void
    {
        $this->switchOn();
        $this->switchOff();

        // A request that had passed the gate a moment earlier still made its draft.
        $late = $this->makeRead($this->organization, $this->owner, ['status' => DocumentRead::DRAFT, 'consent_version' => AiDocumentConsent::VERSION]);

        $this->assertSame(1, $this->settings()->turnOff($this->organization, $this->owner));
        $this->assertSame(DocumentRead::CANCELLED, $late->fresh()->status);
        $this->assertCount(1, $this->audits('ai_documents.consent_off'));
    }

    public function test_an_agreement_to_an_older_notice_no_longer_counts_and_can_be_renewed(): void
    {
        $this->consent($this->organization, true, 'ai-docs-v0');
        AiDocumentSettings::withoutGlobalScopes()->update(['consented_by' => $this->owner->id, 'consented_at' => now()->subMonth()]);

        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->assertSame(['enabled' => true, 'version' => 'ai-docs-v0', 'current' => false], $this->settings()->consent($this->organization));

        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('This notice has changed since your company agreed to it.')
            ->assertSee('name="accept"', false)
            ->assertSee('Switch the readers off');

        $this->switchOn()->assertSessionHasNoErrors();

        $this->assertTrue(AiDocumentConsent::current($this->organization));
        $audit = collect($this->audits('ai_documents.consent_on'))->sole();
        $this->assertSame(['enabled' => true, 'version' => 'ai-docs-v0'], $audit->old_values);
        $this->assertSame('ai-docs-v2', $audit->new_values['version']);
    }

    public function test_one_company_never_changes_or_sees_another_companys_switch(): void
    {
        $other = $this->tenant('Godavari Infra');
        $otherOwner = $this->member($other, 'owner');

        $this->switchOn($otherOwner)->assertSessionHasNoErrors();
        $this->actingAs($otherOwner)->post(self::PAGE, ['action' => 'limits', 'max_credits_per_read' => '90', 'budgets' => ['company' => ['total' => '70']]])->assertSessionHasNoErrors();

        $this->assertNull($this->row());
        $this->assertFalse(AiDocumentConsent::current($this->organization));
        $this->assertTrue(AiDocumentConsent::current($other));

        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('name="accept"', false)
            ->assertDontSee($otherOwner->name)
            ->assertDontSee('value="90"', false)
            ->assertDontSee('value="70"', false);

        $this->switchOff($this->owner);
        $this->assertTrue(AiDocumentConsent::current($other));
        $this->assertSame([$other->id], AuditLog::withoutGlobalScopes()->where('action', 'like', 'ai_documents.%')->pluck('organization_id')->unique()->all());

        // The service refuses a person of another company outright, whatever a controller passes it.
        foreach ([
            fn () => $this->settings()->turnOn($other, $this->owner, 'en'),
            fn () => $this->settings()->turnOff($other, $this->owner),
            fn () => $this->settings()->saveLimits($other, $this->owner, ['max_credits_per_read' => '5']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A person changed another company\'s reader settings.');
            } catch (LogicException) {
                $this->assertTrue(AiDocumentConsent::current($other));
            }
        }

        $this->assertSame('90.0000', $this->row($other)->max_credits_per_read);
    }

    public function test_the_limits_form_lists_the_deployed_features_with_their_usual_limits(): void
    {
        // The stand-in readers are a BOQ, a slip and a tender reader. Nothing else is deployed.
        $this->withoutRealTextCalls();
        $this->assertSame(['boq', 'slip', 'tender'], $this->settings()->features());

        $page = $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('Spending limits')
            ->assertSee('Most credits one document may use')
            ->assertSee('Scanned BOQ pages')
            ->assertSee('Challan photos')
            ->assertSee('Tender documents')
            ->assertSee('All AI features')
            ->assertDontSee('Supplier bill photos')
            ->assertDontSee('Voice reports')
            ->assertSee('name="budgets[user][slip]"', false)
            ->assertSee('name="budgets[company][total]"', false)
            ->assertDontSee('name="budgets[user][bill]"', false)
            ->assertDontSee('name="budgets[user][total]"', false);

        // The usual limits are shown where the box is empty.
        $this->assertMatchesRegularExpression('/name="budgets\[user\]\[slip\]"[^>]*placeholder="30"/s', $page->getContent());
        $this->assertMatchesRegularExpression('/name="budgets\[company\]\[slip\]"[^>]*placeholder="150"/s', $page->getContent());
        $this->assertMatchesRegularExpression('/name="budgets\[user\]\[boq\]"[^>]*placeholder="-"/s', $page->getContent());
        $this->assertMatchesRegularExpression('/name="max_credits_per_read"[^>]*placeholder="650"/s', $page->getContent());

        // A text feature shows up once a handler is registered for it.
        config(['modules.reader_stand_ins.text_calls' => ['wording' => 'App\\Stand\\In', 'not_a_purpose' => 'App\\Stand\\In']]);
        $this->assertSame(['boq', 'slip', 'tender', 'wording'], $this->settings()->features());
        $this->actingAs($this->owner)->get(self::PAGE)->assertSee('Letters and explanations')->assertSee('name="budgets[company][wording]"', false);

        // With a text feature alone there is no reader to agree to, but there are limits to set.
        config(['modules.reader_stand_ins.read_kinds' => []]);
        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()
            ->assertSee('No reader is switched on yet.')
            ->assertSee('Spending limits')
            ->assertSee('name="budgets[user][wording]"', false)
            ->assertDontSee('Scanned BOQ pages')
            ->assertDontSee('name="budgets[user][slip]"', false)
            ->assertDontSee('Most credits one document may use');
    }

    public function test_an_owner_sets_and_clears_the_companys_own_limits(): void
    {
        $spend = fn () => app(AiSpend::class);

        $this->assertSame(['30.0000', '150.0000', null, '650.0000'], [
            $spend()->budget($this->organization, 'user', 'slip'),
            $spend()->budget($this->organization, 'company', 'slip'),
            $spend()->budget($this->organization, 'company', 'total'),
            $spend()->maxCreditsPerRead($this->organization),
        ]);

        $this->actingAs($this->owner)->post(self::PAGE, [
            'action' => 'limits',
            'max_credits_per_read' => '400',
            'budgets' => ['user' => ['slip' => '12.50', 'boq' => ''], 'company' => ['slip' => '50', 'tender' => '0', 'total' => '300']],
        ])->assertRedirect(route('ai-documents.edit'))->assertSessionHasNoErrors()->assertSessionHas('success', 'The limits are saved.');

        $row = $this->row();
        $this->assertSame('400.0000', $row->max_credits_per_read);
        $this->assertSame(['user' => ['slip' => '12.5'], 'company' => ['slip' => '50', 'tender' => '0', 'total' => '300']], $row->budgets);
        // Saving limits agrees to nothing.
        $this->assertFalse($row->enabled);
        $this->assertFalse(AiDocumentConsent::current($this->organization));

        $this->assertSame(['12.5000', '50.0000', '0.0000', '300.0000', '400.0000'], [
            $spend()->budget($this->organization, 'user', 'slip'),
            $spend()->budget($this->organization, 'company', 'slip'),
            $spend()->budget($this->organization, 'company', 'tender'),
            $spend()->budget($this->organization, 'company', 'total'),
            $spend()->maxCreditsPerRead($this->organization),
        ]);

        $audit = collect($this->audits('ai_documents.limits_changed'))->sole();
        $this->assertSame(['max_credits_per_read' => null, 'budgets' => []], $audit->old_values);
        $this->assertSame(['max_credits_per_read' => '400', 'budgets' => $row->budgets], $audit->new_values);
        $this->assertSame([$this->organization->id, $this->owner->id], [$audit->organization_id, $audit->user_id]);

        $page = $this->actingAs($this->owner)->get(self::PAGE)->assertOk();
        $this->assertMatchesRegularExpression('/name="budgets\[user\]\[slip\]"[^>]*value="12.5"/s', $page->getContent());
        $this->assertMatchesRegularExpression('/name="max_credits_per_read"[^>]*value="400"/s', $page->getContent());

        // Saving the same figures again is not a change.
        $this->actingAs($this->owner)->post(self::PAGE, ['action' => 'limits', 'max_credits_per_read' => '400', 'budgets' => ['user' => ['slip' => '12.5']]])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->audits('ai_documents.limits_changed'));

        // An empty box takes a limit back to the usual one. A figure that was not on the form stays.
        $this->actingAs($this->owner)->post(self::PAGE, [
            'action' => 'limits',
            'max_credits_per_read' => '',
            'budgets' => ['user' => ['slip' => ''], 'company' => ['slip' => '', 'tender' => '']],
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->row()->max_credits_per_read);
        $this->assertSame(['company' => ['total' => '300']], $this->row()->budgets);
        $this->assertSame(['30.0000', '150.0000', '300.0000', '650.0000'], [
            $spend()->budget($this->organization, 'user', 'slip'),
            $spend()->budget($this->organization, 'company', 'slip'),
            $spend()->budget($this->organization, 'company', 'total'),
            $spend()->maxCreditsPerRead($this->organization),
        ]);
        $this->assertCount(2, $this->audits('ai_documents.limits_changed'));
    }

    public function test_limits_that_make_no_sense_are_refused_and_nothing_is_saved(): void
    {
        $post = fn (array $fields) => $this->actingAs($this->owner)->from(self::PAGE)->post(self::PAGE, ['action' => 'limits', ...$fields]);

        // Nobody raises the most one document may use above the platform's figure, or sets it to nothing.
        $post(['max_credits_per_read' => '651'])->assertRedirect(self::PAGE)->assertSessionHasErrors('max_credits_per_read');
        // Zero is a number: the owner is told why it is refused here, and where a feature is stopped instead.
        $stop = 'The most one document may use must be more than 0. To stop a feature, enter 0 as its daily budget.';
        $post(['max_credits_per_read' => '0'])->assertSessionHasErrors(['max_credits_per_read' => $stop]);
        $this->owner->forceFill(['locale' => 'mr'])->save();
        $post(['max_credits_per_read' => '0'])->assertSessionHasErrors(['max_credits_per_read' => __($stop, [], 'mr')]);
        $this->assertNotSame($stop, __($stop, [], 'mr'));
        $this->owner->forceFill(['locale' => 'en'])->save();
        // Something that is not a number is asked for as one.
        $post(['max_credits_per_read' => '-5'])->assertSessionHasErrors(['max_credits_per_read' => 'Enter a number of credits, such as 50 or 12.5.']);
        $post(['max_credits_per_read' => '1e2'])->assertSessionHasErrors('max_credits_per_read');
        $post(['max_credits_per_read' => 'fifty'])->assertSessionHasErrors('max_credits_per_read');

        $post(['budgets' => ['user' => ['slip' => '-1']]])->assertSessionHasErrors('budgets.user.slip');
        $post(['budgets' => ['user' => ['slip' => '1,000']]])->assertSessionHasErrors('budgets.user.slip');
        $post(['budgets' => ['company' => ['slip' => '12.12345']]])->assertSessionHasErrors('budgets.company.slip');
        $post(['budgets' => ['company' => ['total' => '9999999']]])->assertSessionHasErrors('budgets.company.total');

        // A feature that is not deployed has no box, and none can be posted for it. Nor a total for one person.
        $post(['budgets' => ['user' => ['bill' => '5']]])->assertSessionHasErrors('budgets.user');
        $post(['budgets' => ['user' => ['total' => '5']]])->assertSessionHasErrors('budgets.user');
        $post(['budgets' => ['platform' => ['slip' => '5']]])->assertSessionHasErrors('budgets');
        $post(['budgets' => 'everything'])->assertSessionHasErrors('budgets');

        $this->actingAs($this->owner)->post(self::PAGE, ['action' => 'everything'])->assertSessionHasErrors('action');
        $this->actingAs($this->owner)->post(self::PAGE, [])->assertSessionHasErrors('action');

        $this->assertNull($this->row());
        $this->assertSame([], $this->audits('ai_documents.limits_changed'));
    }

    public function test_a_new_read_records_the_notice_its_company_has_agreed_to(): void
    {
        $this->assertNull(AiDocumentConsent::agreedVersion($this->organization));

        $this->switchOn();
        $this->assertSame('ai-docs-v2', AiDocumentConsent::agreedVersion($this->organization));
        $this->assertSame('ai-docs-v2', AiDocumentConsent::agreedVersion($this->organization->id));

        // Another company's agreement is not this one's.
        $this->assertNull(AiDocumentConsent::agreedVersion($this->tenant('Godavari Infra')));

        $this->switchOff();
        $this->assertNull(AiDocumentConsent::agreedVersion($this->organization));

        $this->consent($this->organization, true, 'ai-docs-v0');
        $this->assertNull(AiDocumentConsent::agreedVersion($this->organization));
    }

    public function test_every_reader_and_the_text_calls_that_send_a_persons_own_words_need_the_switch(): void
    {
        foreach (array_keys(config('modules.document_reading.kinds')) as $kind) {
            $this->assertTrue(AiDocumentConsent::covers($kind), $kind);
        }

        // What was heard for a voice report and hindrance notes leave the company as they are.
        $this->assertTrue(AiDocumentConsent::covers('voice'));
        $this->assertTrue(AiDocumentConsent::covers('hindrance_groups'));

        // These send only labels and descriptions the code prepared.
        foreach (['wording', 'rate_match', 'translate'] as $purpose) {
            $this->assertFalse(AiDocumentConsent::covers($purpose), $purpose);
        }

        // Something nobody has described needs the switch until someone decides otherwise.
        $this->assertTrue(AiDocumentConsent::covers('a_later_feature'));

        foreach (AiDocumentConsent::TEXT_PURPOSES as $purpose) {
            $this->assertArrayHasKey($purpose, config('modules.document_reading.purposes'));
        }
    }

    public function test_the_form_has_a_counter_of_its_own_and_reading_the_page_is_not_counted(): void
    {
        $this->actingAs($this->owner);

        // Another form's plain counter is used up for this person.
        for ($i = 0; $i < 10; $i++) {
            $this->post('/agent-credits/checkout', [])->assertSessionDoesntHaveErrors('throttle');
        }

        $this->postJson('/agent-credits/checkout', [])->assertTooManyRequests();

        // This form still answers, thirty times a minute.
        for ($i = 0; $i < 30; $i++) {
            $this->post(self::PAGE, ['action' => 'off'])->assertRedirect(route('ai-documents.edit'))->assertSessionDoesntHaveErrors('throttle');
        }

        $this->postJson(self::PAGE, ['action' => 'off'])->assertTooManyRequests();
        $this->from(self::PAGE)->post(self::PAGE, ['action' => 'off'])->assertRedirect(self::PAGE)
            ->assertSessionHasErrors(['throttle' => 'Too many tries in a short time. Wait a minute and try again.']);

        $this->get(self::PAGE)->assertOk();
    }

    public function test_the_switch_blocked_by_the_platform_admin_is_said_on_the_page(): void
    {
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);

        $this->actingAs($this->owner)->get(self::PAGE)->assertOk()->assertSee('The readers are switched off for your company. Please contact support.');
    }
}
