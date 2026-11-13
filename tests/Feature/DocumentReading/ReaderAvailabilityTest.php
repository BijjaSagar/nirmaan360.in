<?php

namespace Tests\Feature\DocumentReading;

use App\Models\User;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ProviderBlock;
use App\Services\DocumentReading\ReaderAvailability;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Feature\DocumentReading\Support\FakeOffer;

/** What the field app is told before it shows any AI button: GET /api/v1/document-reading/status. */
class ReaderAvailabilityTest extends PageReadingTestCase
{
    private function ask(User $user): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/document-reading/status');
    }

    private function deployScan(): void
    {
        config(['modules.reader_stand_ins.reader_offers' => ['boq_scan' => FakeOffer::class]]);
    }

    public function test_it_says_whether_the_readers_can_be_offered_and_to_this_person(): void
    {
        $this->deployScan();

        $this->ask($this->owner)->assertOk()->assertExactJson([
            'released' => true,
            'connected' => true,
            'plan' => true,
            'consent' => ['enabled' => true, 'version' => AiDocumentConsent::VERSION, 'current' => true],
            'can' => ['boq_scan' => true, 'slip_challan' => false, 'voice_dpr' => false],
            'credits_free' => '500.0000',
            'voice' => ['on_device_only' => true],
        ]);

        // Asking sends nothing to the provider.
        Http::assertNothingSent();
    }

    public function test_what_a_person_can_use_follows_their_own_permissions(): void
    {
        $this->deployScan();

        // A project manager may change the BOQ and use AI, and may see the credits page.
        $this->ask($this->member($this->organization, 'pm'))->assertJson(['can' => ['boq_scan' => true], 'credits_free' => '500.0000']);

        // An engineer uses AI but may not change the BOQ, and is not shown the balance.
        $engineer = $this->ask($this->member($this->organization, 'engineer'))->assertOk();
        $this->assertFalse($engineer->json('can.boq_scan'));
        $this->assertNull($engineer->json('credits_free'));
        $this->assertTrue($engineer->json('released'));

        // A viewer sees the BOQ but may not use the AI features, so nothing is offered.
        $viewer = $this->member($this->organization, 'viewer');
        $this->assertSame(['boq_scan' => false, 'slip_challan' => false, 'voice_dpr' => false], $this->ask($viewer)->json('can'));
    }

    public function test_a_feature_that_is_not_deployed_is_never_offered(): void
    {
        $this->assertSame(['boq_scan' => false, 'slip_challan' => false, 'voice_dpr' => false], $this->ask($this->owner)->json('can'));
    }

    public function test_it_reports_each_reason_the_readers_are_not_available_without_refusing_the_question(): void
    {
        $this->deployScan();
        $settings = app(PlatformSettings::class);

        $settings->set(['ai_readers_released' => '0']);
        $this->ask($this->owner)->assertOk()->assertJson(['released' => false, 'connected' => true, 'can' => ['boq_scan' => true]]);
        $settings->set(['ai_readers_released' => '1']);

        $settings->set(['anthropic_api_key' => null]);
        $this->ask($this->owner)->assertOk()->assertJson(['released' => true, 'connected' => false]);
        $this->connect();

        // The provider is refusing every call for now. The app is not told why.
        ProviderBlock::set(ModelGatewayException::BILLING);
        $this->ask($this->owner)->assertOk()->assertJson(['connected' => false]);
        Cache::forget(ProviderBlock::KEY);

        $this->consent($this->organization, false);
        $this->ask($this->owner)->assertOk()->assertJson(['consent' => ['enabled' => false, 'version' => AiDocumentConsent::VERSION, 'current' => false]]);
        $this->consent($this->organization, true, 'ai-docs-v0');
        $this->ask($this->owner)->assertOk()->assertJson(['consent' => ['enabled' => true, 'version' => 'ai-docs-v0', 'current' => false]]);
    }

    public function test_a_company_on_a_plan_without_ai_is_told_so_and_offered_nothing(): void
    {
        $this->deployScan();
        $small = $this->tenant('Tapi Builders', 'contractor');
        $owner = $this->member($small, 'owner');

        $this->ask($owner)->assertOk()->assertJson([
            'plan' => false,
            'consent' => ['enabled' => false, 'version' => null, 'current' => false],
            'can' => ['boq_scan' => false, 'slip_challan' => false, 'voice_dpr' => false],
            'credits_free' => null,
        ]);
    }

    public function test_one_company_never_sees_anothers_consent_or_balance(): void
    {
        $other = $this->tenant('Godavari Infra');
        $this->setCredits($other, '77');
        $owner = $this->member($other, 'owner');

        $this->ask($owner)->assertOk()->assertJson(['consent' => ['enabled' => false, 'current' => false], 'credits_free' => '77.0000']);
        $this->ask($this->owner)->assertJson(['consent' => ['current' => true], 'credits_free' => '500.0000']);
    }

    public function test_a_class_listed_as_an_offer_must_be_one(): void
    {
        config(['modules.reader_stand_ins.reader_offers' => ['boq_scan' => \stdClass::class]]);

        $this->expectException(LogicException::class);
        app(ReaderAvailability::class)->can($this->owner);
    }

    public function test_it_needs_a_signed_in_person(): void
    {
        $this->getJson('/api/v1/document-reading/status')->assertUnauthorized();
    }
}
