<?php

namespace Tests\Feature\Api;

use App\Models\Party;
use App\Models\Worker;
use Illuminate\Support\Str;

class WorkerApiTest extends ApiTestCase
{
    public function test_list_returns_active_workers_in_the_tenant_only(): void
    {
        $site = $this->site();
        $active = Worker::create(['organization_id' => $site->organization->id, 'name' => 'Dattu', 'trade' => 'Mason', 'daily_wage' => 950]);
        Worker::create(['organization_id' => $site->organization->id, 'name' => 'Gone', 'trade' => 'Mason', 'daily_wage' => 950, 'is_active' => false]);
        Worker::create(['organization_id' => $this->tenant('Other Co')->id, 'name' => 'Theirs', 'trade' => 'Mason', 'daily_wage' => 950]);

        $response = $this->getJson(self::API.'/workers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);

        $this->assertSame(['id', 'name', 'phone', 'trade', 'type', 'is_active', 'updated_at'], array_keys($response->json('data.0')));
    }

    public function test_create_is_idempotent_and_defaults_type_and_wage(): void
    {
        $this->site();
        $payload = ['name' => 'Dattu', 'trade' => 'Mason', 'client_uuid' => (string) Str::uuid()];

        $this->postJson(self::API.'/workers', $payload)->assertCreated()
            ->assertJsonPath('data.type', 'own')
            ->assertJsonPath('data.is_active', true);
        $this->postJson(self::API.'/workers', $payload)->assertOk();

        $this->assertSame('0.00', Worker::sole()->daily_wage);
    }

    public function test_a_contractor_from_another_company_is_rejected(): void
    {
        $this->site();
        $theirs = Party::create(['organization_id' => $this->tenant('Other Co')->id, 'type' => 'subcontractor', 'name' => 'Theirs']);

        $this->postJson(self::API.'/workers', ['name' => 'Dattu', 'trade' => 'Mason', 'type' => 'contractor', 'contractor_id' => $theirs->id])
            ->assertUnprocessable()->assertJsonValidationErrors('contractor_id');
    }

    public function test_routes_need_their_permission(): void
    {
        $site = $this->site();

        $this->signIn($this->member($site->organization, 'store'));
        $this->getJson(self::API.'/workers')->assertForbidden();

        $this->signIn($this->member($site->organization, 'qs'));
        $this->postJson(self::API.'/workers', ['name' => 'Dattu', 'trade' => 'Mason'])->assertForbidden();
    }

    public function test_routes_respect_the_plan_feature(): void
    {
        $this->site('owner');
        config(['buildos.plans.business.features' => ['site']]);

        $this->getJson(self::API.'/workers')->assertForbidden();
    }

    public function test_a_repeated_client_uuid_from_another_company_creates_their_own_row(): void
    {
        $this->site();
        $payload = ['name' => 'Dattu', 'trade' => 'Mason', 'client_uuid' => (string) Str::uuid()];
        $this->postJson(self::API.'/workers', $payload)->assertCreated();

        $this->signInElsewhere();
        $this->postJson(self::API.'/workers', $payload)->assertCreated();

        $this->assertSame(2, Worker::withoutGlobalScopes()->count());
    }
}
