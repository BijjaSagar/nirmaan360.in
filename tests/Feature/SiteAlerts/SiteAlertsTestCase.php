<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

/** A company on the Business plan, an owner, and the engineer's example project: 180 planned days from 1 January 2026. */
abstract class SiteAlertsTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected const API = '/api/v1';

    protected Organization $organization;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();

        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
        $this->project = $this->makeProject($this->organization, ['start_date' => '2026-01-01', 'planned_days' => 180]);
    }

    protected function item(string $qty = '100.000', string $rate = '500.00', array $overrides = [], ?Project $project = null): BoqItem
    {
        $project ??= $this->project;

        return BoqItem::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => 'I'.Str::upper(Str::random(4)),
            'description' => 'Flooring with vitrified tiles',
            'unit' => 'sqm',
            'qty' => $qty,
            'rate' => $rate,
            ...$overrides,
        ]);
    }

    protected function done(BoqItem $item, string $qty, array $extra = []): ProgressEntry
    {
        return ProgressEntry::create([
            'organization_id' => $item->organization_id,
            'project_id' => $item->project_id,
            'boq_item_id' => $item->id,
            'work_date' => '2026-03-30',
            'qty_done' => $qty,
            ...$extra,
        ]);
    }

    /** @return array{new:int, open:int, resolved:int} */
    protected function scan(string $today, ?Project $project = null): array
    {
        return app(AlertEngine::class)->scanProject(($project ?? $this->project)->fresh(), Carbon::parse($today));
    }

    /** Limits a person to the given projects; none means they see no project at all. */
    protected function limitTo(User $user, Project ...$projects): void
    {
        $user->forceFill(['limited_to_projects' => true])->save();

        foreach ($projects as $project) {
            DB::table('user_project_access')->insert([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
