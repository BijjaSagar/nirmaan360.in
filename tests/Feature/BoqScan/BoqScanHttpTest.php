<?php

namespace Tests\Feature\BoqScan;

use App\Models\AuditLog;
use App\Models\BoqScanRow;
use App\Models\CustomRole;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Support\PlatformSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Who may reach each route of the scanned BOQ reader, what each answers, and what never depends on the provider. */
class BoqScanHttpTest extends BoqScanTestCase
{
    private function holding(array $permissions): User
    {
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);
        $user = $this->member($this->organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    private function limited(string $role, Project ...$projects): User
    {
        $user = $this->member($this->organization, $role);
        app(TeamAccessService::class)->applyProjects($user, true, array_map(fn (Project $project) => $project->id, $projects));

        return $user->refresh();
    }

    private function create(User $by, ?Project $project = null, array $body = []): TestResponse
    {
        return $this->actingAs($by)->postJson(route('boq-scans.store', $project ?? $this->project), [
            'client_uuid' => (string) Str::uuid(), 'pages_total' => 12, 'language' => 'mr', 'source_name' => 'Estimate scan.pdf', ...$body,
        ]);
    }

    /**
     * Every route of the module with a body that would pass, as [label, method, url, body, api].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: bool}>
     */
    private function routes(DocumentRead $read, BoqScanRow $row): array
    {
        return [
            ['create', 'POST', route('boq-scans.store', $this->project), ['client_uuid' => (string) Str::uuid(), 'pages_total' => 2, 'language' => 'en'], false],
            ['rows', 'GET', route('boq-scans.rows', $read), [], false],
            ['add', 'POST', route('boq-scans.rows.store', $read), ['client_uuid' => (string) Str::uuid(), 'page_no' => 1, 'description' => 'Typed line'], false],
            ['update', 'PATCH', route('boq-scans.rows.update', [$read, $row]), ['description' => 'Changed'], false],
            ['exclude', 'DELETE', route('boq-scans.rows.exclude', [$read, $row]), [], false],
            ['join', 'POST', route('boq-scans.rows.join', [$read, $row]), [], false],
            ['confirm row', 'POST', route('boq-scans.rows.confirm', [$read, $row]), [], false],
            ['override', 'POST', route('boq-scans.rows.override', [$read, $row]), ['flag' => 'amount_mismatch', 'reason' => 'The page is wrong here'], false],
            ['confirm clean', 'POST', route('boq-scans.pages.confirm-clean', [$read, 1]), ['viewed' => true], false],
            ['no lines', 'POST', route('boq-scans.pages.no-lines', [$read, 1]), [], false],
            ['acks', 'POST', route('boq-scans.acks.store', $read), ['flag' => 'no_grand_total'], false],
            ['confirm', 'POST', route('boq-scans.confirm', $read), [], false],
            ['discard', 'POST', route('boq-scans.discard', $read), [], false],
            ['api create', 'POST', '/api/v1/projects/'.$this->project->id.'/boq-scans', ['client_uuid' => (string) Str::uuid(), 'pages_total' => 2, 'language' => 'en'], true],
            ['api show', 'GET', '/api/v1/boq-scans/'.$read->id, [], true],
        ];
    }

    private function send(User $user, string $method, string $url, array $body, bool $api): TestResponse
    {
        if ($api) {
            Sanctum::actingAs($user);

            return $this->json($method, $url, $body);
        }

        return $this->actingAs($user)->json($method, $url, $body);
    }

    private function fixture(): array
    {
        $read = $this->scan();
        $this->cleanPage($read);

        return [$read, $this->row($read, '2')];
    }

    public function test_site_roles_accounts_and_anyone_without_money_view_are_refused_everywhere(): void
    {
        [$read, $row] = $this->fixture();
        $people = [
            'engineer' => $this->member($this->organization, 'engineer'),
            'store' => $this->member($this->organization, 'store'),
            'viewer' => $this->member($this->organization, 'viewer'),
            'accounts' => $this->member($this->organization, 'accounts'),
            'boq.manage without money.view' => $this->holding(['project.view', 'boq.view', 'boq.manage', 'agents.view', 'agents.use']),
        ];

        foreach ($people as $who => $user) {
            foreach ($this->routes($read, $row) as [$label, $method, $url, $body, $api]) {
                $this->send($user, $method, $url, $body, $api)->assertStatus(403, "{$who} at {$label}");
            }
        }

        $this->assertSame(DocumentRead::READY, $read->fresh()->status);
        $this->assertSame(1, DocumentRead::withoutGlobalScopes()->count());
        $this->assertSame('Excavation in ordinary soil', $this->row($read, '1')->description);
    }

    public function test_another_company_and_a_person_limited_to_another_project_get_not_found(): void
    {
        [$read, $row] = $this->fixture();
        $rival = $this->tenant('Godavari Infra');
        $this->consent($rival);
        $outsider = $this->member($rival, 'owner');
        $elsewhere = $this->makeProject($this->organization, ['code' => 'SB2', 'name' => 'South Block']);
        $limited = $this->limited('pm', $elsewhere);

        foreach (['another company' => $outsider, 'limited to another project' => $limited] as $who => $user) {
            foreach ($this->routes($read, $row) as [$label, $method, $url, $body, $api]) {
                $this->send($user, $method, $url, $body, $api)->assertNotFound("{$who} at {$label}");
            }
        }

        $this->assertSame(1, DocumentRead::withoutGlobalScopes()->count());
    }

    public function test_a_row_of_another_read_is_not_found_under_this_one(): void
    {
        [$read] = $this->fixture();
        $other = $this->scan();
        $this->cleanPage($other);
        $foreign = $this->row($other, '2');

        foreach (['rows.update' => 'PATCH', 'rows.exclude' => 'DELETE', 'rows.join' => 'POST', 'rows.confirm' => 'POST', 'rows.override' => 'POST'] as $name => $method) {
            $this->actingAs($this->owner)->json($method, route("boq-scans.{$name}", [$read, $foreign]), ['description' => 'x', 'flag' => 'amount_mismatch', 'reason' => 'The page is wrong here'])->assertNotFound($name);
        }

        $this->assertSame('Filling with murum in layers', $foreign->fresh()->description);
        $this->assertNull($foreign->fresh()->confirmed_at);
    }

    public function test_a_read_of_another_kind_is_not_found_on_these_routes(): void
    {
        $read = $this->scan(['kind' => 'tender', 'project_id' => null]);

        $this->actingAs($this->owner)->getJson(route('boq-scans.rows', $read))->assertNotFound();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/boq-scans/'.$read->id)->assertNotFound();
    }

    public function test_a_plan_without_the_ai_features_is_refused(): void
    {
        $this->organization->update(['plan' => 'contractor']);
        [$read, $row] = $this->fixture();

        foreach ($this->routes($read, $row) as [$label, $method, $url, $body, $api]) {
            $this->send($this->owner, $method, $url, $body, $api)->assertForbidden($label);
        }
    }

    public function test_a_scan_is_created_once_per_client_uuid_and_a_project_manager_may_create_one(): void
    {
        $pm = $this->member($this->organization, 'pm');
        $uuid = (string) Str::uuid();

        $first = $this->create($pm, body: ['client_uuid' => $uuid])->assertCreated()->assertJson(['status' => 'draft']);
        $this->create($pm, body: ['client_uuid' => $uuid])->assertOk()->assertJson(['id' => $first->json('id'), 'status' => 'draft']);

        $read = DocumentRead::withoutGlobalScopes()->findOrFail($first->json('id'));
        $this->assertSame(['boq', $this->project->id, 12, 'web', 'mr', 'Estimate scan.pdf', $pm->id], [$read->kind, (int) $read->project_id, (int) $read->pages_total, $read->channel, $read->options['language'], $read->source_name, (int) $read->user_id]);

        // The same client_uuid for another project is not a repeat of this one.
        $other = $this->makeProject($this->organization, ['code' => 'SB2', 'name' => 'South Block']);
        $this->create($pm, $other, ['client_uuid' => $uuid])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        $this->create($pm, body: ['pages_total' => 61])->assertUnprocessable()->assertJsonValidationErrors('pages_total');
        $this->create($pm, body: ['language' => 'gu'])->assertUnprocessable()->assertJsonValidationErrors('language');
        $this->assertSame(1, DocumentRead::withoutGlobalScopes()->count());
    }

    public function test_the_phone_creates_a_scan_and_follows_it_until_it_is_ready_to_check_on_the_web(): void
    {
        Sanctum::actingAs($this->owner);
        $uuid = (string) Str::uuid();

        $created = $this->postJson('/api/v1/projects/'.$this->project->id.'/boq-scans', ['client_uuid' => $uuid, 'pages_total' => 2, 'language' => 'hi'])->assertCreated();
        $this->postJson('/api/v1/projects/'.$this->project->id.'/boq-scans', ['client_uuid' => $uuid, 'pages_total' => 2, 'language' => 'hi'])->assertOk()->assertJson(['id' => $created->json('id')]);
        $this->assertSame('mobile', DocumentRead::withoutGlobalScopes()->findOrFail($created->json('id'))->channel);

        [$read] = $this->fixture();
        $read->update(['pages_read' => 1]);

        $this->getJson('/api/v1/boq-scans/'.$read->id)->assertOk()->assertExactJson([
            'id' => $read->id, 'status' => 'ready', 'pages_total' => 2, 'pages_read' => 1, 'pages_failed' => 0,
            'rows' => 5, 'needs_attention' => 0, 'review_url' => url('boq-scans/'.$read->id),
        ]);
    }

    public function test_the_review_answers_one_page_at_a_time_with_its_checks_and_totals(): void
    {
        [$read] = $this->fixture();

        $page = $this->actingAs($this->owner)->getJson(route('boq-scans.rows', [$read, 'page_no' => 1]))->assertOk();

        $this->assertCount(5, $page->json('rows'));
        $line = collect($page->json('rows'))->firstWhere('code', '1');
        $this->assertSame(['item_no' => '1', 'description' => 'Excavation in ordinary soil', 'unit' => 'cum', 'qty' => '120.500', 'rate' => '450.00', 'amount' => '54,225.00'], $line['raw']);
        $this->assertSame(['120.500', '450.00', '54225.00', '54225.00'], [$line['qty'], $line['rate'], $line['amount'], $line['product']]);
        $this->assertSame(['amount'], $line['had_comma']);
        $this->assertSame([['code' => 'unconfirmed_row', 'level' => 'block', 'params' => [], 'message' => 'Not confirmed yet.']], $line['flags']);
        $this->assertTrue($line['can_confirm']);
        $this->assertFalse($line['page_confirmable'], 'The first line of a page is confirmed on its own.');
        $this->assertTrue(collect($page->json('rows'))->firstWhere('code', '2')['page_confirmable']);
        $this->assertSame('140625.00', $page->json('summary.printed_total'));
        $this->assertSame(3, $page->json('summary.unconfirmed'));
        $this->assertSame(1, $page->json('page.page_no'));
        $this->assertTrue($page->json('page.confirm_clean'));
        $this->assertSame([1, 2], array_column($page->json('pages'), 'page_no'));

        // A figure typed with Indian commas is stored as a plain decimal, and anything else is refused.
        $row = $this->row($read, '2');
        $this->actingAs($this->owner)->patchJson(route('boq-scans.rows.update', [$read, $row]), ['rate' => '3,00.5'])->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->actingAs($this->owner)->patchJson(route('boq-scans.rows.update', [$read, $row]), ['qty' => '80', 'rate' => '300'])
            ->assertOk()->assertJsonPath('row.typed', ['qty', 'rate'])->assertJsonPath('row.rate', '300.00');
    }

    public function test_switching_consent_off_or_losing_the_provider_stops_new_scans_but_not_the_review_or_the_import(): void
    {
        [$read] = $this->fixture();
        $this->confirmAll($read);
        $this->consent($this->organization, false);

        $this->create($this->owner)->assertStatus(409)->assertJson(['code' => 'consent_required']);
        $this->actingAs($this->owner)->getJson(route('boq-scans.rows', $read))->assertOk();

        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        $this->create($this->owner)->assertStatus(409)->assertJson(['code' => 'not_connected']);

        $this->actingAs($this->owner)->postJson(route('boq-scans.confirm', $read))->assertOk()->assertJson(['status' => 'confirmed', 'result' => ['created' => 3]]);
        $this->assertSame(DocumentRead::CONFIRMED, $read->fresh()->status);
    }

    public function test_a_hand_made_request_to_confirm_with_an_unconfirmed_line_is_refused(): void
    {
        [$read] = $this->fixture();

        $this->actingAs($this->owner)->postJson(route('boq-scans.confirm', $read))->assertStatus(422)
            ->assertJson(['code' => 'flags_open', 'unconfirmed' => 3]);

        $this->assertSame(DocumentRead::READY, $read->fresh()->status);
        $this->assertSame(0, $this->project->boqItems()->count());
    }

    public function test_rows_can_be_added_joined_left_out_ticked_and_confirmed_by_page_through_the_routes(): void
    {
        [$read] = $this->fixture();
        $owner = $this->actingAs($this->owner);
        $uuid = (string) Str::uuid();

        $added = $owner->postJson(route('boq-scans.rows.store', $read), ['client_uuid' => $uuid, 'page_no' => 1, 'after_row_id' => $this->row($read, '3')->id, 'kind' => 'note', 'description' => 'below plinth level'])->assertCreated();
        $owner->postJson(route('boq-scans.rows.store', $read), ['client_uuid' => $uuid, 'page_no' => 1, 'kind' => 'note', 'description' => 'below plinth level'])->assertOk()->assertJsonPath('row.id', $added->json('row.id'));

        $owner->postJson(route('boq-scans.rows.join', [$read, $added->json('row.id')]))->assertOk()
            ->assertJsonPath('row.description', 'PCC 1:4:8 below footings below plinth level');

        $owner->postJson(route('boq-scans.pages.confirm-clean', [$read, 1]), ['viewed' => false])->assertStatus(422)->assertJson(['code' => 'page_not_viewed']);
        $owner->postJson(route('boq-scans.pages.confirm-clean', [$read, 1]), ['viewed' => true])->assertOk()->assertJson(['confirmed' => 1]);
        $this->assertSame('page', $this->row($read, '2')->confirmed_mode);

        $owner->postJson(route('boq-scans.rows.confirm', [$read, $this->row($read, '1')]))->assertOk()->assertJsonPath('row.confirmed_mode', 'row');
        $owner->deleteJson(route('boq-scans.rows.exclude', [$read, $this->row($read, '3')]))->assertOk()->assertJsonPath('row.kind', 'excluded');

        $owner->postJson(route('boq-scans.acks.store', $read), ['flag' => 'total_mismatch', 'row_id' => 999999, 'reason' => 'Not a standing check'])->assertUnprocessable();
        $owner->postJson(route('boq-scans.pages.no-lines', [$read, 2]))->assertOk()->assertJsonPath('page.no_lines', true);
        $owner->postJson(route('boq-scans.pages.no-lines', [$read, 3]))->assertUnprocessable()->assertJsonValidationErrors('page_no');
    }

    public function test_a_finished_read_can_be_discarded_and_then_nothing_more_can_be_done_with_it(): void
    {
        [$read, $row] = $this->fixture();

        $this->actingAs($this->owner)->postJson(route('boq-scans.discard', $read))->assertOk()->assertJson(['status' => 'discarded']);
        $this->actingAs($this->owner)->postJson(route('boq-scans.discard', $read))->assertOk()->assertJson(['status' => 'discarded']);
        $this->actingAs($this->owner)->patchJson(route('boq-scans.rows.update', [$read, $row]), ['description' => 'Changed'])->assertStatus(409)->assertJson(['code' => 'wrong_state']);
        $this->actingAs($this->owner)->postJson(route('boq-scans.confirm', $read))->assertStatus(409);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'ai.read_discarded')->count());
    }

    public function test_other_requests_of_a_person_do_not_use_up_the_counter_for_new_scans(): void
    {
        $limiter = app(RateLimiter::class);

        foreach (range(1, 300) as $ignored) {
            $limiter->hit(sha1((string) $this->owner->id), 60);
        }

        $this->create($this->owner)->assertCreated();
    }

    public function test_the_phone_is_told_who_may_scan_boq_pages(): void
    {
        $ask = function (User $user) {
            Sanctum::actingAs($user);

            return $this->getJson('/api/v1/document-reading/status')->assertOk()->json('can.boq_scan');
        };

        $this->assertTrue($ask($this->owner));
        $this->assertTrue($ask($this->member($this->organization, 'pm')));
        $this->assertFalse($ask($this->member($this->organization, 'engineer')));
        $this->assertFalse($ask($this->member($this->organization, 'accounts')));
        $this->assertFalse($ask($this->holding(['project.view', 'boq.view', 'boq.manage', 'agents.view', 'agents.use'])));

        // Without this module's reader registered, nobody is offered it.
        config(['modules.boq_scan.read_kinds' => []]);
        $this->assertFalse($ask($this->owner));
    }
}
