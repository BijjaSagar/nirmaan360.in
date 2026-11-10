<?php

namespace Tests\Feature\Public;

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
            ->assertSee('Google Firebase Cloud Messaging');
    }
}
