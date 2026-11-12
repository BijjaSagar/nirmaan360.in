<?php

namespace Tests\Feature\Access;

use App\Models\Organization;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The platform admin list holds addresses. Nobody else may come to own one of them, in any letter case. */
class PlatformAdminEmailTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['buildos.platform_admins' => ['ops@example.com', 'later@example.com']]);

        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'role' => 'owner', 'email' => 'ops@example.com', 'is_active' => true]);
    }

    private function register(string $email)
    {
        return $this->post('/register', [
            'company' => 'Copycat Builders',
            'name' => 'Stranger',
            'email' => $email,
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);
    }

    public function test_signing_up_with_a_listed_address_in_other_letter_case_is_refused(): void
    {
        foreach (['OPS@example.com', 'Ops@Example.com', ' ops@example.com '] as $email) {
            $this->register($email)->assertSessionHasErrors(['email' => __('This email already has an account.')]);
        }

        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['ops@example.com'])->count());
        $this->assertGuest();
    }

    public function test_a_listed_address_nobody_holds_yet_cannot_be_taken_by_signing_up(): void
    {
        $this->register('LATER@example.com')->assertSessionHasErrors('email');
        $this->register('later@example.com')->assertSessionHasErrors('email');

        $this->assertSame(0, User::whereRaw('lower(email) = ?', ['later@example.com'])->count());
    }

    public function test_an_ordinary_address_is_stored_in_small_letters_and_its_other_cases_are_refused(): void
    {
        $this->register('  Priya.Rane@Example.com ')->assertRedirect('/dashboard');
        $this->assertSame(['priya.rane@example.com'], User::where('name', 'Stranger')->pluck('email')->all());

        Auth::logout();
        $this->register('PRIYA.RANE@example.com')->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('name', 'Stranger')->count());
    }

    public function test_only_the_first_account_with_a_listed_address_is_an_admin(): void
    {
        // As a copy made before this fix would sit in the database.
        $copy = User::factory()->create(['organization_id' => $this->tenant('Copycat')->id, 'role' => 'owner', 'email' => 'OPS@example.com', 'is_active' => true]);

        $this->assertTrue($this->admin->isPlatformAdmin());
        $this->assertFalse($copy->isPlatformAdmin());

        $this->actingAs($copy)->get('/admin/seo')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/seo')->assertOk();
    }

    public function test_the_admin_flag_still_works_on_its_own(): void
    {
        $flagged = User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'staff@example.com', 'is_platform_admin' => true]);
        $plain = User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'plain@example.com']);

        $this->assertTrue($flagged->isPlatformAdmin());
        $this->assertFalse($plain->isPlatformAdmin());
    }

    public function test_a_company_cannot_invite_a_listed_address(): void
    {
        $this->actingAs($this->owner)
            ->post('/team', ['email' => 'Later@Example.com', 'role' => 'engineer', 'project_access' => 'all'])
            ->assertSessionHasErrors(['email' => __('This email already has an account.')]);

        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_an_invitation_sent_before_the_address_was_listed_does_not_open(): void
    {
        config(['buildos.platform_admins' => ['ops@example.com']]);
        $this->actingAs($this->owner)->post('/team', ['email' => 'later@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertSessionHasNoErrors();
        $link = session('invite_link');
        $this->flushSession();
        Auth::logout();

        config(['buildos.platform_admins' => ['ops@example.com', 'later@example.com']]);
        $this->post($link, ['name' => 'Someone', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);

        $this->assertSame(0, User::where('email', 'later@example.com')->count());
        $this->assertGuest();
    }
}
