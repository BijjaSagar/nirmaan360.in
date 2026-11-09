<?php

namespace Tests\Feature\Api;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected const API = '/api/v1';

    protected function signIn(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** A company with one member signed in and a project with one BOQ line. */
    protected function site(string $role = 'engineer'): object
    {
        $organization = $this->tenant();
        $user = $this->signIn($this->member($organization, $role));
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);

        return (object) compact('organization', 'user', 'project', 'item');
    }

    /** Sign in as a member of a second company. */
    protected function signInElsewhere(): User
    {
        return $this->signIn($this->member($this->tenant('Other Co'), 'owner'));
    }

    protected function measurementData(BoqItem $item, array $overrides = []): array
    {
        return ['boq_item_id' => $item->id, 'measured_on' => '2026-11-01', 'nos' => 2, 'length' => 10, 'breadth' => 5, 'depth' => 0.5, ...$overrides];
    }

    protected function dprData(array $overrides = []): array
    {
        return [
            'report_date' => '2026-11-01',
            'weather' => 'Clear',
            'summary' => 'Slab cast',
            'manpower' => [['trade' => 'Mason', 'count' => 12]],
            'machinery' => [['name' => 'Crane', 'hours' => 2]],
            'work' => [['description' => 'Formwork', 'qty' => 3.5]],
            ...$overrides,
        ];
    }

    protected function issueData(array $overrides = []): array
    {
        return ['title' => 'Honeycombing at C7', 'priority' => 'high', ...$overrides];
    }

    /** One outbox item, stamped 10:00 IST unless overridden. */
    protected function item(string $type, Project $project, array $data, array $overrides = []): array
    {
        return [
            'type' => $type,
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $project->id,
            'server_id' => null,
            'client_updated_at' => '2026-11-09T10:00:00+05:30',
            'data' => $data,
            ...$overrides,
        ];
    }

    protected function push(array ...$items): TestResponse
    {
        return $this->postJson(self::API.'/sync/push', ['items' => $items])->assertOk();
    }
}
