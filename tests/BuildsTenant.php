<?php

namespace Tests;

use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use Illuminate\Support\Str;

trait BuildsTenant
{
    protected function tenant(string $name = 'Acme Infra', string $plan = 'business'): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'plan' => $plan,
            'plan_status' => 'active',
        ]);
        $organization->seedDefaults();

        return $organization;
    }

    protected function member(Organization $organization, string $role = 'engineer'): User
    {
        return User::factory()->create(['organization_id' => $organization->id, 'role' => $role, 'is_active' => true]);
    }

    protected function makeProject(Organization $organization, array $overrides = []): Project
    {
        return Project::create([
            'organization_id' => $organization->id,
            'code' => 'P'.Str::upper(Str::random(4)),
            'name' => 'Riverside Tower',
            'contract_value' => '10000000.00',
            ...$overrides,
        ]);
    }

    protected function makeBoqItem(Project $project, string $qty = '100.000', string $rate = '5000.00'): BoqItem
    {
        return BoqItem::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => 'I'.Str::upper(Str::random(4)),
            'description' => 'RCC M25 in slab',
            'unit' => 'cum',
            'qty' => $qty,
            'rate' => $rate,
        ]);
    }

    protected function draftEntry(Project $project, BoqItem $item, User $by, array $overrides = []): MeasurementEntry
    {
        return app(MeasurementService::class)->create($project, [
            'boq_item_id' => $item->id,
            'measured_on' => '2026-11-01',
            'nos' => '2',
            'length' => '10',
            'breadth' => '5',
            'depth' => '0.5',
            ...$overrides,
        ], $by);
    }

    /** Submit an entry and take it through every approval level. */
    protected function approveEntry(MeasurementEntry $entry, User $submitter, User $owner): MeasurementEntry
    {
        $approval = app(MeasurementService::class)->submit($entry, $submitter);

        while ($approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $owner, 'approve');
        }

        return $entry->refresh();
    }
}
