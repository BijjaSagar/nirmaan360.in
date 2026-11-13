<?php

namespace Tests\Feature\OwnerPack;

use App\Models\AuditLog;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\Party;
use App\Services\Access\TeamAccessService;
use App\Services\Wording\FactSheet;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The quotation screens: who may open and change what, across roles, plans, companies and projects. */
class QuotationHttpTest extends TestCase
{
    use BuildsOwnerPack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->house();
    }

    /** @return array<string, mixed> What the new-quotation form sends. */
    private function form(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'client_name' => 'Anil Deshmukh',
            'language' => 'en',
            'gst_pct' => self::TYPED_GST,
            'valid_until' => today()->addDays(20)->toDateString(),
            'boq_item_ids' => [$this->lines[0]->id, $this->lines[1]->id, $this->lines[2]->id],
            ...$overrides,
        ];
    }

    /**
     * Every route of the module against one draft, one issued quotation and one stage of each.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> Name => method, address, body.
     */
    private function routes(ClientQuotation $draft, ClientQuotation $issued): array
    {
        $stage = $draft->stages()->first();
        $issuedStage = $issued->stages()->first();

        return [
            'index' => ['get', route('quotations.index', $this->project), []],
            'create' => ['get', route('quotations.create', $this->project), []],
            'store' => ['post', route('quotations.store', $this->project), $this->form()],
            'show' => ['get', route('quotations.show', $draft), []],
            'update' => ['patch', route('quotations.update', $draft), ['language' => 'en', 'client_name' => 'Renamed', 'gst_pct' => '1']],
            'issue' => ['post', route('quotations.issue', $draft), $this->allTicks($draft)],
            'revise' => ['post', route('quotations.revise', $issued), ['client_uuid' => (string) Str::uuid()]],
            'stage store' => ['post', route('quotations.stages.store', $draft), ['title' => 'Extra', 'basis' => 'amount', 'amount' => '1', 'client_uuid' => (string) Str::uuid()]],
            'stage update' => ['patch', route('quotation-stages.update', $stage), ['title' => 'Renamed', 'basis' => 'amount', 'amount' => '2']],
            'stage destroy' => ['delete', route('quotation-stages.destroy', $stage), []],
            'stage reached' => ['post', route('quotation-stages.reached', $issuedStage), ['reached' => '1']],
            'share' => ['post', route('quotations.share', $issued), []],
            'revoke' => ['post', route('quotations.share.revoke', $issued), []],
        ];
    }

    /** @return array{0: ClientQuotation, 1: ClientQuotation} A draft with one stage, and an issued quotation with three. */
    private function pair(): array
    {
        $draft = $this->draft();
        $this->stage($draft, 'Advance', null, '1000');

        return [$draft->fresh(), $this->issued()];
    }

    private function send(array $route): TestResponse
    {
        [$method, $address, $body] = $route;

        return $method === 'get' ? $this->get($address) : $this->{$method}($address, $body);
    }

    /** @return array<string, mixed> Everything that could change, to prove a refused request changed nothing. */
    private function state(): array
    {
        return [
            ClientQuotation::withoutGlobalScopes()->orderBy('id')->get()->map(fn ($row) => $row->makeVisible('share_token')->getAttributes())->all(),
            ClientPaymentStage::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    public function test_site_staff_and_viewers_reach_no_quotation_route(): void
    {
        [$draft, $issued] = $this->pair();
        $before = $this->state();

        foreach (['engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->organization, $role));

            foreach ($this->routes($draft, $issued) as $name => $route) {
                $this->send($route)->assertForbidden();
            }
        }

        $this->assertSame($before, $this->state());
    }

    public function test_accounts_and_the_estimator_read_quotations_and_change_nothing(): void
    {
        [$draft, $issued] = $this->pair();
        $before = $this->state();

        foreach (['accounts', 'qs'] as $role) {
            $this->actingAs($this->member($this->organization, $role));

            foreach ($this->routes($draft, $issued) as $name => $route) {
                $response = $this->send($route);
                in_array($name, ['index', 'show'], true) ? $response->assertOk() : $response->assertForbidden();
            }

            // They read the figures, but the page offers them no way to change or share.
            $this->get(route('quotations.show', $issued))->assertOk()
                ->assertSee('₹1,57,847.17')
                ->assertDontSee('Make a link')
                ->assertDontSee('Make a revision')
                ->assertDontSee('Mark as reached');
            $this->get(route('quotations.show', $draft))->assertOk()->assertDontSee('Issue quotation')->assertDontSee('Save draft')->assertDontSee('Add stage');
        }

        $this->assertSame($before, $this->state());
    }

    public function test_the_pack_alone_is_not_enough_without_money_view_and_money_view_alone_is_not_enough_either(): void
    {
        [$draft, $issued] = $this->pair();

        // A role that was given the pack but may not see money.
        config(['buildos.roles.engineer.permissions' => [...config('buildos.roles.engineer.permissions'), 'owner_pack.*']]);
        $engineer = $this->member($this->organization, 'engineer');
        $this->assertTrue($engineer->can('owner_pack.manage'));
        $this->assertFalse($engineer->can('money.view'));
        $this->actingAs($engineer);

        foreach ($this->routes($draft, $issued) as $route) {
            $this->send($route)->assertForbidden();
        }

        // A role that sees money but was not given the pack.
        config(['buildos.roles.viewer.permissions' => [...config('buildos.roles.viewer.permissions'), 'money.view']]);
        $this->actingAs($this->member($this->organization, 'viewer'));

        foreach ($this->routes($draft, $issued) as $route) {
            $this->send($route)->assertForbidden();
        }
    }

    public function test_a_plan_without_the_pack_refuses_even_the_owner(): void
    {
        [$draft, $issued] = $this->pair();
        $routes = $this->routes($draft, $issued);

        $this->organization->update(['plan' => 'free']);
        $this->actingAs($this->owner->fresh());

        foreach ($routes as $route) {
            $this->send($route)->assertForbidden();
        }

        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $this->assertTrue($this->tenant('Plan '.$plan, $plan)->allowsFeature('owner_pack'));
        }

        $this->assertFalse($this->tenant('Plan free', 'free')->allowsFeature('owner_pack'));
        $this->assertFalse($this->tenant('Plan offline', 'offline')->allowsFeature('owner_pack'));
    }

    public function test_the_roles_get_the_permissions_the_module_declares(): void
    {
        $can = fn (string $role, string $permission) => $this->member($this->organization, $role)->can($permission);

        foreach (['owner', 'admin', 'pm'] as $role) {
            $this->assertTrue($can($role, 'owner_pack.manage') && $can($role, 'owner_pack.view'), $role);
        }

        foreach (['accounts', 'qs'] as $role) {
            $this->assertTrue($can($role, 'owner_pack.view'), $role);
            $this->assertFalse($can($role, 'owner_pack.manage'), $role);
        }

        foreach (['engineer', 'store', 'viewer', 'contractor'] as $role) {
            $this->assertFalse($can($role, 'owner_pack.view') || $can($role, 'owner_pack.manage'), $role);
        }
    }

    public function test_the_menu_offers_quotations_inside_a_project_to_those_who_may_open_them(): void
    {
        $this->assertContains('quotations.index', array_column(Modules::nav('project', $this->organization), 'route'));
        $this->assertNotContains('quotations.index', array_column(Modules::nav('project', $this->tenant('Free Co', 'free')), 'route'));

        $this->actingAs($this->manager)->get(route('projects.show', $this->project))->assertOk()->assertSee(route('quotations.index', $this->project));
        $this->actingAs($this->member($this->organization, 'engineer'))->get(route('projects.show', $this->project))->assertOk()->assertDontSee(route('quotations.index', $this->project));
    }

    public function test_the_manager_takes_a_quotation_from_the_form_to_a_shared_link(): void
    {
        $this->actingAs($this->manager);

        $this->get(route('quotations.index', $this->project))->assertOk()->assertSee('No quotations yet');
        $this->get(route('quotations.create', $this->project))->assertOk()->assertSee('Excavation for foundation in all soils')->assertSee('The app does not choose one.');

        $this->post(route('quotations.store', $this->project), $this->form())->assertRedirect()->assertSessionHasNoErrors();
        $quotation = ClientQuotation::sole();
        $this->assertSame([$this->organization->id, $this->project->id, $this->manager->id, '157847.17'], [$quotation->organization_id, $quotation->project_id, $quotation->created_by, $quotation->total]);

        $this->post(route('quotations.stages.store', $quotation), ['title' => 'On agreement', 'basis' => 'percent', 'percent' => '40', 'client_uuid' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('quotations.stages.store', $quotation), ['title' => 'On handing over', 'basis' => 'percent', 'percent' => '60', 'condition_text' => 'Keys handed over', 'client_uuid' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $stage = $quotation->stages()->first();
        $this->patch(route('quotation-stages.update', $stage), ['title' => 'On signing', 'basis' => 'percent', 'percent' => '40'])->assertSessionHasNoErrors();

        $page = $this->get(route('quotations.show', $quotation))->assertOk()
            ->assertSee('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.')
            ->assertSee('The total of this quotation is', false)
            ->assertSee('On signing')
            ->assertSee('₹63,138.87')
            ->assertSee('₹94,708.30');

        // The page offers a tick for every figure the service will ask for, and marks each placed figure.
        $ticks = $this->allTicks($quotation);

        foreach ($ticks['number_ticks'] as $tick) {
            $page->assertSee('name="number_ticks[]" value="'.$tick.'"', false);
        }

        $page->assertSee('name="fact_fingerprint" value="'.$ticks['fact_fingerprint'].'"', false);
        $page->assertSee('name="text_fingerprint" value="'.$ticks['text_fingerprint'].'"', false);
        $page->assertSee('<mark class="rounded-[2px] bg-haldi/35 px-0.5" title="Quotation total">₹1,57,847.17</mark>.', false);

        // Without the ticks, or without either fingerprint of what the page showed, nothing is issued.
        $this->post(route('quotations.issue', $quotation), [...$ticks, 'number_ticks' => ['total']])->assertSessionHasErrors('number_ticks');
        $this->post(route('quotations.issue', $quotation), ['number_ticks' => $ticks['number_ticks'], 'text_fingerprint' => $ticks['text_fingerprint']])->assertSessionHasErrors('fact_fingerprint');
        $this->post(route('quotations.issue', $quotation), ['number_ticks' => $ticks['number_ticks'], 'fact_fingerprint' => $ticks['fact_fingerprint']])->assertSessionHasErrors('text_fingerprint');
        $this->post(route('quotations.issue', $quotation), [...$ticks, 'text_fingerprint' => str_repeat('a', 64)])->assertSessionHasErrors('facts');
        $this->assertSame(ClientQuotation::DRAFT, $quotation->fresh()->status);

        $this->post(route('quotations.issue', $quotation), $ticks)->assertSessionHasNoErrors();
        $this->assertSame([ClientQuotation::ISSUED, $this->manager->id], [$quotation->fresh()->status, $quotation->fresh()->issued_by]);

        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('No link has been made yet.')->assertSee($this->manager->name)->assertDontSee('<mark', false);

        $this->post(route('quotations.share', $quotation))->assertSessionHasNoErrors();
        $shared = $quotation->fresh();
        $this->assertTrue($shared->isShared());
        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('/shared/quotations/'.$quotation->id.'/'.$shared->share_token)->assertSee('Send on WhatsApp');

        $this->post(route('quotation-stages.reached', $stage), ['reached' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull($stage->fresh()->reached_on);

        $this->post(route('quotations.share.revoke', $quotation))->assertSessionHasNoErrors();
        $this->assertFalse($quotation->fresh()->isShared());
        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('The link was withdrawn. It no longer opens.')->assertDontSee($shared->share_token);

        $this->post(route('quotations.revise', $quotation), ['client_uuid' => (string) Str::uuid()])->assertRedirect(route('quotations.show', ClientQuotation::where('revision', 2)->sole()));
        $this->get(route('quotations.index', $this->project))->assertOk()->assertSee('Revision 2')->assertSee('Revision 1')->assertSee('₹1,57,847.17');

        $this->assertSame(
            ['quotation.created', 'quotation.issued', 'quotation.shared', 'quotation.share_revoked', 'quotation.revised'],
            AuditLog::where('action', 'like', 'quotation.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_sending_the_form_twice_makes_one_quotation_and_one_stage(): void
    {
        $this->actingAs($this->manager);
        $form = $this->form();

        $first = $this->post(route('quotations.store', $this->project), $form)->assertRedirect();
        $this->post(route('quotations.store', $this->project), $form)->assertRedirect($first->headers->get('Location'));
        $this->assertSame(1, ClientQuotation::count());

        $quotation = ClientQuotation::sole();
        $stage = ['title' => 'Advance', 'basis' => 'amount', 'amount' => '5000', 'client_uuid' => (string) Str::uuid()];
        $this->post(route('quotations.stages.store', $quotation), $stage)->assertSessionHasNoErrors();
        $this->post(route('quotations.stages.store', $quotation), $stage)->assertSessionHasNoErrors();
        $this->assertSame(1, $quotation->stages()->count());
    }

    public function test_a_refused_change_to_a_stage_comes_back_open_in_that_stage_with_what_was_typed_and_why(): void
    {
        $quotation = $this->withStages($this->draft());
        [$first, $second] = $quotation->stages()->get()->all();
        $show = route('quotations.show', $quotation);
        $this->actingAs($this->manager);
        $open = '/<details class="mt-1"\s+open\s*>/';
        $refused = fn (string $method, string $address, array $body) => $this->from($show)->followingRedirects()->{$method}($address, $body)->assertOk()->getContent();

        $page = $this->get($show)->assertOk()->assertSee('name="_form" value="stage-'.$second->id.'"', false)->assertSee('name="_form" value="add-stage"', false);
        $this->assertSame(0, preg_match_all($open, $page->getContent()));

        $typed = ['_form' => 'stage-'.$second->id, 'title' => 'Plinth and walls', 'basis' => 'percent', 'percent' => '100.5', 'position' => '2', 'condition_text' => 'Walls up to lintel'];
        $html = $refused('patch', route('quotation-stages.update', $second), $typed);
        $message = e(__('validation.max.numeric', ['attribute' => 'percent', 'max' => 100]));

        // Only that stage's form is open, it holds what was typed, and the reason stands beside the field as well as at the top.
        $this->assertSame(1, preg_match_all($open, $html));
        $this->assertSame(1, substr_count($html, 'value="Plinth and walls"'));
        $this->assertStringContainsString('value="100.5"', $html);
        $this->assertStringContainsString('value="Walls up to lintel"', $html);
        $this->assertSame(1, substr_count($html, '<p class="error">'.$message.'</p>'));
        $this->assertSame(2, substr_count($html, $message));
        $this->assertStringContainsString('value="'.$first->title.'"', $html, 'The other stages show what is stored.');
        $this->assertSame(['On completion of plinth', '33.3333'], [$second->fresh()->title, $second->fresh()->percent]);

        // An amount stage left without its amount comes back as an amount stage.
        $html = $refused('patch', route('quotation-stages.update', $second), [...$typed, 'basis' => 'amount', 'percent' => null, 'amount' => '']);
        $this->assertSame(1, preg_match_all($open, $html));
        $this->assertStringContainsString('x-data="{ basis: \'amount\' }"', $html);

        // A refused new stage keeps its own form, and opens no stage.
        $html = $refused('post', route('quotations.stages.store', $quotation), ['_form' => 'add-stage', 'title' => 'Extra', 'basis' => 'percent', 'percent' => '101', 'client_uuid' => (string) Str::uuid()]);
        $this->assertSame(0, preg_match_all($open, $html));
        $this->assertSame(1, substr_count($html, 'value="Extra"'));
        $this->assertSame(1, substr_count($html, '<p class="error">'.$message.'</p>'));
        $this->assertSame(3, $quotation->stages()->count());
    }

    public function test_an_issued_quotation_whose_note_cannot_be_shown_asks_for_no_save_it_cannot_take(): void
    {
        $issued = $this->issued();
        // The facts kept with it no longer fit the cover note, as after a change of wording in the code.
        DB::table('client_quotations')->where('id', $issued->id)->update(['facts' => json_encode([])]);
        $this->actingAs($this->manager);

        $this->get(route('quotations.show', $issued))->assertOk()
            ->assertSee('The cover note of this issued quotation cannot be shown now. Its lines, totals and stages above stand as issued.')
            ->assertSee('To give the client a cover note, make a revision below and issue it.')
            ->assertSee('Make a revision')
            ->assertSee('₹1,57,847.17')
            ->assertDontSee('Save the draft again');

        $this->actingAs($this->member($this->organization, 'accounts'))->get(route('quotations.show', $issued))->assertOk()
            ->assertSee('The cover note of this issued quotation cannot be shown now.')
            ->assertDontSee('make a revision below')
            ->assertDontSee('Save the draft again');

        // A draft can be saved, so there the page says so, to the person who may save it.
        $draft = $this->draft();
        DB::table('client_quotations')->where('id', $draft->id)->update(['facts' => json_encode([])]);
        $this->actingAs($this->manager)->get(route('quotations.show', $draft))->assertOk()->assertSee('The cover note cannot be shown from the saved figures. Save the draft again.');
        $this->actingAs($this->member($this->organization, 'qs'))->get(route('quotations.show', $draft))->assertOk()
            ->assertSee('The cover note cannot be shown from the saved figures.')
            ->assertDontSee('Save the draft again');
    }

    public function test_the_pages_say_where_a_stage_is_marked_while_the_next_revision_is_a_draft(): void
    {
        $issued = $this->issued();
        [$agreement, $plinth] = $issued->stages()->get()->all();
        $this->quotations()->markReached($agreement, ['reached' => true, 'reached_on' => '2026-11-02'], $this->manager);
        $this->quotations()->markReached($plinth, ['reached' => true, 'reached_on' => '2026-11-05'], $this->manager);
        $revision = $this->quotations()->revise($issued, $this->manager);
        $this->quotations()->updateStage($revision->stages()->get()[1], ['title' => 'On completion of plinth and walls', 'basis' => 'percent', 'percent' => '33.3333', 'condition_text' => 'Plinth beam cast and cured'], $this->manager);
        $this->actingAs($this->manager);

        // The issued revision still takes marks, and says where they go.
        $this->get(route('quotations.show', $issued))->assertOk()
            ->assertSee('Until it is issued, mark the stages reached here. When it is issued, each mark goes to the stage of the new revision that is unchanged.')
            ->assertSee('Mark as reached')
            ->assertSee('Undo');

        // The draft shows the mark it will keep, and the one it will not.
        $this->get(route('quotations.show', $revision))->assertOk()
            ->assertSee('Reached on revision 1')
            ->assertSee('2 November 2026')
            ->assertSee('Kept when this revision is issued, unless the stage is changed.')
            ->assertSee('These stages are marked as reached on revision 1, and no stage of this draft is the same.')
            ->assertSee('<li>On completion of plinth · 5 November 2026</li>', false);

        $this->post(route('quotations.issue', $revision), $this->allTicks($revision))->assertSessionHasNoErrors();
        $this->assertSame([true, false, false], $revision->stages()->get()->map(fn (ClientPaymentStage $stage) => $stage->isReached())->all());
        $this->get(route('quotations.show', $revision))->assertOk()->assertSee('Reached')->assertSee('Not reached yet')->assertDontSee('Reached on revision 1');
    }

    public function test_an_issued_quotation_answers_409_to_every_change(): void
    {
        $issued = $this->issued();
        $stage = $issued->stages()->first();
        $this->actingAs($this->owner);

        $this->patch(route('quotations.update', $issued), ['language' => 'hi', 'client_name' => 'Other', 'gst_pct' => '1'])->assertStatus(409);
        $this->patch(route('quotations.update', $issued), ['boq_item_ids' => [$this->lines[0]->id]])->assertStatus(409);
        $this->post(route('quotations.stages.store', $issued), ['title' => 'Extra', 'basis' => 'amount', 'amount' => '1'])->assertStatus(409);
        $this->patch(route('quotation-stages.update', $stage), ['title' => 'Renamed', 'basis' => 'percent', 'percent' => '30'])->assertStatus(409);
        $this->delete(route('quotation-stages.destroy', $stage))->assertStatus(409);

        $after = $issued->fresh();
        $this->assertSame(['Anil Deshmukh', 'en', '157847.17', 3, 3], [$after->client_name, $after->language, $after->total, $after->items()->count(), $after->stages()->count()]);
    }

    public function test_the_form_refuses_what_does_not_belong_in_a_quotation(): void
    {
        $this->actingAs($this->manager);
        $elsewhere = $this->line($this->makeProject($this->organization, ['name' => 'Other Site']), 'X-01', 'Line of another project', 'nos', '1.000', '10.00');

        $this->post(route('quotations.store', $this->project), $this->form(['boq_item_ids' => [$this->lines[0]->id, $elsewhere->id]]))->assertSessionHasErrors('boq_item_ids.1');
        $this->post(route('quotations.store', $this->project), $this->form(['boq_item_ids' => [$this->lines[0]->id, $this->lines[0]->id]]))->assertSessionHasErrors('boq_item_ids.0');

        $bad = [
            'boq_item_ids' => [[], 'all'],
            'gst_pct' => ['100.01', '-1', 'seven and a half', '7.555'],
            'valid_until' => [today()->subDay()->toDateString(), 'soon'],
            'language' => ['fr', ''],
            'client_name' => [''],
            'client_uuid' => ['not-a-uuid'],
        ];

        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                $this->post(route('quotations.store', $this->project), $this->form([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, ClientQuotation::count());

        $quotation = $this->draft();

        foreach ([['title' => '', 'basis' => 'percent', 'percent' => '10'], ['title' => 'A', 'basis' => 'percent'], ['title' => 'A', 'basis' => 'percent', 'percent' => '100.5'],
            ['title' => 'A', 'basis' => 'percent', 'percent' => '0'], ['title' => 'A', 'basis' => 'amount'], ['title' => 'A', 'basis' => 'amount', 'amount' => '-5'], ['title' => 'A', 'basis' => 'share', 'amount' => '5']] as $stage) {
            $this->post(route('quotations.stages.store', $quotation), $stage)->assertSessionHasErrors();
        }

        $this->assertSame(0, ClientPaymentStage::count());
    }

    public function test_another_company_reaches_nothing_and_can_use_nothing_of_ours(): void
    {
        [$draft, $issued] = $this->pair();
        $before = $this->state();

        $other = $this->tenant('Konkan Builders', 'business');
        $stranger = $this->member($other, 'owner');
        $theirProject = $this->makeProject($other, ['name' => 'Their Site']);
        $theirLine = $this->line($theirProject, 'T-01', 'Their own line', 'nos', '2.000', '10.00');
        $ourClient = Party::create(['organization_id' => $this->organization->id, 'type' => 'client', 'name' => 'Anil Deshmukh']);

        // The addresses are worked out first: once the stranger is signed in, the tenant scope hides our rows from the test too.
        $routes = $this->routes($draft, $issued);
        $this->actingAs($stranger);

        foreach ($routes as $name => $route) {
            $this->send($route)->assertNotFound();
        }

        $this->assertSame($before, $this->state());

        // In their own project they cannot quote our BOQ lines or name our client.
        $form = ['client_uuid' => (string) Str::uuid(), 'client_name' => 'Their Client', 'language' => 'en', 'boq_item_ids' => [$theirLine->id]];
        $this->post(route('quotations.store', $theirProject), [...$form, 'boq_item_ids' => [$this->lines[0]->id]])->assertSessionHasErrors('boq_item_ids.0');
        $this->post(route('quotations.store', $theirProject), [...$form, 'party_id' => $ourClient->id])->assertSessionHasErrors('party_id');
        $this->assertSame(0, ClientQuotation::withoutGlobalScopes()->where('organization_id', $other->id)->count());

        // Their own quotation works, takes its own number series, and their list shows only theirs.
        $this->post(route('quotations.store', $theirProject), $form)->assertSessionHasNoErrors();
        $theirs = ClientQuotation::withoutGlobalScopes()->where('organization_id', $other->id)->sole();
        $this->assertStringEndsWith('-0001', $theirs->number);
        $this->get(route('quotations.index', $theirProject))->assertOk()->assertSee('Their Client')->assertDontSee('Anil Deshmukh');

        // Sending our draft's client_uuid from their side neither returns nor touches our draft.
        $ours = $this->draft(['client_uuid' => $uuid = (string) Str::uuid()]);
        $this->actingAs($stranger)->post(route('quotations.store', $theirProject), [...$form, 'client_uuid' => $uuid])->assertSessionHasNoErrors();
        $this->assertSame(2, ClientQuotation::withoutGlobalScopes()->where('organization_id', $other->id)->count());
        $this->assertSame('Anil Deshmukh', $ours->fresh()->client_name);
    }

    public function test_someone_limited_to_another_project_reaches_no_quotation_of_this_one(): void
    {
        [$draft, $issued] = $this->pair();
        $before = $this->state();
        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);

        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $routes = $this->routes($draft, $issued);
        $this->actingAs($limited->refresh());

        foreach ($routes as $name => $route) {
            $this->send($route)->assertNotFound();
        }

        $this->assertSame($before, $this->state());
        $this->get(route('quotations.index', $elsewhere))->assertOk()->assertDontSee('Anil Deshmukh');

        // A repeat that carries the client_uuid of a draft in the hidden project is answered as not found.
        $hidden = $this->draft(['client_uuid' => $uuid = (string) Str::uuid()]);
        $line = $this->line($elsewhere, 'E-01', 'Line of the other site', 'nos', '1.000', '10.00');
        $this->actingAs($limited)->post(route('quotations.store', $elsewhere), ['client_uuid' => $uuid, 'client_name' => 'X', 'language' => 'en', 'boq_item_ids' => [$line->id]])->assertSessionHasErrors('client_uuid');
        $this->assertSame(3, ClientQuotation::withoutGlobalScopes()->count());
        $this->assertSame('Anil Deshmukh', $hidden->fresh()->client_name);
    }

    public function test_a_client_from_the_companys_list_gives_the_quotation_its_name(): void
    {
        $client = Party::create(['organization_id' => $this->organization->id, 'type' => 'client', 'name' => 'Deshmukh Family']);
        $vendor = Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => 'Cement Depot']);
        $this->actingAs($this->manager);

        $this->post(route('quotations.store', $this->project), $this->form(['client_name' => '', 'party_id' => $vendor->id]))->assertSessionHasErrors('party_id');
        $this->post(route('quotations.store', $this->project), $this->form(['client_name' => '', 'party_id' => $client->id]))->assertSessionHasNoErrors();

        $this->assertSame(['Deshmukh Family', $client->id], [ClientQuotation::sole()->client_name, ClientQuotation::sole()->party_id]);
    }

    public function test_saving_the_details_leaves_the_lines_alone_and_copying_the_lines_again_leaves_the_details(): void
    {
        $quotation = $this->draft();
        $this->lines[0]->update(['rate' => '5000.00']);
        $this->actingAs($this->manager);

        $this->patch(route('quotations.update', $quotation), ['client_name' => 'A. Deshmukh', 'language' => 'hi', 'gst_pct' => '', 'valid_until' => '', 'opening' => '', 'closing' => ''])->assertSessionHasNoErrors();
        $saved = $quotation->fresh();
        $this->assertSame(['A. Deshmukh', 'hi', null, null, '146834.58'], [$saved->client_name, $saved->language, $saved->gst_pct, $saved->valid_until, $saved->total]);
        $this->assertSame('4200.10', $saved->items()->first()->rate, 'Saving the details copies nothing from the BOQ.');

        // Copying two of the lines again takes the BOQ as it now stands.
        $this->patch(route('quotations.update', $quotation), ['boq_item_ids' => [$this->lines[0]->id, $this->lines[2]->id]])->assertSessionHasNoErrors();
        $copied = $quotation->fresh();
        $this->assertSame(['A. Deshmukh', 'hi'], [$copied->client_name, $copied->language]);
        $this->assertSame(['5000.00', '18333.33'], $copied->items()->pluck('rate')->all());
        $this->assertSame('80833.33', $copied->total);
        $this->assertTrue(FactSheet::fromArray($copied->facts)->matches($copied->fact_fingerprint));
    }
}
