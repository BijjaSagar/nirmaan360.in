<?php

namespace Tests\Feature\AutoBoq;

use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class AutoBoqTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected User $engineer;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->org = $this->tenant('Acme Infra', 'business');
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
        $this->qs = $this->member($this->org, 'qs');
        $this->engineer = $this->member($this->org, 'engineer');
        $this->project = $this->makeProject($this->org, ['code' => 'AB1', 'name' => 'Sargam Hotel']);
    }

    /**
     * An invented G+1 building, as the form posts it. Every expected quantity in these tests is
     * worked out by hand from these figures and the default assumptions.
     */
    protected function dimensions(array $overrides = []): array
    {
        return array_replace_recursive([
            'floors' => 2, 'floor_height' => '3', 'plinth_area' => '100', 'plinth_height' => '0.6', 'foundation_depth' => '1.5',
            'stair_area' => '6', 'chajja_length' => '10', 'chajja_width' => '0.6', 'chajja_thickness' => '0.1',
            'footings' => [
                ['label' => 'F1', 'length' => '1.5', 'breadth' => '1.5', 'depth' => '0.45', 'nos' => '4'],
                ['label' => 'F2', 'length' => '1.2', 'breadth' => '1', 'depth' => '0.4', 'nos' => '2'],
            ],
            'columns' => [
                ['label' => 'C1', 'length' => '0.3', 'breadth' => '0.45', 'height' => '3', 'nos' => '4', 'floors' => '2'],
                ['label' => 'C2', 'length' => '0.23', 'breadth' => '0.3', 'height' => '', 'nos' => '2', 'floors' => ''],
            ],
            'beams' => [
                ['label' => 'B1', 'length' => '40', 'width' => '0.23', 'depth' => '0.45', 'floors' => '2'],
                ['label' => 'Plinth', 'length' => '30', 'width' => '0.23', 'depth' => '0.3', 'floors' => '1'],
            ],
            'slabs' => [
                ['label' => 'Typical', 'area' => '100', 'openings' => '10', 'thickness' => '0.125', 'count' => '2'],
            ],
            'walls' => [
                ['label' => 'Ext', 'kind' => 'external', 'length' => '40', 'height' => '', 'thickness' => '0.23', 'openings' => '8', 'floors' => '2'],
                ['label' => 'Int', 'kind' => 'internal', 'length' => '25', 'height' => '3', 'thickness' => '0.115', 'openings' => '4', 'floors' => '2'],
            ],
        ], $overrides);
    }

    /** The hand-worked quantity of every computed line for dimensions(). */
    protected function expected(): array
    {
        return [
            'AB-EXC' => '35.100',      // F1 4 × 2.1 × 2.1 × 1.5 = 26.46; F2 2 × 1.8 × 1.6 × 1.5 = 8.64
            'AB-FILL' => '88.598',     // 35.1 − 1.492 − 5.01 = 28.598 backfill + 100 × 0.6 plinth
            'AB-PCC' => '11.492',      // F1 4 × 1.7 × 1.7 × 0.1 = 1.156; F2 2 × 1.4 × 1.2 × 0.1 = 0.336; floor 100 × 0.1
            'AB-RCC-FTG' => '5.010',   // 4 × 1.5 × 1.5 × 0.45 + 2 × 1.2 × 1 × 0.4
            'AB-RCC-COL' => '3.654',   // 4 × 0.3 × 0.45 × 3 × 2 = 3.24; 2 × 0.23 × 0.3 × 3 (floor height) × 1 = 0.414
            'AB-RCC-BM' => '10.350',   // 40 × 0.23 × 0.45 × 2 = 8.28; 30 × 0.23 × 0.3 = 2.07
            'AB-RCC-SLB' => '22.500',  // (100 − 10) × 0.125 × 2
            'AB-RCC-STR' => '3.600',   // 2 floors × 6 × 0.3
            'AB-RCC-CHJ' => '0.600',   // 10 × 0.6 × 0.1
            'AB-STL' => '5.055',       // 400.8 + 584.64 + 1552.5 + 2025 + 432 + 60 = 5054.94 kg
            'AB-CEN-FTG' => '14.320',  // 4 × 2 × 3 × 0.45 = 10.8; 2 × 2 × 2.2 × 0.4 = 3.52
            'AB-CEN-COL' => '42.360',  // 4 × 2 × 0.75 × 3 × 2 = 36; 2 × 2 × 0.53 × 3 = 6.36
            'AB-CEN-BM' => '115.300',  // 40 × 1.13 × 2 = 90.4; 30 × 0.83 = 24.9
            'AB-CEN-SLB' => '180.000', // 90 × 2
            'AB-CEN-STR' => '18.000',  // 2 × 6 × 1.5
            'AB-BRK' => '67.850',      // (120 − 8) × 0.23 × 2 = 51.52; (75 − 4) × 0.115 × 2 = 16.33
            'AB-PL-INT' => '508.000',  // 224 external inner face + 2 × 142 internal
            'AB-PL-EXT' => '224.000',  // 112 × 2 floors
            'AB-PL-CLG' => '180.000',
            'AB-FLR' => '161.500',     // 0.85 × (100 plinth + 90 slab under the first floor)
            'AB-PNT-INT' => '688.000', // 508 + 180
            'AB-PNT-EXT' => '224.000',
            'AB-WP' => '90.000',       // the top slab
        ];
    }

    /** Someone holding exactly these permissions. */
    protected function holding(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->org;
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = $this->member($organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    /** A second company with a project of its own, for tenant isolation checks. */
    protected function rival(): array
    {
        $org = $this->tenant('Rival Builders', 'business');
        $owner = $this->member($org, 'owner');
        $project = $this->makeProject($org, ['code' => 'RV1']);

        return [$org, $owner, $project];
    }
}
