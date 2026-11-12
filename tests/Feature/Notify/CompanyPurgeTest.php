<?php

namespace Tests\Feature\Notify;

use App\Models\BoqItem;
use App\Models\CompanyDeletionRequest;
use App\Models\CustomRole;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\TenantData;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\StockAdjustmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/** Erasing a company that has used every module, including the append-only stock ledger. */
class CompanyPurgeTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    private const MIGRATION = 'migrations/2027_01_04_100001_allow_stock_ledger_purge.php';

    private const LEDGER_MIGRATION = 'migrations/2026_12_05_100002_create_stock_ledger_tables.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMoneyWorld();
    }

    /** Stock in, a reversal of it, and an issue, on top of the challan the money world already posted. */
    private function moveStock(Project $project, MaterialStore $store, Material $material, User $by): void
    {
        $adjustments = app(StockAdjustmentService::class);
        $in = $adjustments->create($project, ['store_id' => $store->id, 'material_id' => $material->id, 'adjustment_type' => 'increase', 'qty' => '40', 'adjustment_date' => '2026-11-02', 'reason' => 'Found in the yard'], $by);
        $adjustments->reverse($in, 'Counted twice', $by);
        $adjustments->create($project, ['store_id' => $store->id, 'material_id' => $material->id, 'adjustment_type' => 'increase', 'qty' => '25', 'adjustment_date' => '2026-11-03', 'reason' => 'Recount'], $by);

        app(MaterialIssueService::class)->create($project, [
            'store_id' => $store->id,
            'issue_date' => '2026-11-04',
            'items' => [['material_id' => $material->id, 'qty' => '5', 'activity' => 'Slab']],
        ], $by);
    }

    /**
     * Rows for the tables whose foreign keys have no delete action and that the money world leaves
     * empty, so every such key is exercised by the purge.
     */
    private function fillTheOtherUncascadedKeys(): void
    {
        $org = $this->organization->id;
        $now = now();
        $worker = $this->rows[Worker::class];

        DB::table('labour_allocations')->insert(['organization_id' => $org, 'project_id' => $this->project->id, 'worker_id' => $worker->id, 'boq_item_id' => $this->rows[BoqItem::class]->id, 'work_date' => '2026-11-05', 'man_days' => '1.000', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('worker_advances')->insert(['organization_id' => $org, 'worker_id' => $worker->id, 'amount' => '500.00', 'given_on' => '2026-11-05', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('material_short_deliveries')->insert(['organization_id' => $org, 'project_id' => $this->project->id, 'party_id' => $this->rows[Party::class]->id, 'delivery_challan_id' => $this->rows[DeliveryChallan::class]->id, 'short_qty' => '2.000', 'short_base_qty' => '2.000', 'found_on' => '2026-11-06', 'created_at' => $now, 'updated_at' => $now]);
        $this->users['viewer']->forceFill(['custom_role_id' => $this->rows[CustomRole::class]->id])->save();
    }

    /** A second company with stock of its own, which must come through untouched. */
    private function rivalWithStock(): Organization
    {
        $rival = $this->tenant('Rival Constructions');
        $owner = $this->member($rival, 'owner');
        $this->actingAs($owner);

        $project = $this->makeProject($rival);
        $kg = MaterialUnit::create(['organization_id' => $rival->id, 'code' => 'kg']);
        $sand = Material::create(['organization_id' => $rival->id, 'code' => 'SAND', 'name' => 'River sand', 'base_unit_id' => $kg->id]);
        $store = MaterialStore::create(['organization_id' => $rival->id, 'project_id' => $project->id, 'name' => 'Rival store']);
        $this->moveStock($project, $store, $sand, $owner);

        $this->actingAs($this->users['owner']);

        return $rival;
    }

    /** Runs the nightly purge and fails with the logged reason if a company could not be erased. */
    private function purgeAfterGrace(int $expected = 1): void
    {
        $errors = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$errors) {
            if ($log->level === 'error') {
                $errors[] = $log->message.': '.json_encode($log->context);
            }
        });

        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        Auth::forgetGuards();

        $this->assertSame($expected, app(CompanyDeletionService::class)->purgeDue(), implode("\n", $errors));
    }

    /** One statement in its own savepoint, so a refusal on PostgreSQL does not abort the test's transaction. */
    private function refused(callable $statement): bool
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $exception) {
            return str_contains($exception->getMessage(), 'append-only');
        }

        return false;
    }

    private function ledgerRows(int $organizationId): int
    {
        return DB::table('stock_ledger')->where('organization_id', $organizationId)->count();
    }

    public function test_a_company_with_stock_procurement_finance_and_payroll_is_erased_completely(): void
    {
        $org = $this->organization->id;
        $this->moveStock($this->project, $this->rows[MaterialStore::class], $this->rows[Material::class], $this->users['store']);
        $this->fillTheOtherUncascadedKeys();
        $rival = $this->rivalWithStock();
        $rivalLedger = $this->ledgerRows($rival->id);

        $this->assertGreaterThanOrEqual(5, $this->ledgerRows($org), 'The company has stock movements of several kinds.');
        $this->assertGreaterThan(0, $rivalLedger);
        foreach (['purchase_orders', 'grns', 'material_issues', 'stock_transfers', 'stock_adjustments', 'gst_invoices', 'vendor_bills', 'wage_runs', 'wage_run_lines', 'worker_advances', 'labour_allocations', 'expense_claims', 'staff_leaves', 'workers', 'material_short_deliveries'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('organization_id', $org)->count(), "The money world has {$table} rows.");
        }

        app(CompanyDeletionService::class)->request($this->organization, $this->users['owner'], 'Sentinel Infra');
        $this->purgeAfterGrace();

        $this->assertDatabaseMissing('organizations', ['id' => $org]);
        $this->assertSame(0, User::where('organization_id', $org)->count());

        foreach (array_keys(app(TenantData::class)->tables()) as $table) {
            if ($table !== 'company_deletion_requests') {
                $this->assertSame(0, DB::table($table)->where('organization_id', $org)->count(), "{$table} still holds the erased company's rows.");
            }
        }

        $this->assertSame(0, DB::table('stock_ledger_purges')->count(), 'The purge marker is removed with the movements.');
        Storage::assertMissing('none/photo.jpg');
        Storage::assertMissing('none/challan-bill.pdf');
        $this->assertSame('completed', CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $org)->sole()->status);

        // The other company keeps its stock, and its ledger is as locked as before.
        $this->assertDatabaseHas('organizations', ['id' => $rival->id]);
        $this->assertSame($rivalLedger, $this->ledgerRows($rival->id));
        $this->assertTrue($this->refused(fn () => DB::table('stock_ledger')->where('organization_id', $rival->id)->delete()));
    }

    public function test_the_ledger_still_refuses_updates_and_deletes_outside_a_purge(): void
    {
        $org = $this->organization->id;
        $this->moveStock($this->project, $this->rows[MaterialStore::class], $this->rows[Material::class], $this->users['store']);
        $rival = $this->rivalWithStock();
        $before = $this->ledgerRows($org);

        $this->assertTrue($this->refused(fn () => DB::table('stock_ledger')->where('organization_id', $org)->update(['qty' => 999])));
        $this->assertTrue($this->refused(fn () => DB::table('stock_ledger')->where('organization_id', $org)->delete()));

        // A marker opens deletes for its own company only, and never opens an update.
        DB::table('stock_ledger_purges')->insert(['purging_organization_id' => $rival->id, 'created_at' => now()]);
        $this->assertTrue($this->refused(fn () => DB::table('stock_ledger')->where('organization_id', $org)->delete()));

        DB::table('stock_ledger_purges')->insert(['purging_organization_id' => $org, 'created_at' => now()]);
        $this->assertTrue($this->refused(fn () => DB::table('stock_ledger')->where('organization_id', $org)->update(['qty' => 999])));

        $this->assertSame($before, $this->ledgerRows($org));
        $this->assertSame(0, DB::table('stock_ledger')->where('organization_id', $org)->where('qty', 999)->count());
    }

    public function test_a_failed_purge_leaves_the_company_and_its_ledger_whole(): void
    {
        $org = $this->organization->id;
        $this->moveStock($this->project, $this->rows[MaterialStore::class], $this->rows[Material::class], $this->users['store']);
        $before = $this->ledgerRows($org);
        app(CompanyDeletionService::class)->request($this->organization, $this->users['owner'], 'Sentinel Infra');

        // A marker left behind by hand makes the purge's own insert fail, so the whole transaction rolls back.
        DB::table('stock_ledger_purges')->insert(['purging_organization_id' => $org, 'created_at' => now()]);
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        Auth::forgetGuards();

        $this->assertSame(0, app(CompanyDeletionService::class)->purgeDue());
        $this->assertDatabaseHas('organizations', ['id' => $org]);
        $this->assertSame($before, $this->ledgerRows($org));
        $this->assertSame('pending', CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $org)->sole()->status);
    }

    /** @return list<string> The guard as the database holds it: the function and triggers, or the trigger text. */
    private function guard(): array
    {
        return match (DB::getDriverName()) {
            'pgsql' => [
                ...DB::table('pg_proc')->where('proname', 'stock_ledger_append_only')->pluck('prosrc')->all(),
                ...collect(DB::select("SELECT pg_get_triggerdef(oid) AS def FROM pg_trigger WHERE tgrelid = 'stock_ledger'::regclass AND NOT tgisinternal ORDER BY tgname"))->pluck('def')->all(),
            ],
            'sqlite' => DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'stock_ledger')->orderBy('name')->pluck('sql')->all(),
        };
    }

    /** Rebuilds the guard exactly as the stock ledger migration first made it. */
    private function originalGuard(): array
    {
        $ledger = require database_path(self::LEDGER_MIGRATION);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER stock_ledger_no_change ON stock_ledger');
            (new ReflectionMethod($ledger, 'createPostgresTriggers'))->invoke($ledger);
        } else {
            DB::statement('DROP TRIGGER stock_ledger_no_update');
            DB::statement('DROP TRIGGER stock_ledger_no_delete');
            (new ReflectionMethod($ledger, 'createSqliteTriggers'))->invoke($ledger);
        }

        return $this->guard();
    }

    public function test_rolling_back_restores_the_original_guard_word_for_word(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migrated = $this->guard();

        $migration->down();
        $rolledBack = $this->guard();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('stock_ledger_purges'));
        $this->assertSame($this->originalGuard(), $rolledBack);
        $this->assertNotSame($migrated, $rolledBack);

        $migration->up();
        $this->assertSame($migrated, $this->guard());
    }
}
