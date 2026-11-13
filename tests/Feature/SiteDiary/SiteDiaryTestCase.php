<?php

namespace Tests\Feature\SiteDiary;

use App\Models\ContractTerm;
use App\Models\CustomRole;
use App\Models\Dpr;
use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * An invented company with one project, on 20 March 2026. Every number of days, date and clause in
 * these tests is made up for the test and comes from no agreement and no law.
 */
abstract class SiteDiaryTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected const TODAY = '2026-03-20';

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $engineer;

    protected User $qs;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 11:00:00', 'Asia/Kolkata'));

        $this->org = $this->tenant('Shivganga Buildcon', 'business');
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
        $this->engineer = $this->member($this->org, 'engineer');
        $this->qs = $this->member($this->org, 'qs');
        $this->project = $this->makeProject($this->org, ['code' => 'SG1', 'name' => 'Lake View Residency']);
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

    /** Limits a person to the given projects. */
    protected function limitTo(User $user, Project ...$projects): User
    {
        app(TeamAccessService::class)->applyProjects($user, true, array_map(fn (Project $project) => $project->id, $projects));

        return $user->refresh();
    }

    protected function site(string $name = 'Wing B', ?Project $project = null): Site
    {
        $project ??= $this->project;

        return Site::withoutGlobalScopes()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name]);
    }

    /** A daily report with something written under hindrances, unless $hindrances says otherwise. */
    protected function dpr(string $date, ?string $hindrances = 'Podium slab drawings not received from the architect', array $attributes = [], ?Project $project = null): Dpr
    {
        $project ??= $this->project;

        return Dpr::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'report_date' => $date,
            'hindrances' => $hindrances,
            'status' => 'DRAFT',
            ...$attributes,
        ]);
    }

    /** An entry written straight to the table: confirmed by the owner unless $attributes says otherwise. */
    protected function hindrance(array $attributes = [], ?Project $project = null): Hindrance
    {
        $project ??= $this->project;

        return Hindrance::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'category' => 'drawings_or_decisions',
            'description' => 'Podium slab drawings awaited',
            'started_on' => '2026-03-02',
            'ended_on' => '2026-03-06',
            'status' => Hindrance::CONFIRMED,
            'proposed_by' => Hindrance::BY_PERSON,
            'confirmed_by' => $this->owner->id,
            'confirmed_at' => now(),
            ...$attributes,
        ]);
    }

    /** Ties reports to an entry, copying what each says now. */
    protected function cite(Hindrance $hindrance, Dpr ...$reports): Hindrance
    {
        foreach ($reports as $report) {
            HindranceSource::withoutGlobalScopes()->create([
                'organization_id' => $hindrance->organization_id,
                'project_id' => $hindrance->project_id,
                'hindrance_id' => $hindrance->id,
                'dpr_id' => $report->id,
                'report_date' => $report->report_date->toDateString(),
                'excerpt' => trim((string) $report->hindrances),
                'dpr_status' => $report->status,
            ]);
        }

        return $hindrance->unsetRelation('sources');
    }

    /** What the form sends to make an entry from these reports. */
    protected function form(array $reports, array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'category' => 'drawings_or_decisions',
            'description' => 'Podium slab drawings awaited from the architect',
            'dpr_ids' => array_map(fn ($report) => $report instanceof Dpr ? $report->id : $report, $reports),
            ...$overrides,
        ];
    }

    /** A confirmed notice period of the client contract. The number of days is invented. */
    protected function notice(array $attributes = [], ?Project $project = null): ContractTerm
    {
        $project ??= $this->project;

        return ContractTerm::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'kind' => 'notice',
            'code' => 'delay_notice',
            'label' => 'Notice of delay',
            'days' => 11,
            'counted_from' => 'hindrance_start',
            'effective_from' => '2026-01-01',
            'source_ref' => 'Sample agreement, part H',
            'confirmed_by' => $this->owner->id,
            'confirmed_at' => now(),
            ...$attributes,
        ]);
    }

    /** A confirmed stipulated completion date of the client contract. */
    protected function completionDate(string $date, array $attributes = []): ContractTerm
    {
        return $this->notice([
            'kind' => 'reference', 'code' => 'stipulated_completion', 'label' => 'Stipulated completion date',
            'days' => null, 'counted_from' => null, 'value_date' => $date, ...$attributes,
        ]);
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
