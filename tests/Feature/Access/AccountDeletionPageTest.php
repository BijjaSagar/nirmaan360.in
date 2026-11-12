<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\CompanyDeletionRequest;
use App\Models\Organization;
use App\Models\Party;
use App\Models\User;
use App\Services\Access\AccountDeletionService;
use App\Services\Notify\CompanyDeletionService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->assertSee('It is erased after 12 Dec 2026. Until then the company keeps working, and you can cancel by signing in again and opening Account, Delete my account.')
            ->assertDontSee('opening Company data')
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

        // The cancel is on this page itself, which every plan opens, not a link to Company data.
        $this->actingAs($this->owner)->get('/account/delete')->assertOk()
            ->assertSee('Your company is set to be erased')
            ->assertSee("Deccan Builders will be erased after {$date}.")
            ->assertSee('action="'.route('account.cancel').'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertSee('Cancel the deletion')
            ->assertDontSee('name="password"', false);

        $this->actingAs($member)->get('/account/delete')->assertOk()
            ->assertSee("Deccan Builders will be erased after {$date}.")
            ->assertSee('Only a company owner can cancel it.')
            ->assertDontSee('Cancel the deletion')
            ->assertDontSee('name="_method" value="DELETE"', false)
            ->assertSee('name="mode" value="account"', false);
    }

    public function test_another_owner_is_told_before_cancelling_that_it_deletes_the_requester(): void
    {
        // Asha, the only owner, asks to delete her account, so the company is scheduled. Then Kiran is made an owner.
        $this->owner->forceFill(['name' => 'Asha Deshmukh'])->save();
        app(AccountDeletionService::class)->deleteAccount($this->owner->fresh(), 'company', 'password', 'Deccan Builders', 'web');
        $kiran = $this->member($this->company, 'owner');
        $member = $this->member($this->company);
        $warning = 'Cancelling keeps the company. Asha Deshmukh asked for their own account to be deleted, so it is deleted when you cancel.';

        $this->actingAs($kiran)->get('/account/delete')->assertOk()->assertSeeInOrder([$warning, 'Cancel the deletion']);
        $this->get('/company-data')->assertOk()->assertSeeInOrder([$warning, 'Cancel the deletion']);

        foreach (['hi', 'mr'] as $locale) {
            $translated = __('Cancelling keeps the company. :name asked for their own account to be deleted, so it is deleted when you cancel.', ['name' => 'Asha Deshmukh'], $locale);
            $this->withSession(['locale' => $locale])->get('/account/delete')->assertOk()->assertSee($translated)->assertDontSee($warning);
            $this->withSession(['locale' => $locale])->get('/company-data')->assertOk()->assertSee($translated)->assertDontSee($warning);
        }

        // Asha herself, signed in again, keeps her account by cancelling, so she is not warned.
        $this->actingAs($this->owner->fresh())->withSession(['locale' => 'en']);
        $this->get('/account/delete')->assertOk()->assertSee('Cancel the deletion')->assertDontSee('asked for their own account');
        $this->get('/company-data')->assertOk()->assertSee('Cancel the deletion')->assertDontSee('asked for their own account');

        // Someone who cannot cancel sees no cancel and no warning.
        $this->actingAs($member)->get('/account/delete')->assertOk()->assertDontSee('asked for their own account');

        // As warned, Kiran's cancel deletes Asha.
        $this->actingAs($kiran)->delete('/account/delete')->assertRedirect(route('account.delete'));
        $this->assertNotNull($this->owner->fresh()->anonymised_at);
    }

    public function test_no_warning_when_the_cancel_deletes_nobody(): void
    {
        $kiran = $this->member($this->company, 'owner');

        // A request from the Company data page deletes nobody when it is cancelled.
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');
        $this->actingAs($kiran)->get('/account/delete')->assertOk()->assertSee('Cancel the deletion')->assertDontSee('asked for their own account');
        $this->get('/company-data')->assertOk()->assertSee('Cancel the deletion')->assertDontSee('asked for their own account');

        // Nor does one whose requester has already been deleted.
        CompanyDeletionRequest::sole()->update(['requested_via' => 'account']);
        app(AccountDeletionService::class)->deleteBySupport($this->owner->fresh());
        $this->assertNotNull($this->owner->fresh()->anonymised_at);
        $this->get('/account/delete')->assertOk()->assertSee('Cancel the deletion')->assertDontSee('asked for their own account');
        $this->get('/company-data')->assertOk()->assertDontSee('asked for their own account');
    }

    public function test_an_owner_cancels_on_this_page_and_everyone_is_told(): void
    {
        $member = $this->member($this->company);
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');

        $this->actingAs($this->owner)->from('/account/delete')->delete('/account/delete')
            ->assertRedirect(route('account.delete'))
            ->assertSessionHas('success', 'Deletion cancelled. Your company stays as it is.');

        $this->assertSame('cancelled', CompanyDeletionRequest::sole()->status);
        $this->assertSame($this->owner->id, CompanyDeletionRequest::sole()->cancelled_by);
        $this->assertNull($this->owner->fresh()->anonymised_at);
        foreach ([$this->owner, $member] as $person) {
            $this->assertCount(1, $person->notifications()->get()->where('data.title', 'Company deletion cancelled for Deccan Builders'));
        }

        // Back on the page, the deletion is gone and the owner is offered the company deletion again.
        $this->get('/account/delete')->assertOk()
            ->assertSee('Deletion cancelled. Your company stays as it is.')
            ->assertDontSee('Your company is set to be erased')
            ->assertSee('name="mode" value="company"', false);
    }

    public function test_an_offline_plan_only_owner_can_cancel_on_the_website(): void
    {
        $offline = $this->tenant('Phone Co', 'offline');
        $owner = $this->member($offline, 'owner');

        $this->actingAs($owner)->post('/account/delete', ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Phone Co'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', fn (string $message) => str_ends_with($message, 'Sign in again before then to cancel.'));
        $this->assertGuest();

        // Signed in again, as the message says. Company data is closed on this plan, so the page neither links to it nor needs it.
        $this->actingAs($owner->fresh());
        $this->get('/company-data')->assertRedirect(route('offline.devices.index'));
        $this->get('/account/delete')->assertOk()
            ->assertSee('Cancel the deletion')
            ->assertSee('action="'.route('account.cancel').'"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee(route('notify.data.index'), false);

        $this->delete('/account/delete')->assertRedirect(route('account.delete'))->assertSessionHas('success', 'Deletion cancelled. Your company stays as it is.');

        $this->assertSame('cancelled', CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $offline->id)->sole()->status);
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertNull($owner->fresh()->anonymised_at);
    }

    public function test_the_offline_plan_page_does_not_send_the_owner_to_pages_the_plan_closes(): void
    {
        $offline = $this->tenant('Phone Co', 'offline');
        $owner = $this->member($offline, 'owner');
        $this->member($offline, 'engineer');

        $this->actingAs($owner);
        $this->get('/team')->assertRedirect(route('offline.devices.index'));
        $this->get('/account/delete')->assertOk()
            ->assertSee('You are the only owner of Phone Co')
            ->assertSee('opening Account, Delete my account.')
            ->assertDontSee('Want a copy first?')
            ->assertDontSee('make another member an owner under Team first')
            ->assertDontSee(route('notify.data.index'), false)
            ->assertDontSee(route('team.index'), false);
    }

    public function test_only_an_owner_can_cancel(): void
    {
        $party = Party::create(['organization_id' => $this->company->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $this->company->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');

        // An admin holds every permission but is not an owner; a contractor login reaches the service and is refused there too.
        foreach ([$this->member($this->company, 'admin'), $this->member($this->company, 'engineer'), $contractor] as $person) {
            $this->actingAs($person)->delete('/account/delete')->assertForbidden()->assertSee('Only a company owner can do this.');
        }

        $this->assertSame('pending', CompanyDeletionRequest::sole()->status);
    }

    public function test_an_owner_cancels_only_their_own_companys_deletion(): void
    {
        $other = $this->tenant('Rival Constructions');
        $rivalOwner = $this->member($other, 'owner');
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');

        $this->actingAs($rivalOwner)->delete('/account/delete')->assertRedirect(route('account.delete'));

        $this->assertSame('pending', CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $this->company->id)->sole()->status);
        $this->assertSame(0, CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $other->id)->count());
    }

    public function test_the_cancel_is_a_form_post_with_its_csrf_token(): void
    {
        // Tests skip the CSRF check, so it is switched back on here, as the website runs it.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');
        $this->actingAs($this->owner)->withSession(['_token' => 'page-token']);

        $this->delete('/account/delete')->assertStatus(419);
        $this->delete('/account/delete', ['_token' => 'another-token'])->assertStatus(419);
        $this->assertSame('pending', CompanyDeletionRequest::sole()->status);

        $this->delete('/account/delete', ['_token' => 'page-token'])->assertRedirect(route('account.delete'));
        $this->assertSame('cancelled', CompanyDeletionRequest::sole()->status);
    }

    public function test_signed_out_visitors_cannot_cancel(): void
    {
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');

        $this->delete('/account/delete')->assertRedirect(route('login'));
        $this->assertSame('pending', CompanyDeletionRequest::sole()->status);
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

        // The portal's own menu leads there, as the public deletion page says.
        $this->actingAs($contractor)->get(route('contractor-portal.home'))->assertOk()
            ->assertSee('href="'.route('account.delete').'"', false)->assertSee('Delete my account');

        // Shown in the portal's own frame, with no company menu.
        $this->actingAs($contractor)->get('/account/delete')->assertOk()
            ->assertSee('Contractor portal')->assertSee('Shree Formwork')->assertDontSee(route('team.index'), false)
            ->assertSee('href="'.route('account.delete').'" class="btn btn-ink"', false)
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
