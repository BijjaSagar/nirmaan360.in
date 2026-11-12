<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * Forgot password matches an email in any letter case, as sign-in does. The public deletion page sends
 * people who sign in with an OTP or Microsoft here first, to set the password the deletion asks for.
 */
class PasswordResetEmailCaseTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->user = $this->member($this->tenant(), 'engineer');
        $this->user->forceFill(['email' => 'ravi.patil@example.com'])->save();
    }

    public function test_a_reset_link_is_sent_for_the_address_typed_in_another_letter_case(): void
    {
        $this->post('/forgot-password', ['email' => 'Ravi.Patil@EXAMPLE.com'])
            ->assertRedirect()
            ->assertSessionHas('success', 'If this email is registered, a reset link has been sent.');

        Notification::assertSentTo($this->user, ResetPassword::class);
        // The token is kept under the stored address, which the broker and the deletion's clean-up both look up.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'ravi.patil@example.com']);
    }

    public function test_an_unknown_address_gets_the_same_reply_and_nothing_is_sent(): void
    {
        $this->post('/forgot-password', ['email' => 'Nobody@Example.com'])
            ->assertRedirect()
            ->assertSessionHas('success', 'If this email is registered, a reset link has been sent.');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_the_new_password_is_set_when_the_address_is_retyped_in_another_letter_case(): void
    {
        $token = Password::createToken($this->user);

        $this->post('/reset-password', ['token' => $token, 'email' => 'RAVI.PATIL@example.com', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Password changed. Please sign in.');

        $this->assertTrue(Hash::check('brand-new-password', $this->user->fresh()->password));
        $this->assertSame('ravi.patil@example.com', $this->user->fresh()->email);
    }

    public function test_a_wrong_token_still_fails_in_any_letter_case(): void
    {
        Password::createToken($this->user);

        $this->from('/reset-password/not-it')->post('/reset-password', ['token' => 'not-it', 'email' => 'Ravi.Patil@example.com', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])
            ->assertRedirect('/reset-password/not-it')
            ->assertSessionHasErrors(['email' => 'This reset link is invalid or has expired.']);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_two_old_accounts_that_differ_only_by_case_need_the_exact_spelling(): void
    {
        // Rows from before emails were stored in lower case. A guess between them would hand one person the other's reset.
        $other = $this->member($this->tenant('Second Co'), 'engineer');
        $other->forceFill(['email' => 'Ravi.Patil@example.com'])->save();

        $this->post('/forgot-password', ['email' => 'RAVI.PATIL@EXAMPLE.COM'])->assertRedirect();
        Notification::assertNothingSent();

        $this->post('/forgot-password', ['email' => 'Ravi.Patil@example.com'])->assertRedirect();
        Notification::assertSentTo($other, ResetPassword::class);
        Notification::assertNotSentTo($this->user, ResetPassword::class);
    }
}
