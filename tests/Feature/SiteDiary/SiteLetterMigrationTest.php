<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Hindrance;
use App\Models\SiteLetter;
use App\Models\SiteLetterHindrance;
use App\Services\Notify\TenantData;
use App\Services\SiteDiary\SiteLetters;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteLetterMigrationTest extends SiteLetterTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2027_01_16_100002_create_site_letter_tables.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again_without_touching_the_register(): void
    {
        $entry = $this->entry('2026-03-12', null);
        $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $this->assertTrue(Schema::hasTable('site_letters'));
        $this->assertTrue(Schema::hasTable('site_letter_hindrances'));

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('site_letters'));
        $this->assertFalse(Schema::hasTable('site_letter_hindrances'));

        // The register and everything else stay as they were.
        foreach (['hindrances', 'hindrance_sources', 'projects', 'ra_bills', 'contract_terms', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertSame(1, Hindrance::withoutGlobalScopes()->count());

        $this->migration()->up();
        $this->assertSame(0, SiteLetter::withoutGlobalScopes()->count());
        $this->assertSame(0, SiteLetterHindrance::withoutGlobalScopes()->count());

        foreach (['organization_id', 'project_id', 'type', 'language', 'status', 'inputs', 'facts', 'fact_fingerprint', 'slots', 'body_rendered', 'typed_numbers', 'number_ticks', 'addressed_to', 'subject', 'finalised_by', 'finalised_at', 'client_uuid', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('site_letters', $column), $column);
        }

        $this->assertSame(['id', 'organization_id', 'project_id', 'site_letter_id', 'hindrance_id'], Schema::getColumnListing('site_letter_hindrances'));

        $again = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $this->assertSame([$entry->id], $again->hindrances->pluck('id')->all());
    }

    public function test_a_request_id_is_kept_once_per_company_and_an_entry_is_cited_once_per_letter(): void
    {
        $entry = $this->entry('2026-03-12', null);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $copy = fn (array $with) => DB::table('site_letters')->insert([...Arr::except((array) DB::table('site_letters')->where('id', $letter->id)->first(), 'id'), ...$with]);

        // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
        try {
            DB::transaction(fn () => $copy([]));
            $this->fail('The same request id was saved twice in one company.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        // Another company may happen to use the same value, and rows with none never clash.
        [$rivalOrg, , $rivalProject] = $this->rival();
        $copy(['organization_id' => $rivalOrg->id, 'project_id' => $rivalProject->id]);
        $copy(['client_uuid' => null]);
        $copy(['client_uuid' => null]);
        $this->assertSame(4, SiteLetter::withoutGlobalScopes()->count());

        try {
            DB::transaction(fn () => SiteLetterHindrance::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'site_letter_id' => $letter->id, 'hindrance_id' => $entry->id]));
            $this->fail('The same entry was cited twice by one letter.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }
    }

    public function test_letters_go_with_their_project_and_outlive_the_person_who_finalised_them(): void
    {
        $entry = $this->entry('2026-03-12', null);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        app(SiteLetters::class)->finalise($letter, $this->ticks($letter), $this->pm);
        $otherProject = $this->makeProject($this->org, ['code' => 'SG2']);
        $other = $this->draft(self::DELAY, ['hindrance_ids' => [$this->entry('2026-03-12', null, [], null, $otherProject)->id]], $this->pm, $otherProject);

        // The person is removed from the table: the letter stands, only the name is gone.
        DB::table('users')->where('id', $this->pm->id)->delete();
        $letter->refresh();
        $this->assertNull($letter->finalised_by);
        $this->assertTrue($letter->isFinalised());
        $this->assertNotNull($letter->finalised_at);

        // Removing a cited entry's row takes the citation with it and leaves the letter and its text.
        DB::table('hindrances')->where('id', $entry->id)->delete();
        $this->assertSame(0, SiteLetterHindrance::withoutGlobalScopes()->where('site_letter_id', $letter->id)->count());
        $this->assertStringContainsString('Podium slab drawings awaited', $letter->refresh()->body_rendered);

        // Removing the project takes its letters and their citations, and nobody else's.
        DB::table('projects')->where('id', $this->project->id)->delete();
        $this->assertSame([$other->id], SiteLetter::withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame([$other->id], SiteLetterHindrance::withoutGlobalScopes()->pluck('site_letter_id')->all());
    }

    public function test_both_tables_belong_to_a_company_and_a_project_so_export_removal_and_project_limits_find_them(): void
    {
        $tables = app(TenantData::class)->tables();

        foreach (['site_letters', 'site_letter_hindrances'] as $table) {
            $this->assertArrayHasKey($table, $tables);
            $this->assertContains('project_id', $tables[$table]);
        }

        // A letter keeps no stored file, so there is nothing for a purge to remove from the disk.
        $this->assertSame([], app(TenantData::class)->fileColumns($tables['site_letters']));
    }
}
