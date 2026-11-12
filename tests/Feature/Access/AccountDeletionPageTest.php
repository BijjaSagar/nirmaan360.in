<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\CompanyDeletionRequest;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\Notify\CompanyDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The website's "Delete my account" (spec D.2): the same choice as the app, behind the account password. */
class AccountDeletionPageTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private Organization $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        $this->company = $this->tenant('Deccan Builders');
        $this->owner = $this->member($this->company, 'owner');
    }

    public function test_a_member_sees_what_happens_to_their_account(): void
    {
        $member = $this->member($this->company);

        $this->actingAs($member)->get('/account/delete')->assertOk()
            ->assertSee('Your sign-in, name, email and mobile number are removed now. You cannot undo this.')
            ->assertSee('Work you recorded for Deccan Builders')
            ->assertSee('name="mode" value="account"', false)
            ->assertSee('name="password"', false)
            ->assertDontSee('name="confirm_name"', false)
            ->assertSee('Delete my account');
    }

    public function test_the_only_owner_sees_that_the_company_goes_with_them(): void
    {
        $this->member($this->company);
        $this->member($this->company, 'qs');
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));

        $this->actingAs($this->owner)->get('/account/delete')->assertOk()
            ->assertSee('Delete your account and company')
            ->assertSee('You are the only owner of Deccan Builders')
            ->assertSee('2 other people will lose access.')
            ->assertSee('It is erased after 12 Dec 2026.')
            ->assertSee('Type Deccan Builders to confirm')
            ->assertSee('name="mode" value="company"', false)
            ->assertSee('name="confirm_name"', false)
            ->assertSee(route('team.index'), false)
            ->assertSee(route('notify.data.index'), false);

        $this->member($this->tenant('Solo Co'), 'owner');
        $solo = User::where('role', 'owner')->latest('id')->first();
        $this->actingAs($solo)->get('/account/delete')->assertOk()->assertSee('No one else uses this company.');
    }

    public function test_a_pending_deletion_is_shown_with_the_way_to_cancel_it(): void
    {
        $member = $this->member($this->company);
        $request = app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');
        $date = $request->purge_after->format('d M Y');

        $this->actingAs($this->owner)->get('/account/delete')->assertOk()
            ->assertSee('Your company is set to be erased')
            ->assertSee("Deccan Builders will be erased after {$date}.")
            ->assertSee(route('notify.data.index'), false)
            ->assertSee('Cancel the deletion')
            ->assertDontSee('name="password"', false);

        $this->actingAs($member)->get('/account/delete')->assertOk()
            ->assertSee("Deccan Builders will be erased after {$date}.")
            ->assertSee('Only a company owner can cancel it.')
            ->assertSee('name="mode" value="account"', false);
    }

    public function test_a_member_deletes_their_account_and_is_signed_out(): void
    {
        $member = $this->member($this->company);

        $this->actingAs($member)->post('/account/delete', ['mode' => 'account', 'password' => 'password'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'Your account has been deleted.');

        $this->assertGuest();
        $this->assertNotNull($member->fresh()->anonymised_at);
        $this->assertSame(['mode' => 'account', 'via' => 'web'], AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->sole()->new_values);
        $this->get(route('login'))->assertOk()->assertSee('Your account has been deleted.');
    }

    public function test_the_only_owner_schedules_the_company_and_is_signed_out(): void
    {
        $response = $this->actingAs($this->owner)->post('/account/delete', ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Deccan Builders']);

        $date = CompanyDeletionRequest::sole()->purge_after->format('d M Y');
        $response->assertRedirect(route('login'))
            ->assertSessionHas('success', "Your company will be erased after {$date}. Sign in again before then to cancel.");
        $this->assertGuest();
        $this->assertSame('account', CompanyDeletionRequest::sole()->requested_via);
        $this->assertNull($this->owner->fresh()->anonymised_at);
        $this->assertTrue($this->owner->fresh()->is_active);

        // They can sign in again during the grace period.
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($this->owner->fresh());
    }

    public function test_a_wrong_password_or_name_goes_back_with_the_reason(): void
    {
        $member = $this->member($this->company);

        $this->actingAs($member)->from('/account/delete')->post('/account/delete', ['mode' => 'account', 'password' => 'not-it'])
            ->assertRedirect('/account/delete')->assertSessionHasErrors(['password' => 'That password is not right.']);
        $this->assertAuthenticatedAs($member);
        $this->assertNull($member->fresh()->anonymised_at);

        $this->actingAs($this->owner)->from('/account/delete')->post('/account/delete', ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Deccan'])
            ->assertRedirect('/account/delete')->assertSessionHasErrors(['confirm_name' => 'Type the company name exactly as shown.']);
        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_a_page_left_open_while_the_company_changed_goes_back_with_an_error(): void
    {
        $second = $this->member($this->company, 'owner');
        // The page showed "account" because another owner existed; that owner then left.
        $second->forceFill(['is_active' => false])->save();

        $this->actingAs($this->owner)->from('/account/delete')->post('/account/delete', ['mode' => 'account', 'password' => 'password'])
            ->assertRedirect('/account/delete')
            ->assertSessionHasErrors(['mode' => 'Something changed in your company. Check what will happen, then try again.']);
        $this->assertNull($this->owner->fresh()->anonymised_at);
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_everyone_signed_in_finds_it_in_the_menu(): void
    {
        foreach (['owner', 'admin', 'engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->company, $role))->get('/devices')->assertOk()
                ->assertSee(route('account.delete'), false)->assertSee('Delete my account');
        }
    }

    public function test_a_contractor_login_can_delete_its_account(): void
    {
        $party = Party::create(['organization_id' => $this->company->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $this->company->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);

        // Shown in the portal's own frame, with no company menu.
        $this->actingAs($contractor)->get('/account/delete')->assertOk()
            ->assertSee('Contractor portal')->assertSee('Shree Formwork')->assertDontSee(route('team.index'), false)
            ->assertSee('name="mode" value="account"', false);

        $this->post('/account/delete', ['mode' => 'account', 'password' => 'password'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull($contractor->fresh()->anonymised_at);
    }

    public function test_an_offline_plan_company_can_delete_too(): void
    {
        $offline = $this->tenant('Phone Co', 'offline');
        $owner = $this->member($offline, 'owner');
        $member = $this->member($offline, 'engineer');

        $this->actingAs($member)->get('/offline/devices')->assertOk()->assertSee(route('account.delete'), false);
        $this->get('/account/delete')->assertOk();
        $this->post('/account/delete', ['mode' => 'account', 'password' => 'password'])->assertRedirect(route('login'));
        $this->assertNotNull($member->fresh()->anonymised_at);

        $this->actingAs($owner)->get('/account/delete')->assertOk()->assertSee('name="mode" value="company"', false);
        $this->post('/account/delete', ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Phone Co'])->assertRedirect(route('login'));
        $this->assertSame('pending', CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $offline->id)->sole()->status);
    }

    public function test_signed_out_visitors_are_sent_to_sign_in(): void
    {
        $this->get('/account/delete')->assertRedirect(route('login'));
        $this->post('/account/delete', ['mode' => 'account', 'password' => 'password'])->assertRedirect(route('login'));
        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_the_page_is_translated(): void
    {
        $member = $this->member($this->company);
        $member->forceFill(['locale' => 'mr'])->save();

        foreach (['hi', 'mr'] as $locale) {
            $this->actingAs($member)->withSession(['locale' => $locale])->get('/account/delete')->assertOk()
                ->assertSee(__('Delete my account', [], $locale))
                ->assertSee(__('Your sign-in, name, email and mobile number are removed now. You cannot undo this.', [], $locale))
                ->assertDontSee('Your sign-in, name, email and mobile number are removed now.');
        }
    }
}
