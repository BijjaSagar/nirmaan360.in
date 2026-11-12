<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The three quotation tables: they roll back and come up again, and they carry what every tenant table must. */
class MigrationTest extends TestCase
{
    use BuildsOwnerPack, RefreshDatabase;

    private const TABLES = ['client_quotations', 'client_quotation_items', 'client_payment_stages'];

    private function migration(): object
    {
        return require database_path('migrations/2027_01_17_100001_create_owner_pack_quotation_tables.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->house();
        $this->withStages($this->draft());

        $this->migration()->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} is dropped");
        }

        // What the module copied from is untouched.
        $this->assertSame(3, DB::table('boq_items')->where('project_id', $this->project->id)->count());

        $this->migration()->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count());
        }

        $this->assertSame(ClientQuotation::DRAFT, $this->withStages($this->draft())->status);
    }

    public function test_every_table_belongs_to_a_company_and_a_project(): void
    {
        foreach ([ClientQuotation::class, ClientQuotationItem::class, ClientPaymentStage::class] as $model) {
            $this->assertContains(BelongsToOrganization::class, class_uses_recursive($model));

            foreach (['organization_id', 'project_id'] as $column) {
                $this->assertTrue(Schema::hasColumn((new $model)->getTable(), $column), "{$model} has {$column}");
            }
        }

        // Money to two places and quantity to three, as everywhere else.
        $this->house();
        $quotation = $this->draft();
        $item = DB::table('client_quotation_items')->where('quotation_id', $quotation->id)->orderBy('sort')->first();
        $this->assertSame(['12.500', '4200.10', '52501.25'], [number_format((float) $item->qty, 3, '.', ''), number_format((float) $item->rate, 2, '.', ''), number_format((float) $item->amount, 2, '.', '')]);
    }

    public function test_a_number_has_one_row_per_revision_and_a_client_uuid_one_row_per_company(): void
    {
        $this->house();
        $quotation = $this->draft(['client_uuid' => '0b0f8f5e-7a3c-4a8e-9a55-3f1f6f0c2d11']);
        $copy = fn (array $changes) => DB::table('client_quotations')->insert([
            'organization_id' => $quotation->organization_id, 'project_id' => $quotation->project_id, 'number' => $quotation->number,
            'revision' => 1, 'status' => 'draft', 'client_name' => 'Copy', 'language' => 'en', ...$changes,
        ]);

        foreach ([[], ['revision' => 2, 'client_uuid' => '0b0f8f5e-7a3c-4a8e-9a55-3f1f6f0c2d11']] as $changes) {
            try {
                // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
                DB::transaction(fn () => $copy($changes));
                $this->fail('A second row was accepted.');
            } catch (UniqueConstraintViolationException) {
                // Refused by the table itself.
            }
        }

        $this->assertTrue($copy(['revision' => 2]));

        // Another company may hold the same number and the same key.
        $other = $this->tenant('Konkan Builders');
        $project = $this->makeProject($other);
        $this->assertTrue(DB::table('client_quotations')->insert([
            'organization_id' => $other->id, 'project_id' => $project->id, 'number' => $quotation->number, 'revision' => 1,
            'status' => 'draft', 'client_name' => 'Theirs', 'language' => 'en', 'client_uuid' => '0b0f8f5e-7a3c-4a8e-9a55-3f1f6f0c2d11',
        ]));
    }

    public function test_deleting_the_company_takes_its_quotations_with_it(): void
    {
        $this->house();
        $this->shared();
        $other = $this->tenant('Konkan Builders');

        DB::table('organizations')->where('id', $this->organization->id)->delete();

        foreach ([...self::TABLES, 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $this->organization->id)->count(), $table);
        }

        $this->assertNotNull(DB::table('organizations')->find($other->id));
    }
}
