<?php

namespace Tests\Feature\ContractTerms;

use App\Models\ContractTerm;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContractTermsMigrationTest extends ContractTermsTestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2027_01_14_100001_create_contract_terms_table.php');
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $this->term();
        $this->assertTrue(Schema::hasTable('contract_terms'));

        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('contract_terms'));

        // Nothing else was touched on the way down.
        $this->assertTrue(Schema::hasTable('projects'));
        $this->assertTrue(Schema::hasTable('work_orders'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasTable('contract_terms'));
        $this->assertSame(0, ContractTerm::withoutGlobalScopes()->withTrashed()->count());

        foreach (['organization_id', 'project_id', 'work_order_id', 'kind', 'code', 'label', ...ContractTerm::VALUE_COLUMNS, 'effective_from', 'effective_to', 'source_ref', 'entered_by', 'confirmed_by', 'confirmed_at', 'client_uuid', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('contract_terms', $column), $column);
        }

        $this->assertNotNull($this->term()->id);
    }

    public function test_a_request_id_is_kept_once_per_company(): void
    {
        $uuid = '33333333-3333-4333-8333-333333333333';
        $this->term(['client_uuid' => $uuid]);

        // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
        try {
            DB::transaction(fn () => $this->term(['client_uuid' => $uuid, 'code' => 'tds', 'label' => 'TDS']));
            $this->fail('The same request id was saved twice in one company.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        // Another company may happen to use the same value, and rows with none never clash.
        [, , $rivalProject] = $this->rival();
        $this->term(['client_uuid' => $uuid], $rivalProject);
        $this->term();
        $this->term();

        $this->assertSame(4, ContractTerm::withoutGlobalScopes()->count());
    }

    public function test_terms_go_with_their_project_and_survive_the_people_who_wrote_them(): void
    {
        $term = $this->confirmed(['entered_by' => $this->qs->id, 'confirmed_by' => $this->pm->id]);
        $other = $this->term([], $this->makeProject($this->org, ['code' => 'MB2']));

        DB::table('users')->where('id', $this->qs->id)->delete();
        DB::table('users')->where('id', $this->pm->id)->delete();

        $term->refresh();
        $this->assertNull($term->entered_by);
        $this->assertNull($term->confirmed_by);
        $this->assertTrue($term->isConfirmed(), 'The confirmation stands; only the name is gone.');

        DB::table('projects')->where('id', $this->project->id)->delete();

        $this->assertSame([$other->id], ContractTerm::withoutGlobalScopes()->pluck('id')->all());
    }
}
