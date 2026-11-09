<?php

namespace Tests\Feature\SiteProgress;

use App\Models\ProjectFloor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrationTest extends SiteProgressTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_12_22_100001_create_site_progress_tables.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $first = $this->floor('1st floor');
        $second = $this->floor('2nd floor');
        $this->floor('Passage', $first->id);
        $this->floor('Passage', $second->id);

        $this->migration()->down();

        foreach (['floor_work_plans', 'mep_stage_cells', 'mep_stage_events', 'progress_photos'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "$table is dropped");
        }

        $this->assertFalse(Schema::hasColumn('project_floors', 'parent_id'));
        $this->assertFalse(Schema::hasColumn('progress_entries', 'corrects_entry_id'));
        $this->assertFalse(Schema::hasColumn('progress_entries', 'resolves_entry_id'));

        // Names that were only unique under their parent are made unique again, so the old key can be rebuilt.
        $names = DB::table('project_floors')->orderBy('id')->pluck('name')->all();
        $this->assertCount(4, $names);
        $this->assertCount(4, array_unique($names));

        $this->expectException(UniqueConstraintViolationException::class);

        try {
            DB::table('project_floors')->insert(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => '1st floor', 'sort' => 9]);
        } finally {
            $this->migration()->up();
            $this->assertTrue(Schema::hasTable('mep_stage_cells'));
            $this->assertTrue(Schema::hasColumn('project_floors', 'parent_id'));
            $this->assertSame(2, ProjectFloor::where('name', 'like', 'Passage%')->count());
        }
    }
}
