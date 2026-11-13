<?php

namespace Tests\Feature\OwnerPack;

use App\Models\AuditLog;
use App\Models\ClientQuotation;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\OwnerUpdate;
use App\Services\Access\TeamAccessService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The weekly-update screens: who may open and change what, across roles, plans, companies and projects. */
class OwnerUpdateHttpTest extends TestCase
{
    use BuildsOwnerUpdates, RefreshDatabase;

    /** The routes a person who only prepares updates may use. */
    private const PREPARE = ['index', 'create', 'store', 'show', 'update'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->site();
    }

    /** @return array<string, mixed> What the new-update form sends. */
    private function form(array $overrides = []): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'week_start' => self::WEEK, 'language' => 'en', ...$overrides];
    }

    /**
     * Every signed-in route of the weekly update against one draft and one approved update, neither stating money.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> Name => method, address, body.
     */
    private function routes(OwnerUpdate $draft, OwnerUpdate $approved): array
    {
        return [
            'index' => ['get', route('owner-updates.index', $this->project), []],
            'create' => ['get', route('owner-updates.create', $this->project), []],
            'store' => ['post', route('owner-updates.store', $this->project), $this->form()],
            'show' => ['get', route('owner-updates.show', $draft), []],
            'update' => ['patch', route('owner-updates.update', $draft), ['next_week_text' => 'Plinth beam next']],
            'approve' => ['post', route('owner-updates.approve', $draft), $this->updateTicks($draft)],
            'share' => ['post', route('owner-updates.share', $approved), []],
            'revoke' => ['post', route('owner-updates.share.revoke', $approved), []],
        ];
    }

    /** @return array{0: OwnerUpdate, 1: OwnerUpdate} A draft and an approved update of the week, both without money. */
    private function pair(): array
    {
        $this->week();

        return [$this->draftUpdate(), $this->approvedUpdate()];
    }

    private function send(array $route): TestResponse
    {
        [$method, $address, $body] = $route;

        return $method === 'get' ? $this->get($address) : $this->{$method}($address, $body);
    }

    /** @return list<array<string, mixed>> Every update as it is stored, to prove a refused request changed nothing. */
    private function state(): array
    {
        return OwnerUpdate::withoutGlobalScopes()->orderBy('id')->get()->map(fn ($row) => $row->makeVisible('share_token')->getAttributes())->all();
    }

    public function test_every_route_of_the_weekly_update_is_named_here_and_carries_its_guards(): void
    {
        [$draft, $approved] = $this->pair();
        $named = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'owner-updates.') || str_contains((string) $route->getName(), '.shared')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $named[] = $route->getName();

            $this->assertContains('auth', $middleware, $route->getName());
            $this->assertContains('feature:owner_pack', $middleware, $route->getName());

            // Preparing needs owner_pack.progress. Approving and sharing need the pack's manager, who must also see money.
            in_array(Str::after($route->getName(), 'owner-updates.'), self::PREPARE, true)
                ? $this->assertContains('can:owner_pack.progress', $middleware, $route->getName())
                : $this->assertEmpty(array_diff(['can:owner_pack.manage', 'can:money.view'], $middleware), $route->getName());
        }

        $this->assertEqualsCanonicalizing(
            ['owner-updates.index', 'owner-updates.create', 'owner-updates.store', 'owner-updates.show', 'owner-updates.update', 'owner-updates.approve', 'owner-updates.share', 'owner-updates.share.revoke'],
            $named,
        );
        $this->assertCount(count($named), $this->routes($draft, $approved));
    }

    public function test_the_engineer_prepares_an_update_without_money_and_can_neither_approve_nor_share_it(): void
    {
        [$draft, $approved] = $this->pair();
        $this->actingAs($this->engineer);

        foreach ($this->routes($draft, $approved) as $name => $route) {
            $response = $this->send($route);

            match (true) {
                in_array($name, ['index', 'create', 'show'], true) => $response->assertOk(),
                in_array($name, ['store', 'update'], true) => $response->assertRedirect()->assertSessionHasNoErrors(),
                default => $response->assertForbidden(),
            };
        }

        $this->assertSame([OwnerUpdate::DRAFT, 'Plinth beam next'], [$draft->fresh()->status, $draft->fresh()->next_week_text]);
        $this->assertSame([OwnerUpdate::APPROVED, null], [$approved->fresh()->status, $approved->fresh()->share_token]);

        // The engineer's own draft: no rupee anywhere, no way to approve it, and it says who does.
        $own = OwnerUpdate::where('created_by', $this->engineer->id)->sole();
        $this->assertFalse($own->show_money);

        $page = $this->get(route('owner-updates.show', $own))->assertOk()
            ->assertSee('Approved daily reports of that week: ', false)
            ->assertSee('A person who manages the home-owner pack and may see money checks every figure and approves the update before it can be shared.')
            ->assertDontSee('Approve update')
            ->assertDontSee('number_ticks', false)
            ->assertDontSee('State the payment position')
            ->assertDontSee('Received so far');
        $this->assertStringNotContainsString('₹', $page->getContent());

        // Nor does the form offer them the payment position.
        $this->get(route('owner-updates.create', $this->project))->assertOk()->assertDontSee('show_money', false)->assertDontSee('quotation_id', false);
        // The approved update shows no way to make a link.
        $this->get(route('owner-updates.show', $approved))->assertOk()->assertSee('No link has been made yet.')->assertDontSee('Make a link');
    }

    public function test_the_engineer_cannot_switch_the_payment_position_on_or_open_an_update_that_states_it(): void
    {
        $this->week();
        $quotation = $this->quotationWithAStageReached();
        $plain = $this->draftUpdate([], $this->engineer);
        $withMoney = $this->draftUpdate(['show_money' => true, 'quotation_id' => $quotation->id, 'received_amount' => '40000']);
        $before = $this->state();

        $this->actingAs($this->engineer);

        $this->post(route('owner-updates.store', $this->project), $this->form(['show_money' => '1', 'quotation_id' => $quotation->id]))->assertForbidden();
        $this->patch(route('owner-updates.update', $plain), ['show_money' => '1', 'quotation_id' => $quotation->id])->assertForbidden();
        $this->get(route('owner-updates.show', $withMoney))->assertForbidden()->assertDontSee('47,354')->assertDontSee('7,354');
        $this->patch(route('owner-updates.update', $withMoney), ['next_week_text' => 'Changed by the engineer'])->assertForbidden();
        $this->patch(route('owner-updates.update', $withMoney), ['show_money' => '0'])->assertForbidden();

        $this->assertSame($before, $this->state());

        // The list names the update and says it carries money, with no figure and no way in.
        $list = $this->get(route('owner-updates.index', $this->project))->assertOk()
            ->assertSee('States the payment position, which your role does not see')
            ->assertSee('Carries no money figures')
            ->assertSee(route('owner-updates.show', $plain))
            ->assertDontSee(route('owner-updates.show', $withMoney));
        $this->assertStringNotContainsString('₹', $list->getContent());

        foreach (['47,354', '40,000', '7,354', '157847', '1,57,847'] as $figure) {
            $list->assertDontSee($figure);
        }

        // The manager opens the same update and reads its figures.
        $this->actingAs($this->manager)->get(route('owner-updates.show', $withMoney))->assertOk()
            ->assertSee('Due on the stages reached, after what was received: ', false)
            ->assertSee('₹7,354.15')
            ->assertSee('<mark class="rounded-[2px] bg-haldi/35 px-0.5" title="Received so far, as entered by the contractor">₹40,000.00</mark>.', false);
        $this->get(route('owner-updates.index', $this->project))->assertOk()->assertSee('States the payment position')->assertSee(route('owner-updates.show', $withMoney));
    }

    public function test_roles_without_the_permission_reach_no_route_of_the_weekly_update(): void
    {
        [$draft, $approved] = $this->pair();
        $before = $this->state();

        // Accounts and the estimator read quotations (owner_pack.view) but do not prepare updates.
        foreach (['accounts', 'qs', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->organization, $role));

            foreach ($this->routes($draft, $approved) as $route) {
                $this->send($route)->assertForbidden();
            }
        }

        // The pack's manager without money.view prepares, and still neither approves nor shares.
        config(['buildos.roles.viewer.permissions' => [...config('buildos.roles.viewer.permissions'), 'owner_pack.*']]);
        $this->actingAs($manager = $this->member($this->organization, 'viewer'));
        $this->assertTrue($manager->can('owner_pack.manage') && ! $manager->can('money.view'));

        foreach (array_diff_key($this->routes($draft, $approved), array_flip(self::PREPARE)) as $route) {
            $this->send($route)->assertForbidden();
        }

        $this->assertSame($before, $this->state());

        // Signed out, every route sends the visitor to sign in.
        $routes = $this->routes($draft, $approved);
        $this->signOut();

        foreach ($routes as $route) {
            $this->send($route)->assertRedirect(route('login'));
        }

        $this->assertSame($before, $this->state());
    }

    public function test_the_roles_get_the_permission_to_prepare_that_the_module_declares(): void
    {
        $can = fn (string $role, string $permission) => $this->member($this->organization, $role)->can($permission);

        foreach (['owner', 'admin', 'pm', 'engineer'] as $role) {
            $this->assertTrue($can($role, 'owner_pack.progress'), $role);
        }

        foreach (['accounts', 'qs', 'store', 'viewer', 'contractor'] as $role) {
            $this->assertFalse($can($role, 'owner_pack.progress'), $role);
        }

        // The engineer holds nothing else of the pack, and no money.
        $this->assertFalse($can('engineer', 'owner_pack.manage') || $can('engineer', 'owner_pack.view') || $can('engineer', 'money.view'));
    }

    public function test_a_plan_without_the_pack_refuses_even_the_owner(): void
    {
        [$draft, $approved] = $this->pair();
        $routes = $this->routes($draft, $approved);
        $before = $this->state();

        $this->organization->update(['plan' => 'free']);
        $this->actingAs($this->owner->fresh());

        foreach ($routes as $route) {
            $this->send($route)->assertForbidden();
        }

        $this->assertSame($before, $this->state());
    }

    public function test_another_company_reaches_nothing_and_changes_nothing(): void
    {
        [$draft, $approved] = $this->pair();
        $routes = $this->routes($draft, $approved);
        $before = $this->state();

        $other = $this->tenant('Konkan Builders', 'business');
        $theirProject = $this->makeProject($other, ['name' => 'Their Own Site']);

        foreach (['owner', 'pm', 'engineer'] as $role) {
            $this->actingAs($this->member($other, $role));

            foreach ($routes as $route) {
                $this->send($route)->assertNotFound();
            }
        }

        $this->assertSame($before, $this->state());

        // In their own project the same week is empty: our reports, photos and quotations cannot be pulled in by id.
        $theirOwner = $this->member($other, 'owner');
        $this->actingAs($theirOwner);
        $ourPhoto = DprPhoto::withoutGlobalScopes()->firstOrFail();
        $ourQuotation = $this->issued();
        $this->actingAs($theirOwner);

        $this->post(route('owner-updates.store', $theirProject), $this->form(['photo_ids' => [$ourPhoto->id]]))->assertSessionHasErrors('photo_ids');
        $this->post(route('owner-updates.store', $theirProject), $this->form(['show_money' => '1', 'quotation_id' => $ourQuotation->id]))->assertSessionHasErrors('quotation_id');
        $this->assertSame(0, OwnerUpdate::withoutGlobalScopes()->where('organization_id', $other->id)->count());

        $this->post(route('owner-updates.store', $theirProject), $this->form())->assertRedirect()->assertSessionHasNoErrors();
        $theirs = OwnerUpdate::withoutGlobalScopes()->where('organization_id', $other->id)->sole();
        $page = $this->get(route('owner-updates.show', $theirs))->assertOk()->assertSee('No daily report of that week has been approved yet, so no work is listed here.');

        foreach (['Excavation', 'Brick masonry', 'Footing F2', 'Sahyadri', 'Deshmukh'] as $ours) {
            $page->assertDontSee($ours);
        }

        $this->get(route('owner-updates.index', $theirProject))->assertOk()->assertDontSee(route('owner-updates.show', $draft));
    }

    public function test_a_person_limited_to_another_project_reaches_nothing_here(): void
    {
        [$draft, $approved] = $this->pair();
        $routes = $this->routes($draft, $approved);
        $uuid = $this->draftUuid($draft);
        $before = $this->state();
        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);

        foreach (['pm', 'engineer'] as $role) {
            $limited = $this->member($this->organization, $role);
            app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
            $this->actingAs($limited->refresh());

            foreach ($routes as $route) {
                $this->send($route)->assertNotFound();
            }

            // A repeated key of an update in the project they cannot reach is an error, not a way in.
            $this->post(route('owner-updates.store', $elsewhere), $this->form(['client_uuid' => $uuid]))->assertSessionHasErrors('client_uuid');
            $this->get(route('owner-updates.index', $elsewhere))->assertOk()->assertDontSee(route('owner-updates.show', $draft));
        }

        $this->assertSame($before, $this->state());

        // Limited to this project, the same person works as usual.
        $here = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($here, true, [$this->project->id]);
        $this->actingAs($here->refresh())->get(route('owner-updates.show', $draft))->assertOk();
        $this->post(route('owner-updates.store', $this->project), $this->form())->assertRedirect()->assertSessionHasNoErrors();
    }

    /** Gives a draft a client_uuid underneath the app and returns it. */
    private function draftUuid(OwnerUpdate $draft): string
    {
        $uuid = (string) Str::uuid();
        OwnerUpdate::withoutGlobalScopes()->whereKey($draft->id)->update(['client_uuid' => $uuid]);

        return $uuid;
    }

    public function test_the_form_refuses_what_does_not_belong_to_the_week_or_the_project(): void
    {
        ['photos' => [$footing, $plain, $brickwork]] = $this->week();
        $lastWeek = $this->photo(Dpr::whereDate('report_date', '2026-11-06')->sole(), 'Last week');
        $draftQuotation = $this->draft();
        $this->actingAs($this->manager);
        $store = fn (array $with) => $this->post(route('owner-updates.store', $this->project), $this->form($with));

        $store(['week_start' => ''])->assertSessionHasErrors('week_start');
        $store(['week_start' => '2026-11-15'])->assertSessionHasErrors('week_start');
        $store(['language' => 'ta'])->assertSessionHasErrors('language');
        $store(['photo_ids' => [$footing->id, $lastWeek->id]])->assertSessionHasErrors('photo_ids');
        $store(['photo_ids' => [$footing->id, $footing->id]])->assertSessionHasErrors('photo_ids.0');
        $store(['photo_ids' => 'all'])->assertSessionHasErrors('photo_ids');
        $store(['show_money' => '1'])->assertSessionHasErrors('quotation_id');
        $store(['show_money' => '1', 'quotation_id' => $draftQuotation->id])->assertSessionHasErrors('quotation_id');
        $store(['show_money' => '1', 'quotation_id' => $this->issued()->id, 'received_amount' => '-1'])->assertSessionHasErrors('received_amount');
        $store(['show_money' => '1', 'quotation_id' => ClientQuotation::where('status', 'issued')->sole()->id, 'received_amount' => '10.555'])->assertSessionHasErrors('received_amount');
        $store(['next_week_text' => str_repeat('a', 1201)])->assertSessionHasErrors('next_week_text');

        // No more photos than the limit, which the page states.
        config(['modules.owner_pack.max_photos' => 2]);
        $store(['photo_ids' => [$footing->id, $plain->id, $brickwork->id]])->assertSessionHasErrors('photo_ids');

        $this->assertSame(0, OwnerUpdate::count());

        $store(['photo_ids' => [$footing->id, $brickwork->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('owner-updates.show', OwnerUpdate::sole()))->assertOk()->assertSee('at most 2.');
    }

    public function test_the_manager_takes_an_update_from_the_form_to_a_shared_link(): void
    {
        ['photos' => [$footing, , $brickwork]] = $this->week();
        $quotation = $this->quotationWithAStageReached();
        $this->actingAs($this->manager);

        $this->get(route('owner-updates.index', $this->project))->assertOk()->assertSee('No weekly updates yet');
        $this->get(route('owner-updates.create', $this->project))->assertOk()
            ->assertSee('name="week_start" type="date" value="2026-11-09"', false)
            ->assertSee('State the payment position: the stages reached and what is due')
            ->assertSee($quotation->number)
            ->assertSee('Nothing is sent to the client from here.');

        $this->post(route('owner-updates.store', $this->project), $this->form(['show_money' => '1', 'quotation_id' => $quotation->id, 'received_amount' => '40000']))->assertRedirect()->assertSessionHasNoErrors();
        $update = OwnerUpdate::sole();
        $this->assertSame([$this->organization->id, $this->project->id, $this->manager->id, true], [$update->organization_id, $update->project_id, $update->created_by, $update->show_money]);

        // The draft page offers the week's photos, and only those of approved reports.
        $page = $this->get(route('owner-updates.show', $update))->assertOk()
            ->assertSee('name="photo_ids[]" value="'.$footing->id.'"', false)
            ->assertSee('name="photo_ids[]" value="'.$brickwork->id.'"', false)
            ->assertSee('Footing F2 before concreting')
            ->assertSee(route('dprs.photo', $footing));
        $this->assertSame(3, substr_count($page->getContent(), 'name="photo_ids[]"'));

        $this->patch(route('owner-updates.update', $update), ['photo_ids' => [$footing->id, $brickwork->id], 'next_week_text' => 'Plinth beam and backfilling.', 'opening' => 'Namaste.'])->assertSessionHasNoErrors();

        $page = $this->get(route('owner-updates.show', $update))->assertOk()
            ->assertSee('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.')
            ->assertSee('Namaste.')
            ->assertSee('Plinth beam and backfilling.')
            ->assertSee('A-01 Excavation for foundation in all soils')
            ->assertSee('7.375')
            ->assertSee('<mark class="rounded-[2px] bg-haldi/35 px-0.5" title="Approved daily reports of the week">3</mark>.', false)
            ->assertSee('<mark class="rounded-[2px] bg-haldi/35 px-0.5" title="Due on the stages reached">₹7,354.15</mark>.', false);

        // The page offers a tick for every figure, work row and photo the service will ask for.
        $ticks = $this->updateTicks($update);

        foreach ($ticks['number_ticks'] as $tick) {
            $page->assertSee('name="number_ticks[]" value="'.$tick.'"', false);
        }

        $page->assertSee('name="fact_fingerprint" value="'.$ticks['fact_fingerprint'].'"', false);
        $page->assertSee('name="text_fingerprint" value="'.$ticks['text_fingerprint'].'"', false);
        $this->assertContains('photo_2', $ticks['number_ticks']);
        $this->assertContains('pay_due', $ticks['number_ticks']);

        // Without the ticks, or without the facts and the text the page showed, nothing is approved.
        $this->post(route('owner-updates.approve', $update), [...$ticks, 'number_ticks' => ['report_count']])->assertSessionHasErrors('number_ticks');
        $this->post(route('owner-updates.approve', $update), ['number_ticks' => $ticks['number_ticks'], 'text_fingerprint' => $ticks['text_fingerprint']])->assertSessionHasErrors('fact_fingerprint');
        $this->post(route('owner-updates.approve', $update), ['number_ticks' => $ticks['number_ticks'], 'fact_fingerprint' => $ticks['fact_fingerprint']])->assertSessionHasErrors('text_fingerprint');
        $this->post(route('owner-updates.approve', $update), [...$ticks, 'fact_fingerprint' => str_repeat('a', 64)])->assertSessionHasErrors('facts');
        $this->post(route('owner-updates.approve', $update), [...$ticks, 'text_fingerprint' => str_repeat('a', 64)])->assertSessionHasErrors('facts');
        $this->assertSame(OwnerUpdate::DRAFT, $update->fresh()->status);

        $this->post(route('owner-updates.approve', $update), $ticks)->assertSessionHasNoErrors();
        $this->assertSame([OwnerUpdate::APPROVED, $this->manager->id], [$update->fresh()->status, $update->fresh()->approved_by]);

        // Approving sent nothing anywhere: there is no link until a person makes one.
        $this->assertNull($update->fresh()->share_token);
        $this->get(route('owner-updates.show', $update))->assertOk()
            ->assertSee('The update as approved')
            ->assertSee('No link has been made yet.')
            ->assertSee($this->manager->name)
            ->assertSee('Due on the stages reached, after what was received: ₹7,354.15.')
            ->assertDontSee('<mark', false)
            ->assertDontSee('Save draft');

        // An approved update answers 409 to a change.
        $this->patch(route('owner-updates.update', $update), ['next_week_text' => 'Changed'])->assertStatus(409);
        $this->assertSame('Plinth beam and backfilling.', $update->fresh()->next_week_text);

        $this->post(route('owner-updates.share', $update))->assertSessionHasNoErrors();
        $shared = $update->fresh();
        $this->assertTrue($shared->isShared());
        $this->get(route('owner-updates.show', $update))->assertOk()->assertSee('/shared/owner-updates/'.$update->id.'/'.$shared->share_token)->assertSee('Send on WhatsApp');
        $this->get(route('owner-updates.index', $this->project))->assertOk()->assertSee('Shared')->assertSee('2 photos');

        $this->post(route('owner-updates.share.revoke', $update))->assertSessionHasNoErrors();
        $this->assertFalse($update->fresh()->isShared());
        $this->get(route('owner-updates.show', $update))->assertOk()->assertSee('The link was withdrawn. It no longer opens.')->assertDontSee($shared->share_token);

        $this->assertSame(
            ['owner_update.created', 'owner_update.updated', 'owner_update.approved', 'owner_update.shared', 'owner_update.share_revoked'],
            AuditLog::where('action', 'like', 'owner_update.%')->orderBy('id')->pluck('action')->all(),
        );

        // The audit trail keeps neither the token nor the amounts.
        $trail = json_encode(AuditLog::where('action', 'like', 'owner_update.%')->get()->map->getAttributes());
        $this->assertStringNotContainsString($shared->share_token, $trail);
        $this->assertStringNotContainsString('7354', $trail);
        $this->assertStringNotContainsString('40000', $trail);
    }

    public function test_sending_the_form_twice_makes_one_update(): void
    {
        $this->week();
        $this->actingAs($this->engineer);
        $form = $this->form();

        $first = $this->post(route('owner-updates.store', $this->project), $form)->assertRedirect();
        $this->post(route('owner-updates.store', $this->project), $form)->assertRedirect($first->headers->get('Location'));

        $this->assertSame(1, OwnerUpdate::count());
    }

    public function test_the_page_says_when_the_records_have_moved_on(): void
    {
        $this->week();
        $draft = $this->draftUpdate();
        $approved = $this->approvedUpdate();
        [$shared] = $this->sharedUpdate();
        $this->actingAs($this->manager);
        $again = route('owner-updates.create', [$this->project, 'week_start' => self::WEEK, 'language' => 'en']);

        $this->get(route('owner-updates.show', $draft))->assertOk()->assertDontSee('The records have changed since this draft was prepared.');
        $this->get(route('owner-updates.show', $approved))->assertOk()->assertSee('Make a link</button>', false)->assertDontSee($again);
        $this->get(route('owner-updates.show', $shared))->assertOk()->assertSee('Make a new link</button>', false)->assertDontSee($again);

        Dpr::where('status', 'IN_REVIEW')->sole()->update(['status' => 'APPROVED']);

        $this->get(route('owner-updates.show', $draft))->assertOk()->assertSee('The records have changed since this draft was prepared. Save the draft to read them again.');

        // The page offers no link it has just said cannot be made, and leads to a new update for the week instead.
        $this->get(route('owner-updates.show', $approved))->assertOk()
            ->assertSee('a new link cannot be made for it')
            ->assertDontSee('Make a link</button>', false)
            ->assertSee('Prepare a new update for this week')
            ->assertSee($again);
        $this->get(route('owner-updates.show', $shared))->assertOk()
            ->assertDontSee('Make a new link</button>', false)
            ->assertSee('Withdraw the link')
            ->assertSee($again);
        $this->get($again)->assertOk()->assertSee('name="week_start" type="date" value="'.self::WEEK.'"', false);
        $this->get(route('owner-updates.create', [$this->project, 'week_start' => '2026-11-02', 'language' => 'mr']))->assertOk()
            ->assertSee('name="week_start" type="date" value="2026-11-02"', false)
            ->assertSee('<option value="mr" lang="mr" selected', false);
        // A week that has not begun, or a language the update is not written in, is not taken from the address.
        $this->get(route('owner-updates.create', [$this->project, 'week_start' => '2026-12-07', 'language' => 'fr']))->assertOk()
            ->assertSee('name="week_start" type="date" value="'.self::WEEK.'"', false)
            ->assertSee('<option value="en" lang="en" selected', false);

        // Sharing reads the records again and refuses; saving the draft catches up.
        $this->post(route('owner-updates.share', $approved))->assertSessionHasErrors('facts');
        $this->assertNull($approved->fresh()->share_token);

        $this->patch(route('owner-updates.update', $draft), [])->assertSessionHasNoErrors();
        $this->get(route('owner-updates.show', $draft))->assertOk()->assertDontSee('The records have changed since this draft was prepared.')->assertSee('Approved daily reports of that week: ', false);
    }

    public function test_changing_the_week_on_the_page_keeps_the_ticked_photos_of_the_new_week_and_drops_the_rest(): void
    {
        ['photos' => [$footing, , $brickwork]] = $this->week();
        $update = $this->draftUpdate(['photo_ids' => [$footing->id, $brickwork->id]], $this->engineer);
        $this->actingAs($this->engineer);

        // The Details form sends every field it shows, and the photos it shows ticked, whatever else was changed.
        $this->get(route('owner-updates.show', $update))->assertOk()
            ->assertSee('<input type="hidden" name="photo_ids" value="">', false)
            ->assertSee('name="photo_ids[]" value="'.$footing->id.'" class="mt-0.5" checked', false);
        $form = fn (string $week, array $ticked) => ['week_start' => $week, 'language' => 'en', 'opening' => '', 'next_week_text' => '', 'closing' => '', 'photo_ids' => array_map('strval', $ticked)];

        // From the 9th to the 11th: Thursday's report is in both weeks, so its photo stays; Monday's goes with the old week.
        $this->patch(route('owner-updates.update', $update), $form('2026-11-11', [$footing->id, $brickwork->id]))->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-11', [$brickwork->id]], [$update->fresh()->week_start->toDateString(), $update->fresh()->photoIds()]);

        // A week back, where no approved report has a photo: nothing ticked on the page is kept.
        $this->patch(route('owner-updates.update', $update), $form('2026-11-02', [$brickwork->id]))->assertSessionHasNoErrors();
        $this->assertSame(['2026-11-02', []], [$update->fresh()->week_start->toDateString(), $update->fresh()->photoIds()]);

        // With the week left as it is, a photo of another week is refused and nothing changes.
        $this->patch(route('owner-updates.update', $update), $form('2026-11-02', [$brickwork->id]))->assertSessionHasErrors('photo_ids');
        $this->assertSame(['2026-11-02', []], [$update->fresh()->week_start->toDateString(), $update->fresh()->photoIds()]);
    }

    public function test_the_menu_offers_weekly_updates_to_those_who_may_prepare_them(): void
    {
        $this->assertContains('owner-updates.index', array_column(Modules::nav('project', $this->organization), 'route'));
        $this->assertNotContains('owner-updates.index', array_column(Modules::nav('project', $this->tenant('Free Co', 'free')), 'route'));

        $link = route('owner-updates.index', $this->project);

        foreach (['pm' => true, 'engineer' => true, 'accounts' => false, 'viewer' => false] as $role => $offered) {
            $page = $this->actingAs($this->member($this->organization, $role))->get(route('projects.show', $this->project))->assertOk();
            $offered ? $page->assertSee($link) : $page->assertDontSee($link);
        }
    }

    public function test_the_screens_are_written_in_the_readers_language(): void
    {
        $this->week();
        $update = $this->draftUpdate(['language' => 'en']);

        foreach (['hi' => ['साप्ताहिक अपडेट', 'जाँचें और मंज़ूर करें', 'क्लाइंट के लिए फ़ोटो', 'सप्ताह की मंज़ूर दैनिक रिपोर्टें'], 'mr' => ['साप्ताहिक अपडेट', 'तपासा आणि मंजूर करा', 'क्लायंटसाठी फोटो', 'आठवड्यातील मंजूर दैनिक अहवाल']] as $locale => $texts) {
            $this->manager->forceFill(['locale' => $locale])->save();
            $page = $this->actingAs($this->manager->fresh())->get(route('owner-updates.show', $update))->assertOk();

            foreach ($texts as $text) {
                $page->assertSee($text);
            }

            // The update itself stays in its own language, whatever language the reader works in.
            $page->assertSee('Approved daily reports of that week: ', false)->assertDontSee('Check and approve');
        }
    }
}
