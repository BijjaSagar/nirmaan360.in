<?php

namespace Tests\Feature\RateHelper;

use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MigrationTest extends RateHelperTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2027_01_15_100001_create_rate_helper_tables.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->startRun($this->owner, $line);
        $boqLines = DB::table('boq_items')->count();

        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('rate_suggestion_lines'));
        $this->assertFalse(Schema::hasTable('rate_suggestion_runs'));
        // Nothing else was touched.
        $this->assertSame($boqLines, DB::table('boq_items')->count());
        $this->assertTrue(Schema::hasTable('rate_library_items'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('rate_suggestion_runs', ['organization_id', 'project_id', 'user_id', 'status', 'client_uuid']));
        $this->assertTrue(Schema::hasColumns('rate_suggestion_lines', [
            'organization_id', 'project_id', 'run_id', 'boq_item_id', 'candidates',
            'accepted_ref', 'accepted_source', 'accepted_rate', 'accepted_mode', 'accepted_by', 'accepted_at', 'skipped_by', 'skipped_at',
        ]));

        $run = $this->startRun($this->owner, $line);
        $this->assertSame(1, RateSuggestionLine::withoutGlobalScopes()->where('run_id', $run->id)->count());
    }

    public function test_a_client_uuid_is_kept_once_per_company_and_a_line_once_per_run(): void
    {
        $line = $this->line('RCC M25 in slab');
        $run = $this->startRun($this->owner, $line);

        // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
        try {
            DB::transaction(fn () => RateSuggestionRun::withoutGlobalScopes()->create([
                'organization_id' => $this->org->id, 'project_id' => $this->other->id, 'user_id' => $this->owner->id, 'client_uuid' => $run->client_uuid,
            ]));
            $this->fail('The same client_uuid was saved twice in one company.');
        } catch (UniqueConstraintViolationException) {
            // Kept once.
        }

        try {
            DB::transaction(fn () => RateSuggestionLine::withoutGlobalScopes()->create([
                'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'run_id' => $run->id, 'boq_item_id' => $line->id, 'candidates' => [],
            ]));
            $this->fail('The same BOQ line was saved twice in one run.');
        } catch (UniqueConstraintViolationException) {
            // Kept once.
        }

        // Another company may use the same client_uuid.
        [$rivalOrg, $rivalOwner, $rivalProject] = $this->rival();
        $this->assertNotNull(RateSuggestionRun::withoutGlobalScopes()->create([
            'organization_id' => $rivalOrg->id, 'project_id' => $rivalProject->id, 'user_id' => $rivalOwner->id, 'client_uuid' => $run->client_uuid,
        ])->id);
    }

    public function test_runs_and_lines_go_with_their_project_and_outlive_the_person_who_made_them(): void
    {
        $line = $this->line('RCC M25 in slab');
        $this->libraryRate('RCC M25 in slab', 'cum', '6200.00');
        $maker = $this->member($this->org, 'qs');
        $run = $this->startRun($maker, $line);
        $this->accept($maker, $run, $line, 'C1')->assertSessionHasNoErrors();

        // The person's row is removed outright: the run and the accepted rate stay, without a name.
        $this->actingAs($this->owner);
        DB::table('audit_logs')->where('user_id', $maker->id)->update(['user_id' => null]);
        DB::table('boq_revisions')->where('user_id', $maker->id)->update(['user_id' => null]);
        User::whereKey($maker->id)->delete();

        $this->assertNull($run->fresh()->user_id);
        $kept = $this->lineOf($run, $line);
        $this->assertNull($kept->accepted_by);
        $this->assertSame('6200.00', $kept->accepted_rate);

        // A project that is erased takes its runs and lines with it.
        $other = $this->line('RCC M25 in slab', 'cum', '0.00', $this->other);
        $second = $this->startRun($this->owner, $other);
        DB::table('projects')->where('id', $this->other->id)->delete();

        $this->assertNull(RateSuggestionRun::withoutGlobalScopes()->find($second->id));
        $this->assertSame(0, RateSuggestionLine::withoutGlobalScopes()->where('run_id', $second->id)->count());
        $this->assertNotNull(RateSuggestionRun::withoutGlobalScopes()->find($run->id));
        $this->assertTrue(Str::isUuid($run->client_uuid));
    }
}
