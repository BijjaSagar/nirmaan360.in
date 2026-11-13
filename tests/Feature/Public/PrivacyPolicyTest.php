<?php

namespace Tests\Feature\Public;

use App\Services\Notify\CompanyDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The store listings declare what the phone app collects, so the policy they link to must say the same. */
class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_names_what_the_phone_app_collects(): void
    {
        $this->withoutVite();

        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('reads your phone\'s precise location once and saves it with that photo', false)
            ->assertSee('does not read your location at any other time or in the background')
            ->assertSee('A notification token for your phone, only after you turn on alerts in the app.')
            ->assertSee('Google Firebase Cloud Messaging')
            ->assertSee('Details of workers and contractors your team adds, such as their name and phone number.');
    }

    /** The AI agents send records to their model provider, so the policy names it. There is no opt-in to training. */
    public function test_the_policy_names_the_ai_model_provider_and_promises_no_training(): void
    {
        $this->withoutVite();

        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('The AI agents on the website use an AI model provider, Anthropic: when a company uses the agents, what is asked of them and the records they read to answer are sent to Anthropic.')
            ->assertSee('Nothing is sent to it for a company that does not use the agents.')
            ->assertSee('We do not use your records to train AI models.')
            ->assertDontSee('opt in')
            ->assertDontSee('shared AI models');
    }

    /** Google Play and Apple compare the policy with the deletion the app offers, so it describes that deletion. */
    public function test_the_policy_explains_deleting_an_account_and_what_is_kept(): void
    {
        $this->withoutVite();

        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('<h2 id="deleting-your-account">Deleting your account</h2>', false)
            ->assertSee(route('account-deletion'), false)
            ->assertSee('in the app, under Settings, Delete account, or on the website, under Account, Delete my account')
            ->assertSee(route('contact', ['type' => 'support']), false)
            ->assertSee('If you are a member of a company, your account is deleted straight away')
            ->assertSee('show "Deleted user"', false)
            ->assertSee('with the network address each action came from')
            ->assertSee('deleting your account deletes the company. It keeps working for '.CompanyDeletionService::GRACE_DAYS.' days, and any owner can cancel by signing in during that time: in the app under Settings, Delete account, or on the website under Account, Delete my account.')
            ->assertSee('we email you when it is done')
            ->assertSee('are kept as Indian tax law requires')
            ->assertSee('Backup copies are overwritten on a rolling schedule.')
            // The old promise named no timeline and no exceptions.
            ->assertDontSee('How long we keep it')
            ->assertDontSee('If you ask us to delete your account, we remove your data except where the law requires us to keep it.');
    }
}
