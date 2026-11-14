<?php

namespace Tests\Feature\SiteDiary;

use App\Models\HindranceSuggestion;
use App\Services\Notify\TenantData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HindranceSuggestionMigrationTest extends SiteDiaryTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2027_01_16_100003_create_hindrance_suggestions_table.php');
    }

    private function suggestion(array $attributes = []): HindranceSuggestion
    {
        return HindranceSuggestion::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'project_id' => $this->project->id,
            'user_id' => $this->pm->id,
            'dpr_ids' => [$this->dpr('2026-03-02')->id],
            'status' => HindranceSuggestion::WAITING,
            ...$attributes,
        ]);
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->suggestion();

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('hindrance_suggestions'));

        // The register and the letters are not touched on the way down.
        foreach (['hindrances', 'hindrance_sources', 'site_letters', 'ai_text_calls', 'projects', 'dprs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->migration()->up();
        $this->assertSame(0, HindranceSuggestion::withoutGlobalScopes()->count());

        foreach (['organization_id', 'project_id', 'user_id', 'dpr_ids', 'from_date', 'to_date', 'status', 'proposed', 'left_out', 'fail_reason', 'finished_at', 'client_uuid', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('hindrance_suggestions', $column), $column);
        }

        $row = $this->suggestion()->refresh();
        $this->assertSame([0, 0, null], [(int) $row->proposed, (int) $row->left_out, $row->fail_reason]);
    }

    public function test_a_request_id_is_kept_once_per_company_and_the_table_goes_with_the_company(): void
    {
        $uuid = '55555555-5555-4555-8555-555555555555';
        $this->suggestion(['client_uuid' => $uuid]);

        [$rival, , $rivalProject] = $this->rival();
        HindranceSuggestion::withoutGlobalScopes()->create(['organization_id' => $rival->id, 'project_id' => $rivalProject->id, 'dpr_ids' => [], 'status' => HindranceSuggestion::DONE, 'client_uuid' => $uuid]);

        try {
            // In a transaction of its own, so PostgreSQL carries on after the refused insert.
            DB::transaction(fn () => $this->suggestion(['client_uuid' => $uuid]));
            $this->fail('A request id was kept twice in one company.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // A company's export and erasure find the table by its organization_id.
        $this->assertArrayHasKey('hindrance_suggestions', app(TenantData::class)->tables());
    }
}
