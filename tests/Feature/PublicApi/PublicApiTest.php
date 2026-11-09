<?php

namespace Tests\Feature\PublicApi;

use App\Models\AuditLog;
use App\Models\CompanyApiKey;
use App\Models\Dpr;
use App\Models\DprManpower;
use App\Models\DprWorkItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\RaBillDeduction;
use App\Models\RaBillItem;
use App\Services\PublicApi\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private const BASE = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** A Business company with an owner, a project, one BOQ line and an all-scopes key. */
    private function company(string $name = 'Acme Infra', string $plan = 'business'): object
    {
        $organization = $this->tenant($name, $plan);
        $owner = $this->member($organization, 'owner');
        $project = $this->makeProject($organization, ['name' => "{$name} Tower"]);
        $item = $this->makeBoqItem($project, '100.000', '5000.00');
        $created = app(ApiKeyService::class)->create($owner, ['name' => 'Power BI', 'scopes' => array_keys(ApiKeyService::SCOPES)]);

        return (object) [
            'organization' => $organization, 'owner' => $owner, 'project' => $project, 'item' => $item,
            'key' => $created['key'], 'secret' => $created['secret'],
        ];
    }

    private function api(string $path, ?string $secret, array $query = [])
    {
        return $this->getJson(self::BASE.$path.($query ? '?'.http_build_query($query) : ''), $secret ? ['Authorization' => 'Bearer '.$secret] : []);
    }

    private function makeDpr(Project $project, string $date = '2026-11-02', string $status = 'APPROVED'): Dpr
    {
        $dpr = Dpr::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'report_date' => $date, 'status' => $status, 'weather' => 'Clear', 'summary' => 'Slab cast']);
        DprManpower::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'trade' => 'Mason', 'count' => 12]);
        DprManpower::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'trade' => 'Helper', 'count' => 8]);
        DprWorkItem::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'boq_item_id' => $project->boqItems()->first()?->id, 'qty' => '12.500']);

        return $dpr;
    }

    private function makeBill(Project $project, string $number = 'RA-1'): RaBill
    {
        $bill = RaBill::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'type' => 'client', 'bill_no' => 1, 'number' => $number,
            'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => 'DRAFT',
            'gross_amount' => '100000.00', 'deduction_total' => '5000.00', 'gst_amount' => '17100.00', 'net_payable' => '112100.00',
        ]);
        $item = $project->boqItems()->first();
        RaBillItem::create([
            'organization_id' => $project->organization_id, 'ra_bill_id' => $bill->id, 'boq_item_id' => $item->id, 'code' => $item->code, 'description' => 'RCC',
            'unit' => 'cum', 'rate' => '5000.00', 'prev_qty' => '0', 'current_qty' => '20', 'cumulative_qty' => '20',
            'prev_amount' => '0', 'current_amount' => '100000.00', 'cumulative_amount' => '100000.00',
        ]);
        RaBillDeduction::create(['organization_id' => $project->organization_id, 'ra_bill_id' => $bill->id, 'type' => 'retention', 'label' => 'Retention', 'pct' => '5.00', 'amount' => '5000.00', 'sort' => 1]);

        return $bill;
    }

    // ---- keys ----

    public function test_a_new_key_is_shown_once_and_only_its_hash_is_kept(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');

        $page = $this->actingAs($owner)->post('/developer/api-keys', ['name' => 'Tableau', 'scopes' => ['projects:read', 'boq:read']])->assertOk();
        $key = CompanyApiKey::withoutGlobalScopes()->firstOrFail();

        preg_match('/n360_[A-Za-z0-9]{40}/', $page->getContent(), $found);
        $secret = $found[0] ?? $this->fail('The new key should be on the page.');

        $this->assertSame(hash('sha256', $secret), $key->key_hash);
        $this->assertSame(substr($secret, 0, 12), $key->prefix);
        $this->assertSame(['projects:read', 'boq:read'], $key->scopes);
        $this->assertSame(60, $key->rate_limit_per_minute);
        $this->assertDatabaseMissing('company_api_keys', ['key_hash' => $secret]);

        // Gone from the next page view.
        $this->actingAs($owner)->get('/developer/api-keys')->assertOk()->assertSee($key->prefix)->assertDontSee($secret)->assertSee('Tableau');

        // The audit trail has the key's name but never its hash or secret.
        $entry = AuditLog::withoutGlobalScopes()->where('action', 'api_key.created')->firstOrFail();
        $this->assertStringNotContainsString($key->key_hash, json_encode($entry->new_values));
        $this->assertStringNotContainsString($secret, json_encode($entry->new_values));
        $this->assertSame('Tableau', $entry->new_values['name']);
    }

    public function test_the_key_form_is_validated(): void
    {
        $owner = $this->member($this->tenant(), 'owner');

        $this->actingAs($owner)->post('/developer/api-keys', ['name' => 'No scopes'])->assertSessionHasErrors('scopes');
        $this->actingAs($owner)->post('/developer/api-keys', ['name' => 'Odd scope', 'scopes' => ['admin:write']])->assertSessionHasErrors('scopes.0');
        $this->actingAs($owner)->post('/developer/api-keys', ['name' => 'Too fast', 'scopes' => ['projects:read'], 'rate_limit_per_minute' => 100000])->assertSessionHasErrors('rate_limit_per_minute');
        $this->actingAs($owner)->post('/developer/api-keys', ['name' => 'Past', 'scopes' => ['projects:read'], 'expires_on' => '2020-01-01'])->assertSessionHasErrors('expires_on');
        $this->assertSame(0, CompanyApiKey::withoutGlobalScopes()->count());
    }

    public function test_keys_need_the_plan_and_an_owner_or_admin(): void
    {
        $business = $this->tenant('Big Co', 'business');
        $contractor = $this->tenant('Small Co', 'contractor');

        $this->actingAs($this->member($business, 'owner'))->get('/developer/api-keys')->assertOk();
        $this->actingAs($this->member($business, 'owner'))->get('/developer/api-docs')->assertOk();
        $this->actingAs($this->member($business, 'admin'))->get('/developer/api-keys')->assertOk();
        $this->actingAs($this->member($business, 'pm'))->get('/developer/api-keys')->assertForbidden();
        $this->actingAs($this->member($business, 'accounts'))->post('/developer/api-keys', ['name' => 'x', 'scopes' => ['projects:read']])->assertForbidden();
        $this->actingAs($this->member($contractor, 'owner'))->get('/developer/api-keys')->assertForbidden();
        $this->actingAs($this->member($contractor, 'owner'))->get('/developer/api-docs')->assertForbidden();
        $this->actingAs($this->member($contractor, 'owner'))->post('/developer/api-keys', ['name' => 'x', 'scopes' => ['projects:read']])->assertForbidden();

        $this->actingAs($this->member($business, 'owner'))->get('/dashboard')->assertSee('API keys')->assertSee('API documentation');
        $this->actingAs($this->member($contractor, 'owner'))->get('/dashboard')->assertDontSee('API keys');
    }

    public function test_a_key_can_be_revoked_only_by_its_own_company(): void
    {
        $mine = $this->company('Mine');
        $theirs = $this->company('Theirs');

        $this->actingAs($mine->owner)->post("/developer/api-keys/{$theirs->key->id}/revoke")->assertNotFound();
        $this->assertNull($theirs->key->refresh()->revoked_at);

        $this->actingAs($mine->owner)->post("/developer/api-keys/{$mine->key->id}/revoke")->assertSessionHasNoErrors();
        $this->assertNotNull($mine->key->refresh()->revoked_at);
        $this->api('/projects', $mine->secret)->assertUnauthorized();
    }

    public function test_the_number_of_active_keys_is_capped(): void
    {
        $company = $this->company();
        $service = app(ApiKeyService::class);

        for ($i = 1; $i < ApiKeyService::MAX_ACTIVE_KEYS; $i++) {
            $service->create($company->owner, ['name' => "Key {$i}", 'scopes' => ['projects:read']]);
        }

        $this->actingAs($company->owner)->post('/developer/api-keys', ['name' => 'One too many', 'scopes' => ['projects:read']])->assertSessionHasErrors('name');
    }

    // ---- signing in ----

    public function test_the_api_needs_a_valid_key(): void
    {
        $company = $this->company();

        $this->api('/projects', null)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_api_key');
        $this->api('/projects', 'n360_'.str_repeat('x', 40))->assertUnauthorized()->assertJsonPath('error.code', 'invalid_api_key');
        $this->api('/projects', 'not-even-the-right-shape')->assertUnauthorized();
        $this->api('/projects', $company->secret)->assertOk();
        $this->getJson(self::BASE.'/projects', ['X-Api-Key' => $company->secret])->assertOk();
    }

    public function test_revoked_and_expired_keys_stop_working(): void
    {
        $company = $this->company();
        $expiring = app(ApiKeyService::class)->create($company->owner, ['name' => 'Short', 'scopes' => ['projects:read'], 'expires_on' => now()->addDays(2)->toDateString()]);

        $this->api('/projects', $expiring['secret'])->assertOk();
        $this->travel(3)->days();
        $this->api('/projects', $expiring['secret'])->assertUnauthorized();
        $this->travel(-3)->days();

        app(ApiKeyService::class)->revoke($company->key);
        $this->api('/projects', $company->secret)->assertUnauthorized();
    }

    public function test_a_user_login_token_is_not_an_api_key_and_a_key_is_not_a_login(): void
    {
        $company = $this->company();
        $token = $company->owner->createToken('phone')->plainTextToken;

        $this->api('/projects', $token)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_api_key');
        $this->getJson('/api/v1/projects', ['Authorization' => 'Bearer '.$company->secret])->assertUnauthorized();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$company->secret])->assertUnauthorized();
    }

    public function test_a_key_only_reaches_what_its_scopes_allow(): void
    {
        $company = $this->company();
        $narrow = app(ApiKeyService::class)->create($company->owner, ['name' => 'Projects only', 'scopes' => ['projects:read']])['secret'];
        $dpr = $this->makeDpr($company->project);
        $bill = $this->makeBill($company->project);

        $this->api('/projects', $narrow)->assertOk();
        $this->api("/projects/{$company->project->id}", $narrow)->assertOk();
        foreach (["/projects/{$company->project->id}/boq", "/projects/{$company->project->id}/dprs", "/dprs/{$dpr->id}", '/bills', "/bills/{$bill->id}"] as $path) {
            $this->api($path, $narrow)->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
        }
    }

    public function test_a_company_that_drops_below_the_plan_loses_api_access(): void
    {
        $company = $this->company();
        $this->api('/projects', $company->secret)->assertOk();

        Organization::whereKey($company->organization->id)->update(['plan' => 'contractor']);
        $this->api('/projects', $company->secret)->assertForbidden()->assertJsonPath('error.code', 'plan_not_included');

        // The keys stay, so the API works again after an upgrade.
        Organization::whereKey($company->organization->id)->update(['plan' => 'enterprise']);
        $this->api('/projects', $company->secret)->assertOk();
    }

    public function test_the_platform_switch_for_api_access_also_turns_the_public_api_off(): void
    {
        $company = $this->company();
        config(['buildos.plans.business.features' => array_values(array_diff(config('buildos.plans.business.features'), ['api']))]);

        $this->api('/projects', $company->secret)->assertForbidden()->assertJsonPath('error.code', 'plan_not_included');
    }

    // ---- limits ----

    public function test_each_key_has_its_own_rate_limit_with_headers(): void
    {
        $company = $this->company();
        $slow = app(ApiKeyService::class)->create($company->owner, ['name' => 'Slow', 'scopes' => ['projects:read'], 'rate_limit_per_minute' => 10]);

        $first = $this->api('/projects', $slow['secret'])->assertOk();
        $this->assertSame('10', $first->headers->get('X-RateLimit-Limit'));
        $this->assertSame('9', $first->headers->get('X-RateLimit-Remaining'));

        for ($i = 0; $i < 9; $i++) {
            $this->api('/projects', $slow['secret'])->assertOk();
        }

        $blocked = $this->api('/projects', $slow['secret'])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));

        // Another key is not affected.
        $this->api('/projects', $company->secret)->assertOk();
    }

    public function test_the_limit_resets_after_a_minute(): void
    {
        $company = $this->company();
        $slow = app(ApiKeyService::class)->create($company->owner, ['name' => 'Slow', 'scopes' => ['projects:read'], 'rate_limit_per_minute' => 10]);

        for ($i = 0; $i < 10; $i++) {
            $this->api('/projects', $slow['secret'])->assertOk();
        }
        $this->api('/projects', $slow['secret'])->assertStatus(429);

        $this->travel(61)->seconds();
        $this->api('/projects', $slow['secret'])->assertOk();
    }

    public function test_guessing_keys_is_throttled(): void
    {
        $company = $this->company();

        for ($i = 0; $i < 30; $i++) {
            $this->api('/projects', 'n360_'.str_repeat('a', 39).chr(97 + $i % 26))->assertUnauthorized();
        }

        $this->api('/projects', 'n360_'.str_repeat('b', 40))->assertStatus(429);
        $this->api('/projects', $company->secret)->assertStatus(429);
    }

    public function test_use_is_recorded_without_a_write_on_every_call(): void
    {
        $company = $this->company();
        $this->assertNull($company->key->last_used_at);

        $this->api('/projects', $company->secret)->assertOk();

        $key = $company->key->refresh();
        $this->assertNotNull($key->last_used_at);
        $this->assertSame('127.0.0.1', $key->last_used_ip);
    }

    // ---- data ----

    public function test_projects_are_listed_and_shown(): void
    {
        $company = $this->company();
        $this->makeProject($company->organization, ['name' => 'Old Road', 'status' => 'closed']);

        $list = $this->api('/projects', $company->secret)->assertOk();
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertSame(['Acme Infra Tower', 'Old Road'], collect($list->json('data'))->pluck('name')->all());

        $this->api('/projects', $company->secret, ['status' => 'closed'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Old Road');
        $this->api('/projects', $company->secret, ['status' => 'nonsense'])->assertUnprocessable();

        $this->api("/projects/{$company->project->id}", $company->secret)->assertOk()
            ->assertJsonPath('data.id', $company->project->id)
            ->assertJsonPath('data.contract_value', '10000000.00')
            ->assertJsonPath('data.status', 'active');
        $this->api('/projects/999999', $company->secret)->assertNotFound();
    }

    public function test_boq_carries_exact_decimal_text_and_the_ledger(): void
    {
        $company = $this->company();
        $this->makeDpr($company->project);

        $row = $this->api("/projects/{$company->project->id}/boq", $company->secret)->assertOk()->json('data.0');

        $this->assertSame('100.000', $row['qty']);
        $this->assertSame('5000.00', $row['rate']);
        $this->assertSame('500000.00', $row['amount']);
        $this->assertSame('12.500', $row['executed_qty']);
        $this->assertSame('0.000', $row['approved_qty']);
        $this->assertSame('100.000', $row['balance_qty']);
        $this->assertIsString($row['qty']);
    }

    public function test_boq_does_not_list_deleted_lines(): void
    {
        $company = $this->company();
        $gone = $this->makeBoqItem($company->project);
        $gone->delete();

        $this->api("/projects/{$company->project->id}/boq", $company->secret)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_daily_reports_are_filtered_and_shown_in_full(): void
    {
        $company = $this->company();
        $approved = $this->makeDpr($company->project, '2026-11-02', 'APPROVED');
        $this->makeDpr($company->project, '2026-11-10', 'DRAFT');

        $list = $this->api("/projects/{$company->project->id}/dprs", $company->secret)->assertOk();
        $this->assertSame(['2026-11-10', '2026-11-02'], collect($list->json('data'))->pluck('report_date')->all());
        $this->assertSame(20, $list->json('data.1.manpower_total'));

        $this->api("/projects/{$company->project->id}/dprs", $company->secret, ['status' => 'APPROVED'])->assertJsonCount(1, 'data');
        $this->api("/projects/{$company->project->id}/dprs", $company->secret, ['from' => '2026-11-05'])->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'DRAFT');
        $this->api("/projects/{$company->project->id}/dprs", $company->secret, ['status' => 'WHATEVER'])->assertUnprocessable();

        $detail = $this->api("/dprs/{$approved->id}", $company->secret)->assertOk();
        $detail->assertJsonPath('data.weather', 'Clear')->assertJsonPath('data.manpower.0.trade', 'Mason')->assertJsonPath('data.work_items.0.qty', '12.500');
        $this->assertSame($company->item->code, $detail->json('data.work_items.0.code'));
    }

    public function test_bills_are_listed_filtered_and_shown_with_items_and_deductions(): void
    {
        $company = $this->company();
        $bill = $this->makeBill($company->project);

        $this->api('/bills', $company->secret)->assertOk()
            ->assertJsonPath('data.0.number', 'RA-1')->assertJsonPath('data.0.net_payable', '112100.00')->assertJsonPath('data.0.project', 'Acme Infra Tower');
        $this->api('/bills', $company->secret, ['status' => 'PAID'])->assertJsonCount(0, 'data');
        $this->api('/bills', $company->secret, ['project_id' => $company->project->id, 'type' => 'client'])->assertJsonCount(1, 'data');
        $this->api('/bills', $company->secret, ['type' => 'weird'])->assertUnprocessable();

        $this->api("/bills/{$bill->id}", $company->secret)->assertOk()
            ->assertJsonPath('data.gross_amount', '100000.00')
            ->assertJsonPath('data.items.0.current_qty', '20.000')
            ->assertJsonPath('data.items.0.current_amount', '100000.00')
            ->assertJsonPath('data.deductions.0.label', 'Retention')
            ->assertJsonPath('data.deductions.0.amount', '5000.00');
    }

    public function test_lists_are_paged_and_capped(): void
    {
        $company = $this->company();
        foreach (range(1, 5) as $i) {
            $this->makeProject($company->organization, ['name' => "Extra {$i}"]);
        }

        $page = $this->api('/projects', $company->secret, ['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertSame(2, $page->json('meta.per_page'));
        $this->assertSame(2, $page->json('meta.page'));
        $this->assertSame(6, $page->json('meta.total'));
        $this->assertSame(3, $page->json('meta.last_page'));
        $this->assertCount(2, $page->json('data'));

        $this->assertSame(100, $this->api('/projects', $company->secret, ['per_page' => 5000])->json('meta.per_page'));
        $this->assertSame(1, $this->api('/projects', $company->secret, ['per_page' => 0])->json('meta.per_page'));
    }

    // ---- isolation ----

    public function test_a_key_never_reaches_another_companys_data(): void
    {
        $mine = $this->company('Mine');
        $theirs = $this->company('Theirs');
        $dpr = $this->makeDpr($theirs->project);
        $bill = $this->makeBill($theirs->project, 'THEIRS-1');
        $this->makeDpr($mine->project);

        // Lists contain only my rows.
        $this->assertSame(['Mine Tower'], collect($this->api('/projects', $mine->secret)->json('data'))->pluck('name')->all());
        $this->api('/bills', $mine->secret)->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString('Theirs', $this->api('/projects', $mine->secret)->getContent());

        // Their ids are simply not found.
        foreach ([
            "/projects/{$theirs->project->id}", "/projects/{$theirs->project->id}/boq", "/projects/{$theirs->project->id}/dprs",
            "/dprs/{$dpr->id}", "/bills/{$bill->id}",
        ] as $path) {
            $this->api($path, $mine->secret)->assertNotFound();
        }

        // A filter cannot be used to reach across.
        $this->api('/bills', $mine->secret, ['project_id' => $theirs->project->id])->assertJsonCount(0, 'data');

        // And the other way round.
        $this->api("/projects/{$mine->project->id}", $theirs->secret)->assertNotFound();
    }

    public function test_a_signed_in_browser_session_does_not_change_which_company_a_key_reads(): void
    {
        $mine = $this->company('Mine');
        $theirs = $this->company('Theirs');

        // Someone from another company is signed in on this browser. The key still decides the company.
        $this->actingAs($theirs->owner);
        $names = collect($this->api('/projects', $mine->secret)->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Mine Tower'], $names);
    }

    // ---- docs ----

    public function test_the_documentation_describes_every_endpoint_and_scope(): void
    {
        $owner = $this->member($this->tenant('Docs Co', 'business'), 'owner');

        $page = $this->actingAs($owner)->get('/developer/api-docs')->assertOk();

        $page->assertSee(url('/api/v1/public'), false)->assertSee('Authorization: Bearer', false)->assertSee('X-RateLimit-Remaining')->assertSee('Retry-After');
        foreach (['/projects/{id}/boq', '/projects/{id}/dprs', '/dprs/{id}', '/bills/{id}', 'projects:read', 'boq:read', 'dpr:read', 'bills:read', 'insufficient_scope'] as $text) {
            $page->assertSee($text, false);
        }
    }

    public function test_every_documented_endpoint_exists(): void
    {
        $paths = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();

        foreach (['projects', 'projects/{projectId}', 'projects/{projectId}/boq', 'projects/{projectId}/dprs', 'dprs/{dprId}', 'bills', 'bills/{billId}'] as $path) {
            $this->assertContains('api/v1/public/'.$path, $paths);
        }
    }

    public function test_no_write_methods_are_exposed(): void
    {
        $company = $this->company();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->json($method, self::BASE.'/projects', [], ['Authorization' => 'Bearer '.$company->secret])->assertStatus(405);
        }
    }
}
