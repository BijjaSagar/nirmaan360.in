<?php

namespace Tests\Feature\BillCheck;

use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\BillCheckExplanation;
use App\Models\BillCheckPolicy;
use App\Services\BillCheck\BillChecks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BillCheckMigrationTest extends BillCheckTestCase
{
    private const TABLES = ['bill_checks', 'bill_check_acks', 'bill_check_policies'];

    private function migration(): object
    {
        return require database_path('migrations/2027_01_14_100002_create_bill_check_tables.php');
    }

    /** The explanations of a check, which hang on the checks table and so come off before it and go back after it. */
    private function explanations(): object
    {
        return require database_path('migrations/2027_01_14_100003_create_bill_check_explanations_table.php');
    }

    private function checkedBill(): BillCheck
    {
        $this->measure('20');

        return $this->check($this->clientBill(), $this->pm);
    }

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $check = $this->checkedBill();
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'Seen and noted', $this->qs);
        $this->requireCheck();

        $this->explanations()->down();
        $this->migration()->down();

        foreach ([...self::TABLES, 'bill_check_explanations'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        // Nothing else was touched on the way down, and a company without the tables is one that never asked for the check.
        $this->assertTrue(Schema::hasTable('ra_bills'));
        $this->assertTrue(Schema::hasTable('contract_terms'));
        $this->assertSame(1, DB::table('ra_bills')->count());

        $this->migration()->up();
        $this->explanations()->up();

        foreach ([...self::TABLES, 'bill_check_explanations'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        foreach (['organization_id', 'project_id', 'ra_bill_id', 'flags', 'flags_hash', 'summary', 'expected', 'run_by', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('bill_checks', $column), $column);
        }

        foreach (['organization_id', 'project_id', 'bill_check_id', 'flag_key', 'remark', 'user_id', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('bill_check_acks', $column), $column);
        }

        foreach (['organization_id', 'required', 'updated_by', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('bill_check_policies', $column), $column);
        }

        foreach (['organization_id', 'project_id', 'bill_check_id', 'locale', 'source', 'slots', 'fingerprint', 'reason', 'ai_text_call_id', 'requested_by', 'drafted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('bill_check_explanations', $column), $column);
        }

        $this->assertFalse(app(BillChecks::class)->isRequired($this->org->id), 'No row, so the check is not required.');
        $this->assertNotNull($this->check($check->bill, $this->pm)->id);
    }

    public function test_a_finding_is_acknowledged_once_per_check_and_a_company_has_one_policy(): void
    {
        $check = $this->checkedBill();
        $ack = ['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'bill_check_id' => $check->id, 'flag_key' => 'T1:tds', 'remark' => 'Seen and noted', 'user_id' => $this->pm->id];
        BillCheckAck::create($ack);

        // In its own savepoint: on PostgreSQL a failed statement would otherwise abort the test's transaction.
        try {
            DB::transaction(fn () => BillCheckAck::create([...$ack, 'remark' => 'A second time']));
            $this->fail('The same finding of one check was acknowledged twice.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        BillCheckPolicy::create(['organization_id' => $this->org->id, 'required' => true]);

        try {
            DB::transaction(fn () => BillCheckPolicy::create(['organization_id' => $this->org->id, 'required' => false]));
            $this->fail('One company got two policies.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        // The same finding on a later check of the same bill is another acknowledgement.
        $this->term();
        $later = $this->check($check->bill, $this->pm);
        BillCheckAck::create([...$ack, 'bill_check_id' => $later->id]);

        $this->assertSame(2, BillCheckAck::withoutGlobalScopes()->count());
        $this->assertSame(1, BillCheckPolicy::withoutGlobalScopes()->count());
    }

    public function test_the_explanations_can_be_rolled_back_alone_and_one_language_is_kept_once_per_check(): void
    {
        $check = $this->checkedBill();
        $row = ['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'bill_check_id' => $check->id, 'locale' => 'mr', 'requested_by' => $this->pm->id];
        BillCheckExplanation::create($row);

        try {
            DB::transaction(fn () => BillCheckExplanation::create($row));
            $this->fail('One check was explained twice in the same language.');
        } catch (UniqueConstraintViolationException) {
            // Refused by the table itself.
        }

        BillCheckExplanation::create([...$row, 'locale' => 'hi']);
        $this->assertSame(['template', 'template'], BillCheckExplanation::withoutGlobalScopes()->pluck('source')->all());

        $this->explanations()->down();
        $this->assertFalse(Schema::hasTable('bill_check_explanations'));
        $this->assertSame(1, DB::table('bill_checks')->count(), 'The checks are not touched.');

        $this->explanations()->up();
        $this->assertSame(0, DB::table('bill_check_explanations')->count());

        // An explanation goes with its check and survives the person who asked for it.
        BillCheckExplanation::create($row);
        DB::table('users')->where('id', $this->pm->id)->delete();
        $this->assertNull(BillCheckExplanation::withoutGlobalScopes()->sole()->requested_by);
        DB::table('ra_bills')->where('id', $check->ra_bill_id)->delete();
        $this->assertSame(0, BillCheckExplanation::withoutGlobalScopes()->count());
    }

    public function test_a_check_goes_with_its_bill_and_survives_the_people_who_made_it(): void
    {
        $check = $this->checkedBill();
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'Seen and noted', $this->qs);
        $this->requireCheck();
        [, , , $rivalCheck] = $this->rival();

        DB::table('users')->whereIn('id', [$this->pm->id, $this->qs->id])->delete();

        $this->assertNull($check->refresh()->run_by);
        $ack = BillCheckAck::withoutGlobalScopes()->where('bill_check_id', $check->id)->sole();
        $this->assertNull($ack->user_id);
        $this->assertSame('Seen and noted', $ack->remark, 'The acknowledgement stands; only the name is gone.');

        DB::table('ra_bills')->where('id', $check->ra_bill_id)->delete();

        $this->assertSame([$rivalCheck->id], BillCheck::withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame(0, BillCheckAck::withoutGlobalScopes()->count());

        // The policy belongs to the company, not to a bill.
        $this->assertTrue(app(BillChecks::class)->isRequired($this->org->id));
        DB::table('organizations')->where('id', $this->org->id)->delete();
        $this->assertSame(0, BillCheckPolicy::withoutGlobalScopes()->count());
    }
}
