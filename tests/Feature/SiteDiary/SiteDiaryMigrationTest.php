<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Services\Notify\TenantData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteDiaryMigrationTest extends SiteDiaryTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2027_01_16_100001_create_hindrance_tables.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->cite($this->hindrance(), $this->dpr('2026-03-02'));
        $this->assertTrue(Schema::hasTable('hindrances'));
        $this->assertTrue(Schema::hasTable('hindrance_sources'));

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('hindrances'));
        $this->assertFalse(Schema::hasTable('hindrance_sources'));

        // Nothing else was touched on the way down.
        foreach (['projects', 'dprs', 'sites', 'contract_terms', 'alerts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->migration()->up();
        $this->assertSame(0, Hindrance::withoutGlobalScopes()->withTrashed()->count());
        $this->assertSame(0, HindranceSource::withoutGlobalScopes()->count());

        foreach (['organization_id', 'project_id', 'site_id', 'category', 'description', 'started_on', 'ended_on', 'responsible', 'status', 'proposed_by', 'locked_at', 'supersedes_id', 'confirmed_by', 'confirmed_at', 'client_uuid', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('hindrances', $column), $column);
        }

        foreach (['organization_id', 'project_id', 'hindrance_id', 'dpr_id', 'report_date', 'excerpt', 'dpr_status', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('hindrance_sources', $column), $column);
        }

        $this->assertNotNull($this->cite($this->hindrance(), $this->dpr('2026-03-03'))->sources->sole()->id);
    }

    public function test_a_request_id_is_kept_once_per_company(): void
    {
        $uuid = '44444444-4444-4444-8444-444444444444';
        $this->hindrance(['client_uuid' => $uuid]);

        // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
        try {
            DB::transaction(fn () => $this->hindrance(['client_uuid' => $uuid, 'category' => 'weather']));
            $this->fail('The same request id was saved twice in one company.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        // Another company may happen to use the same value, and rows with none never clash.
        [, , $rivalProject] = $this->rival();
        $this->hindrance(['client_uuid' => $uuid], $rivalProject);
        $this->hindrance();
        $this->hindrance();

        $this->assertSame(4, Hindrance::withoutGlobalScopes()->count());
    }

    public function test_entries_go_with_their_project_and_outlive_the_people_reports_and_sites_they_name(): void
    {
        $site = $this->site();
        $report = $this->dpr('2026-03-02', 'Podium slab drawings not received');
        $entry = $this->cite($this->hindrance(['site_id' => $site->id, 'confirmed_by' => $this->pm->id]), $report);
        $otherProject = $this->makeProject($this->org, ['code' => 'SG2']);
        $other = $this->cite($this->hindrance([], $otherProject), $this->dpr('2026-03-02', 'Rain', [], $otherProject));

        // The person, the site and the report itself are removed from the tables.
        DB::table('users')->where('id', $this->pm->id)->delete();
        DB::table('dprs')->where('id', $report->id)->delete();
        DB::table('sites')->where('id', $site->id)->delete();

        $entry->refresh();
        $this->assertNull($entry->confirmed_by);
        $this->assertNull($entry->site_id);
        $this->assertTrue($entry->isConfirmed(), 'The entry stands; only the name is gone.');
        $source = HindranceSource::withoutGlobalScopes()->where('hindrance_id', $entry->id)->sole();
        $this->assertSame([$report->id, 'Podium slab drawings not received'], [$source->dpr_id, $source->excerpt]);
        $this->assertNull($source->dpr);

        // Removing an entry's row takes its sources with it, and removing the project takes both.
        DB::table('hindrances')->where('id', $entry->id)->delete();
        $this->assertSame(0, HindranceSource::withoutGlobalScopes()->where('hindrance_id', $entry->id)->count());

        $this->hindrance();
        DB::table('projects')->where('id', $this->project->id)->delete();

        $this->assertSame([$other->id], Hindrance::withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame([$other->id], HindranceSource::withoutGlobalScopes()->pluck('hindrance_id')->all());
    }

    public function test_both_tables_belong_to_a_company_so_its_export_and_its_removal_find_them(): void
    {
        $tables = app(TenantData::class)->tables();

        $this->assertArrayHasKey('hindrances', $tables);
        $this->assertArrayHasKey('hindrance_sources', $tables);
        $this->assertContains('project_id', $tables['hindrances']);
        $this->assertContains('project_id', $tables['hindrance_sources']);
    }
}
