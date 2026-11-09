<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\Party;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Models\Worker;
use App\Services\ApprovalService;
use App\Services\SampleDataService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\BuildsTenant;
use Tests\TestCase;

class SampleDataTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private function company(string $name = 'Real Co', string $plan = 'contractor'): array
    {
        $organization = $this->tenant($name, $plan);

        return [$organization, $this->member($organization, 'owner')];
    }

    private function rowsIn(string $model, int $organizationId): int
    {
        return $model::withoutGlobalScopes()->where('organization_id', $organizationId)->count();
    }

    public function test_it_only_runs_for_an_owner_that_exists(): void
    {
        [$organization] = $this->company();
        $engineer = $this->member($organization, 'engineer');

        $this->artisan('sample:fill', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('sample:fill', ['email' => $engineer->email])->assertFailed();

        $this->assertSame(0, $this->rowsIn(Project::class, $organization->id));
    }

    public function test_it_fills_the_company_with_worked_records(): void
    {
        [$organization, $owner] = $this->company();

        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();

        $this->assertSame(2, $this->rowsIn(Project::class, $organization->id));
        $tower = Project::withoutGlobalScopes()->where('organization_id', $organization->id)->where('code', SampleDataService::TOWER_CODE)->firstOrFail();
        $this->assertStringContainsString('(Sample)', $tower->name);

        $this->assertSame(5, BoqItem::withoutGlobalScopes()->where('project_id', $tower->id)->count());
        $this->assertSame(9, $this->rowsIn(Worker::class, $organization->id));
        $this->assertSame(3, $this->rowsIn(Issue::class, $organization->id));
        $this->assertSame(1, RaBill::withoutGlobalScopes()->where('project_id', $tower->id)->count());
        $this->assertSame(1, MeasurementEntry::withoutGlobalScopes()->where('project_id', $tower->id)->where('status', 'APPROVED')->count());
        $this->assertSame(3, MeasurementEntry::withoutGlobalScopes()->where('project_id', $tower->id)->count());

        // The owner gets a wallet to use and is left with today's report to try.
        $this->assertSame($owner->id, PettyCashWallet::withoutGlobalScopes()->where('project_id', $tower->id)->value('user_id'));
        $this->assertSame(0, Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->whereDate('report_date', now()->toDateString())->count());
    }

    public function test_owner_has_work_waiting_in_the_inbox(): void
    {
        [$organization, $owner] = $this->company();
        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();
        $this->actingAs($owner);

        $waiting = Approval::where('status', 'IN_REVIEW')->get()->filter(fn ($approval) => app(ApprovalService::class)->canAct($approval, $owner));

        $this->assertGreaterThanOrEqual(2, $waiting->count());
        $this->get('/projects')->assertOk()->assertSee('Kalyani Tower B (Sample)');
    }

    public function test_sample_team_cannot_sign_in(): void
    {
        [$organization, $owner] = $this->company();
        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();

        $team = User::where('organization_id', $organization->id)->whereKeyNot($owner->id)->get();

        $this->assertCount(4, $team);

        foreach ($team as $person) {
            $this->assertFalse($person->is_active);
            $this->assertFalse(Hash::check('password', $person->password));
            $this->assertStringEndsWith('@example.invalid', $person->email);
            $this->assertFalse($person->isPlatformAdmin());
        }
    }

    public function test_running_it_twice_adds_nothing_more(): void
    {
        [$organization, $owner] = $this->company();

        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();
        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();

        $this->assertSame(2, $this->rowsIn(Project::class, $organization->id));
        $this->assertSame(9, $this->rowsIn(Worker::class, $organization->id));
        $this->assertSame(5, User::where('organization_id', $organization->id)->count());
    }

    public function test_other_companies_are_untouched(): void
    {
        [, $owner] = $this->company();
        [$other] = $this->company('Other Co');

        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();

        foreach ([Project::class, Party::class, Worker::class, Issue::class] as $model) {
            $this->assertSame(0, $this->rowsIn($model, $other->id));
        }
    }

    public function test_a_plan_without_room_is_left_exactly_as_it_was(): void
    {
        [$organization, $owner] = $this->company('Small Co', 'free');

        $this->artisan('sample:fill', ['email' => $owner->email])->assertFailed();

        $this->assertSame(0, $this->rowsIn(Project::class, $organization->id));
        $this->assertSame(0, $this->rowsIn(Party::class, $organization->id));
        $this->assertSame(1, User::where('organization_id', $organization->id)->count());
    }

    public function test_local_demo_company_still_builds(): void
    {
        $this->seed(DemoSeeder::class);

        $project = Project::withoutGlobalScopes()->where('code', 'KT-B')->firstOrFail();

        $this->assertSame('Kalyani Tower B', $project->name);
        $this->assertSame(1, RaBill::withoutGlobalScopes()->where('project_id', $project->id)->count());
        $this->assertSame(1, Dpr::withoutGlobalScopes()->where('project_id', $project->id)->whereDate('report_date', now()->toDateString())->count());
        $this->assertSame(User::where('email', 'engineer@demo.test')->value('id'), PettyCashWallet::withoutGlobalScopes()->where('project_id', $project->id)->value('user_id'));
    }
}
