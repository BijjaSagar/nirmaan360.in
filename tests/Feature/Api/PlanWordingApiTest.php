<?php

namespace Tests\Feature\Api;

use App\Exceptions\PlanLimitReachedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The field app shows a refusal's message as it is, and Apple does not let it send people to pay outside the app
 * (guideline 3.1.1, and no prompt to pay elsewhere on the India storefront). So a plan refusal on the API never
 * says upgrade, buy, renew, billing or pricing, while the website keeps its own wording.
 */
class PlanWordingApiTest extends ApiTestCase
{
    private const API_MODULE = "Your company's plan does not include this module. Your company's owner can check the plan.";

    private const WEB_MODULE = 'Your plan does not include this module. Upgrade to continue.';

    private function freeOwner(string $locale = 'en'): object
    {
        $organization = $this->tenant('Free Co', 'free');
        $owner = $this->member($organization, 'owner');
        $owner->update(['locale' => $locale]);

        return (object) ['organization' => $organization, 'owner' => $owner, 'project' => $this->makeProject($organization)];
    }

    /** What the app can show: the message and the field errors. A debug trace, if any, is left out. */
    private function assertNoPaymentPrompt(TestResponse $response): void
    {
        $shown = json_encode(Arr::only($response->json(), ['message', 'errors']), JSON_UNESCAPED_UNICODE);

        foreach (['upgrade', 'buy', 'renew', 'billing', 'pricing', 'purchase', 'अपग्रेड', 'ख़रीद', 'खरेदी'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $shown);
        }
    }

    public function test_a_module_outside_the_plan_answers_the_api_with_no_upgrade_prompt(): void
    {
        $free = $this->freeOwner();
        $this->signIn($free->owner);

        // Core routes and routes from module files loaded by routes/api.php.
        foreach (['/assets', '/agents', "/projects/{$free->project->id}/site-dashboard", "/projects/{$free->project->id}/procurement/snapshot", '/checklist-templates'] as $path) {
            $response = $this->getJson(self::API.$path)->assertForbidden()->assertJsonPath('message', self::API_MODULE);
            $this->assertNoPaymentPrompt($response);
        }
    }

    public function test_a_file_upload_for_a_module_outside_the_plan_has_no_upgrade_prompt(): void
    {
        $free = $this->freeOwner();
        $this->signIn($free->owner);
        config(['buildos.plans.free.features' => array_values(array_diff(config('buildos.plans.free.features'), ['site']))]);

        $response = $this->post(self::API.'/sync/upload', [
            'type' => 'dpr_photo', 'client_uuid' => (string) Str::uuid(), 'parent_id' => 1, 'file' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('message', self::API_MODULE);

        $this->assertNoPaymentPrompt($response);
    }

    public function test_the_api_wording_is_in_hindi_and_marathi_too(): void
    {
        foreach ([
            'hi' => 'आपकी कंपनी के प्लान में यह मॉड्यूल शामिल नहीं है। आपकी कंपनी के मालिक प्लान जाँच सकते हैं।',
            'mr' => 'तुमच्या कंपनीच्या प्लॅनमध्ये हे मॉड्यूल समाविष्ट नाही. तुमच्या कंपनीचे मालक प्लॅन तपासू शकतात.',
        ] as $locale => $message) {
            $this->signIn($this->freeOwner($locale)->owner);

            $this->assertNoPaymentPrompt($this->getJson(self::API.'/assets')->assertForbidden()->assertJsonPath('message', $message));
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_the_website_keeps_its_upgrade_wording(): void
    {
        $free = $this->freeOwner();
        $this->actingAs($free->owner);

        $this->get('/assets')->assertForbidden()->assertSee(self::WEB_MODULE)->assertDontSee('can check the plan');
        $this->get('/agents')->assertForbidden()->assertSee(self::WEB_MODULE);

        // The plan limit on a web form keeps its own wording too.
        $this->post('/projects', [])->assertForbidden()->assertSee('Your plan project limit has been reached. Upgrade to add more.');
    }

    /** The rule bootstrap/app.php uses for JSON: a web address that asks for JSON is answered like the API. */
    public function test_a_json_request_to_a_website_address_gets_the_api_wording(): void
    {
        $this->actingAs($this->freeOwner()->owner);

        $this->getJson('/assets')->assertForbidden()->assertJsonPath('message', self::API_MODULE);
    }

    /** Thrown outside any request, as a console command or a queued job would, it keeps the website wording. */
    public function test_the_project_limit_outside_a_request_keeps_the_website_wording(): void
    {
        $this->assertSame('Your plan project limit has been reached. Upgrade to add more.', (new PlanLimitReachedException)->getMessage());
    }
}
