<?php

namespace Tests\Feature\SlipReading;

use App\Models\SlipRead;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SlipReadsMigrationTest extends SlipReadingTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2027_01_12_100001_create_slip_reads_table.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->storedReading($this->owner);
        $reads = DB::table('document_reads')->count();

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('slip_reads'));
        $this->assertSame($reads, DB::table('document_reads')->count(), 'Nothing else was touched.');

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumns('slip_reads', [
            'organization_id', 'project_id', 'document_read_id', 'kind', 'status', 'doc_kind', 'fields', 'lines', 'warnings', 'suggestions',
            'image_sha256', 'delivery_challan_id', 'vendor_bill_id', 'used_client_uuid', 'confirmed', 'rechecked_at', 'user_id', 'client_uuid',
        ]));
        $this->assertNotNull($this->storedReading($this->owner)->id);
    }

    public function test_a_client_uuid_is_kept_once_per_company_and_a_reading_goes_with_its_project(): void
    {
        $slip = $this->storedReading($this->owner);

        try {
            DB::transaction(fn () => SlipRead::withoutGlobalScopes()->create([
                'organization_id' => $this->organization->id, 'project_id' => $this->otherProject->id, 'document_read_id' => 1,
                'kind' => SlipRead::CHALLAN, 'user_id' => $this->owner->id, 'client_uuid' => $slip->client_uuid,
            ]));
            $this->fail('The same client_uuid was saved twice in one company.');
        } catch (UniqueConstraintViolationException) {
            // Kept once.
        }

        $rival = $this->tenant('Narmada Infra');
        $rivalProject = $this->makeProject($rival);
        $this->assertNotNull(SlipRead::withoutGlobalScopes()->create([
            'organization_id' => $rival->id, 'project_id' => $rivalProject->id, 'document_read_id' => 1,
            'kind' => SlipRead::CHALLAN, 'client_uuid' => $slip->client_uuid,
        ])->id);

        DB::table('projects')->where('id', $this->project->id)->delete();
        $this->assertNull(SlipRead::withoutGlobalScopes()->find($slip->id));
    }
}
