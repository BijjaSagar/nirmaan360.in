<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\OwnerUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The quotation tables and the weekly-update table: they roll back and come up again, and they carry what every tenant table must. */
class MigrationTest extends TestCase
{
    use BuildsOwnerUpdates, RefreshDatabase;

    private const TABLES = ['client_quotations', 'client_quotation_items', 'client_payment_stages'];

    private function migration(): object
    {
        return require database_path('migrations/2027_01_17_100001_create_owner_pack_quotation_tables.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->house();
        $this->withStages($this->draft());

        // Rolled back in the order a rollback runs: the weekly updates point at a quotation, so their table goes first.
        $this->updatesMigration()->down();
        $this->migration()->down();

        foreach ([...self::TABLES, 'owner_updates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} is dropped");
        }

        // What the module copied from is untouched.
        $this->assertSame(3, DB::table('boq_items')->where('project_id', $this->project->id)->count());

        $this->migration()->up();
        $this->updatesMigration()->up();

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

    private function updatesMigration(): object
    {
        return require database_path('migrations/2027_01_17_100002_create_owner_updates_table.php');
    }

    public function test_the_weekly_update_table_can_be_rolled_back_and_run_again_without_touching_the_quotations(): void
    {
        $this->site();
        $this->week();
        $quotation = $this->quotationWithAStageReached();
        $this->approvedUpdate($this->draftUpdate(['show_money' => true, 'quotation_id' => $quotation->id]));

        $this->updatesMigration()->down();

        $this->assertFalse(Schema::hasTable('owner_updates'));

        // What the update was read from is untouched: the quotation, its stages, the reports and their photos.
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertSame([1, 3, 6, 3], [DB::table('client_quotations')->count(), DB::table('client_payment_stages')->count(), DB::table('dprs')->count(), DB::table('dpr_photos')->count()]);

        $this->updatesMigration()->up();

        $this->assertTrue(Schema::hasTable('owner_updates'));
        $this->assertSame(0, DB::table('owner_updates')->count());
        $this->assertSame(OwnerUpdate::APPROVED, $this->approvedUpdate($this->draftUpdate(['show_money' => true, 'quotation_id' => $quotation->id]))->status);
    }

    public function test_a_weekly_update_belongs_to_a_company_and_a_project_and_keeps_money_to_two_places(): void
    {
        $this->assertContains(BelongsToOrganization::class, class_uses_recursive(OwnerUpdate::class));

        foreach (['organization_id', 'project_id', 'client_uuid', 'share_token', 'share_expires_at', 'share_revoked_at', 'approved_by', 'approved_at', 'shared_at', 'facts', 'fact_fingerprint', 'slots', 'body_rendered', 'photo_ids', 'next_week_text', 'typed_numbers', 'number_ticks'] as $column) {
            $this->assertTrue(Schema::hasColumn('owner_updates', $column), $column);
        }

        $this->site();
        $this->week();
        $update = $this->draftUpdate(['show_money' => true, 'quotation_id' => $this->quotationWithAStageReached()->id, 'received_amount' => '1234.5', 'client_uuid' => '7c1b1f0a-52d4-4c57-8f1d-0d6a3f1f9b21']);

        $this->assertSame('1234.50', number_format((float) DB::table('owner_updates')->where('id', $update->id)->value('received_amount'), 2, '.', ''));

        // One row per client_uuid in a company, by the table itself. Another company may hold the same key.
        $copy = fn (int $organization, int $project) => DB::table('owner_updates')->insert([
            'organization_id' => $organization, 'project_id' => $project, 'week_start' => '2026-11-09', 'week_end' => '2026-11-15',
            'language' => 'en', 'status' => 'draft', 'client_uuid' => '7c1b1f0a-52d4-4c57-8f1d-0d6a3f1f9b21',
        ]);

        try {
            // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
            DB::transaction(fn () => $copy($this->organization->id, $this->project->id));
            $this->fail('A second row was accepted.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        $other = $this->tenant('Konkan Builders');
        $this->assertTrue($copy($other->id, $this->makeProject($other)->id));
    }

    public function test_deleting_the_company_takes_its_weekly_updates_with_it(): void
    {
        $this->site();
        $this->week();
        $this->sharedUpdate($this->approvedUpdate($this->draftUpdate(['show_money' => true, 'quotation_id' => $this->quotationWithAStageReached()->id])));
        $other = $this->tenant('Konkan Builders');
        $theirs = $this->updates()->create($this->makeProject($other), ['week_start' => self::WEEK, 'language' => 'en'], $this->member($other, 'owner'));

        DB::table('organizations')->where('id', $this->organization->id)->delete();

        foreach ([...self::TABLES, 'owner_updates', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $this->organization->id)->count(), $table);
        }

        $this->assertNotNull(DB::table('owner_updates')->find($theirs->id));
    }
}
