<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\User;
use App\Services\Access\AccountDeletionService;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\CompanyExportService;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionClassConstant;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * The public /delete-account page, which Google Play links to (spec D.1), and the privacy and terms
 * text that points at it (D.3). What deletion does in detail is tested in tests/Feature/Access; here
 * the page is held to the same numbers and the same split between members and the only owner.
 */
class AccountDeletionPolicyTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
    }

    public function test_anyone_can_read_how_to_delete_an_account(): void
    {
        $this->assertGuest();

        $this->get('/delete-account')->assertOk()
            ->assertSee('Delete your Nirmaan360 account')
            ->assertSee('Open <strong>Settings</strong>, then <strong>Delete account</strong>.', false)
            ->assertSee(route('account.delete'), false)
            ->assertSee(route('password.request'), false)
            ->assertSee(route('contact', ['type' => 'support']), false)
            ->assertSee('We reply to that address to confirm the request is yours.')
            ->assertSee('Your account is deleted straight away, and this cannot be undone.')
            ->assertSee('They now show "Deleted user" instead of your name.', false)
            ->assertSee('deleting your account deletes the company and everything in it', false)
            ->assertSee('because Indian tax law requires it')
            ->assertSee('Backup copies are overwritten on a rolling schedule.');
    }

    public function test_the_days_on_the_page_are_the_ones_the_code_uses(): void
    {
        $grace = CompanyDeletionService::GRACE_DAYS;
        $exportDays = (new ReflectionClassConstant(CompanyExportService::class, 'KEEP_DAYS'))->getValue();

        $this->get('/delete-account')->assertOk()
            ->assertSee("The company keeps working for {$grace} days.")
            ->assertSee("After the {$grace} days, the company is erased")
            ->assertSee("We remove our copy {$exportDays} days after it is made.");

        $this->get('/privacy')->assertOk()
            ->assertSee("It keeps working for {$grace} days")
            ->assertSee("company data exports for up to {$exportDays} days");
    }

    public function test_the_page_names_no_backup_period_until_the_owner_gives_one(): void
    {
        // The backup retention is the owner's to state (spec G.1). Until then neither page invents a number.
        foreach (['/delete-account', '/privacy'] as $path) {
            $text = strip_tags((string) $this->get($path)->assertOk()->getContent());

            $this->assertStringContainsString('Backup copies are overwritten on a rolling schedule.', $text, $path);
            $this->assertDoesNotMatchRegularExpression('/backup[^.]*\d/i', $text, "{$path} gives no number for backups");
        }
    }

    public function test_the_page_splits_people_the_way_the_server_does(): void
    {
        $deletions = app(AccountDeletionService::class);
        $company = $this->tenant('Deccan Builders');
        $owner = $this->member($company, 'owner');
        $engineer = $this->member($company, 'engineer');
        $party = Party::create(['organization_id' => $company->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $company->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);

        $this->get('/delete-account')
            ->assertSee('This covers everyone except a company\'s only owner, including contractor sign-ins and an owner whose company has another owner.', false)
            ->assertSee('If you are your company\'s only owner', false);

        // "Everyone except the only owner", contractor sign-ins included.
        $this->assertSame(AccountDeletionService::MODE_ACCOUNT, $deletions->modeFor($engineer));
        $this->assertSame(AccountDeletionService::MODE_ACCOUNT, $deletions->modeFor($contractor));
        $this->assertSame(AccountDeletionService::MODE_COMPANY, $deletions->modeFor($owner));

        // "An owner whose company has another owner", which is also the page's way to keep the company going.
        $second = $this->member($company, 'owner');
        $this->assertSame(AccountDeletionService::MODE_ACCOUNT, $deletions->modeFor($owner));

        // An owner who can no longer sign in does not count, so the first is the only owner again.
        $second->forceFill(['is_active' => false])->save();
        $this->assertSame(AccountDeletionService::MODE_COMPANY, $deletions->modeFor($owner));
    }

    public function test_the_only_owner_can_sign_in_again_to_cancel_as_the_page_says(): void
    {
        $company = $this->tenant('Deccan Builders');
        $owner = $this->member($company, 'owner');
        $owner->createToken('Phone');
        $this->get('/delete-account')->assertSee('Your name, email address and mobile number stay until then, so that you can sign in.');

        $result = app(AccountDeletionService::class)->deleteAccount($owner, 'company', 'password', 'Deccan Builders', 'app');

        // "You are signed out everywhere straight away" and "the company keeps working for 30 days".
        $this->assertSame('scheduled', $result['result']);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertTrue(now()->addDays(CompanyDeletionService::GRACE_DAYS)->isSameDay($result['erase_after']));

        // "Any owner, you included, can cancel by signing in again", and their details stay until then.
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertNull($owner->fresh()->anonymised_at);
        $this->assertSame([$owner->name, $owner->email], [$owner->fresh()->name, $owner->fresh()->email]);
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);

        // "On the website under Account, Delete my account."
        $this->get(route('account.delete'))->assertOk()->assertSee('Cancel the deletion');
        $this->delete(route('account.cancel'))->assertRedirect(route('account.delete'));
        $this->assertNull(app(CompanyDeletionService::class)->pending($company->id));
    }

    public function test_the_website_cancel_the_pages_name_is_the_one_every_plan_opens(): void
    {
        $where = 'on the website under Account, Delete my account.';

        $this->get('/delete-account')->assertOk()
            ->assertSee('Until then any owner, you included, can cancel by signing in again: in the app under Settings, Delete account, or '.$where)
            ->assertDontSee('on the website under Account, Company data');
        $this->get('/privacy')->assertOk()
            ->assertSee('any owner can cancel by signing in during that time: in the app under Settings, Delete account, or '.$where);

        // The free plan, the smallest, opens the account pages, the cancel among them.
        $free = $this->tenant('Phone Co', 'free');
        $owner = $this->member($free, 'owner');
        app(CompanyDeletionService::class)->request($free, $owner, 'Phone Co');
        $this->actingAs($owner)->get(route('account.delete'))->assertOk()->assertSee('Cancel the deletion');
        $this->delete(route('account.cancel'))->assertRedirect(route('account.delete'));
        $this->assertNull(app(CompanyDeletionService::class)->pending($free->id));
    }

    public function test_a_contractor_finds_delete_my_account_in_the_portal_menu_as_the_pages_say(): void
    {
        $this->get('/delete-account')->assertSee('A contractor sign-in finds <strong>Delete my account</strong> in the contractor portal\'s menu.', false);
        $this->get('/privacy')->assertSee('a contractor sign-in finds Delete my account in the contractor portal\'s menu', false);

        $company = $this->tenant('Deccan Builders');
        $party = Party::create(['organization_id' => $company->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $company->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);

        $this->actingAs($contractor)->get(route('contractor-portal.home'))->assertOk()->assertSee('href="'.route('account.delete').'"', false);
    }

    public function test_signed_in_people_can_read_it_too(): void
    {
        $company = $this->tenant('Deccan Builders');
        $party = Party::create(['organization_id' => $company->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $company->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);

        // A contractor login is kept to its portal, but public pages stay open.
        $this->actingAs($contractor)->get('/delete-account')->assertOk()->assertSee('Delete your Nirmaan360 account');
        $this->actingAs($this->member($this->tenant('Phone Co', 'free'), 'owner'))->get('/delete-account')->assertOk();
    }

    public function test_the_footer_the_privacy_policy_and_the_terms_link_to_it(): void
    {
        $page = route('account-deletion');

        $this->get('/')->assertOk()->assertSee($page, false)->assertSee('Delete account');
        $this->get('/refund-policy')->assertOk()->assertSee($page, false);

        $this->get('/privacy')->assertOk()
            ->assertSee('id="deleting-your-account"', false)
            ->assertSee('Deleting your account')
            ->assertSee($page, false)
            ->assertSee('your account is deleted straight away')
            ->assertSee('deleting your account deletes the company')
            ->assertSee('are kept as Indian tax law requires')
            ->assertDontSee('If you ask us to delete your account, we remove your data');

        $this->get('/terms')->assertOk()
            ->assertSee($page, false)
            ->assertSee('or your company if you are its only owner')
            ->assertDontSee('if you want your account closed');
    }

    public function test_the_footer_link_reads_in_hindi_and_marathi(): void
    {
        $this->assertSame('खाता हटाएँ', __('Delete account', [], 'hi'));
        $this->assertSame('खाते हटवा', __('Delete account', [], 'mr'));

        $this->get('/hi')->assertOk()->assertSee('खाता हटाएँ')->assertSee(route('account-deletion'), false);
    }

    public function test_the_text_is_built_in_and_an_admin_cannot_replace_it(): void
    {
        // Google Play reads this page as our deletion promise, so it always says what the code does.
        app(PlatformSettings::class)->set(['privacy_text' => '## Our policy']);

        $this->get('/privacy')->assertSee('Our policy')->assertDontSee('Deleting your account');
        $this->get('/delete-account')->assertOk()->assertSee('Delete your Nirmaan360 account')->assertSee('If you cannot sign in');
    }
}
