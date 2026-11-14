<?php

namespace Tests\Feature\BoqScan;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/** The module's one table comes and goes with its migration. */
class BoqScanMigrationTest extends BoqScanTestCase
{
    private const MIGRATION = 'database/migrations/2027_01_11_100001_create_boq_scan_tables.php';

    public function test_the_table_is_rolled_back_and_made_again(): void
    {
        $this->assertTrue(Schema::hasTable('boq_scan_rows'));
        $this->assertTrue(Schema::hasColumns('boq_scan_rows', ['organization_id', 'project_id', 'document_read_id', 'qty_raw', 'rate_raw', 'amount_raw', 'qty', 'rate', 'amount', 'confirmed_mode', 'client_uuid']));

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        $this->assertFalse(Schema::hasTable('boq_scan_rows'));

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);
        $this->assertTrue(Schema::hasTable('boq_scan_rows'));
    }
}
