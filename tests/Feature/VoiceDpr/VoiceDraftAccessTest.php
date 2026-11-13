<?php

namespace Tests\Feature\VoiceDpr;

use App\Models\DprVoiceDraft;
use App\Models\User;
use App\Services\Website\AiOffer;
use App\Support\PlatformSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Who may send a spoken report, who may read one, and what refuses it before anything is kept or sent.
 */
class VoiceDraftAccessTest extends VoiceDprTestCase
{
    private function assertNothingKept(): void
    {
        $this->assertSame(0, DprVoiceDraft::withoutGlobalScopes()->count());
        $this->assertSame([], $this->queuedCalls());
        Http::assertNothingSent();
    }

    private function readyDraft(User $by): int
    {
        $id = $this->send($by)->assertStatus(202)->json('id');
        $this->fakeModel([$this->answer($this->form())]);
        $this->runCall($this->draft($id)->ai_text_call_id);

        return $id;
    }

    public function test_people_who_write_dprs_may_send_and_others_may_not(): void
    {
        $this->send($this->engineer)->assertStatus(202);
        $this->send($this->member($this->organization, 'pm'))->assertStatus(202);
        $this->send($this->owner)->assertStatus(202);

        foreach (['viewer', 'store', 'qs', 'accounts'] as $role) {
            $this->send($this->member($this->organization, $role))->assertForbidden();
        }

        $this->assertSame(3, DprVoiceDraft::withoutGlobalScopes()->count());
    }

    public function test_a_plan_without_the_ai_features_is_refused_without_being_told_to_upgrade(): void
    {
        $this->organization->update(['plan' => 'contractor']);

        $response = $this->send($this->engineer->fresh())->assertForbidden();
        $this->assertDoesNotMatchRegularExpression('/upgrade|\\bbuy\\b/i', (string) $response->json('message'));
        $this->assertNothingKept();
    }

    public function test_another_companys_project_and_a_project_out_of_reach_do_not_exist(): void
    {
        $outsider = $this->member($other = $this->tenant('Godavari Infra'), 'engineer');
        $this->consent($other);
        $this->send($outsider)->assertNotFound();

        $limited = $this->member($this->organization, 'engineer');
        $limited->forceFill(['limited_to_projects' => true])->save();
        $limited->assignedProjects()->attach($this->makeProject($this->organization, ['name' => 'Canal Road Block']), ['organization_id' => $this->organization->id]);
        $this->send($limited)->assertNotFound();

        $this->assertNothingKept();

        // Once the project is theirs, they may send.
        $limited->assignedProjects()->attach($this->project, ['organization_id' => $this->organization->id]);
        $this->send($limited->fresh())->assertStatus(202);
    }

    public function test_only_the_person_who_spoke_a_draft_reads_or_links_it(): void
    {
        $id = $this->readyDraft($this->engineer);
        $link = ['dpr_client_uuid' => (string) Str::uuid(), 'changed' => []];

        $outsider = $this->member($other = $this->tenant('Godavari Infra'), 'owner');
        $this->consent($other);

        foreach ([$this->member($this->organization, 'engineer'), $this->owner, $outsider] as $someoneElse) {
            $this->look($someoneElse, $id)->assertNotFound();
            $this->link($someoneElse, $id, $link)->assertNotFound();
        }

        $this->assertNull($this->draft($id)->dpr_client_uuid);

        // Taken off the project, the person no longer reaches it either.
        $this->engineer->forceFill(['limited_to_projects' => true])->save();
        $this->look($this->engineer->fresh(), $id)->assertNotFound();

        $this->engineer->assignedProjects()->attach($this->project, ['organization_id' => $this->organization->id]);
        $this->look($this->engineer->fresh(), $id)->assertOk()->assertJson(['status' => 'ready']);
        $this->link($this->engineer->fresh(), $id, $link)->assertOk();
    }

    public function test_without_the_companys_consent_nothing_is_taken(): void
    {
        $this->consent($this->organization, false);
        $this->send($this->engineer)->assertStatus(409)->assertJson(['code' => 'consent_required']);

        // An agreement to an older notice does not count.
        $this->consent($this->organization, true, 'ai-docs-v1');
        $this->send($this->engineer)->assertStatus(409)->assertJson(['code' => 'consent_required']);

        $this->assertNothingKept();
    }

    public function test_nothing_is_taken_until_the_readers_are_released_and_voice_is_accepted(): void
    {
        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => null]);
        $this->send($this->engineer)->assertStatus(409)->assertJson(['code' => 'not_released', 'message' => 'Voice reports are not switched on yet.']);

        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => '1', 'ai_readers_released' => '0']);
        $this->send($this->engineer)->assertStatus(409)->assertJson(['code' => 'not_released']);

        $this->assertNothingKept();
    }

    public function test_words_not_heard_on_the_phone_itself_are_refused(): void
    {
        $words = 'Voice reports are taken only from phones that turn speech into text on the phone itself.';

        $payload = $this->payload();
        unset($payload['speech']);
        $this->send($this->engineer, $payload)->assertStatus(422)->assertJson(['code' => 'on_device_required', 'message' => $words]);

        foreach (['google_cloud', 'android_default', '', null] as $engine) {
            $this->send($this->engineer, $this->payload(['speech' => ['engine' => $engine, 'os' => 'android 14 (sdk 34)', 'offline_checked' => true]]))
                ->assertStatus(422)->assertJson(['code' => 'on_device_required']);
        }

        $this->send($this->engineer, $this->payload(['speech' => 'android_on_device']))->assertStatus(422)->assertJson(['code' => 'on_device_required']);
        $this->assertNothingKept();

        // The iPhone's own recogniser is the other one taken.
        $this->send($this->engineer, $this->payload(['platform' => 'ios', 'speech' => ['engine' => 'ios_on_device', 'os' => 'iOS 26.1', 'offline_checked' => true]]))->assertStatus(202);

        // Only while the server takes words from the phone's own recognisers alone.
        config(['modules.voice_dpr.on_device_only' => false]);
        $this->send($this->engineer, $payload)->assertStatus(202);
    }

    public function test_a_persons_daily_voice_budget_stops_the_twenty_first_credit(): void
    {
        $this->spentOnVoice($this->organization, $this->engineer, '19.5000');
        $this->send($this->engineer)->assertStatus(202);

        $this->spentOnVoice($this->organization, $this->engineer, '0.4000');
        $this->send($this->engineer)->assertStatus(402)->assertJson(['code' => 'daily_budget', 'feature' => 'voice', 'scope' => 'user']);

        // Not kept, so the same words can be sent again tomorrow.
        $this->assertSame(1, DprVoiceDraft::withoutGlobalScopes()->count());
        $this->assertCount(1, $this->queuedCalls());

        // Another person's budget is their own.
        $this->send($this->member($this->organization, 'engineer'))->assertStatus(202);
    }

    public function test_reading_and_linking_do_not_depend_on_the_provider_or_the_consent(): void
    {
        $id = $this->readyDraft($this->engineer);

        $this->consent($this->organization, false);
        app(PlatformSettings::class)->set(['anthropic_api_key' => null, 'ai_readers_released' => '0', AiOffer::VOICE_ACCEPTED => null]);

        $this->look($this->engineer, $id)->assertOk()->assertJson(['status' => 'ready']);
        $this->link($this->engineer, $id, ['dpr_client_uuid' => (string) Str::uuid(), 'changed' => ['work.0.qty']])->assertOk()->assertJson(['linked' => true]);
    }

    public function test_sending_has_a_counter_of_its_own_and_watching_leaves_the_one_sync_shares(): void
    {
        $limiter = app(RateLimiter::class);

        // Fifty other requests this minute: more than a counter of ten shared with them would allow.
        foreach (range(1, 50) as $ignored) {
            $limiter->hit(sha1((string) $this->engineer->id), 60);
        }

        foreach (range(1, 10) as $ignored) {
            $id = $this->send($this->engineer)->assertStatus(202)->json('id');
        }

        $this->send($this->engineer)->assertStatus(429);

        // Past the shared counter, the phone still watches its draft.
        foreach (range(1, 300) as $ignored) {
            $limiter->hit(sha1((string) $this->engineer->id), 60);
        }

        Sanctum::actingAs($this->engineer);
        $this->getJson('/api/v1/me')->assertStatus(429);
        $this->look($this->engineer, $id)->assertOk();
    }

    public function test_the_app_is_told_who_may_speak_a_report(): void
    {
        $can = function (User $user): mixed {
            Sanctum::actingAs($user);

            return $this->getJson('/api/v1/document-reading/status')->assertOk()->json('can.voice_dpr');
        };

        $this->assertTrue($can($this->engineer));
        $this->assertTrue($can($this->owner));
        $this->assertFalse($can($this->member($this->organization, 'viewer')));
        $this->assertFalse($can($this->member($this->organization, 'store')));

        // Until the platform admin records that speech stays on the phone, nobody is offered it.
        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => null]);
        $this->assertFalse($can($this->engineer));
    }

    public function test_refusals_are_worded_in_the_persons_language(): void
    {
        $payload = $this->payload();
        unset($payload['speech']);

        $this->engineer->update(['locale' => 'mr']);
        $this->send($this->engineer->fresh(), $payload)->assertStatus(422)
            ->assertJson(['message' => 'व्हॉइस अहवाल फक्त अशाच फोनवरून घेतले जातात जे बोलण्याचे मजकुरात रूपांतर फोनवरच करतात.']);

        $this->engineer->update(['locale' => 'hi']);
        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => null]);
        $this->send($this->engineer->fresh())->assertStatus(409)->assertJson(['message' => 'वॉइस रिपोर्ट अभी चालू नहीं की गई हैं।']);
    }

    public function test_the_website_names_voice_by_its_real_route_once_speech_was_shown_to_stay_on_the_phone(): void
    {
        $this->withoutVite();
        $this->assertTrue(Route::has(AiOffer::VOICE_ROUTE));

        // Released, connected and accepted by the platform admin, as set up for these tests.
        $this->get('/ai')->assertOk()->assertSee('Voice DPR')->assertSee('It becomes a draft for you to check and send.');

        app(PlatformSettings::class)->set([AiOffer::VOICE_ACCEPTED => null]);
        $this->get('/ai')->assertOk()->assertDontSee('Voice DPR');
        Http::assertNothingSent();
    }
}
