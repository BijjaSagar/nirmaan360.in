<?php

namespace Tests\Feature\Procurement;

use App\Models\Grn;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialStore;
use App\Models\PurchaseOrder;
use App\Models\StockLedgerEntry;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\StockAdjustmentService;
use App\Services\Procurement\StockLedgerService;
use App\Services\Procurement\StockTransferService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

/**
 * Real processes racing on a real database. SQLite has no row locks, so this needs Postgres:
 *
 *   PROCUREMENT_PGSQL_TEST_URL=pgsql://user@127.0.0.1:5432/anything_test php artisan test --filter=StockConcurrencyTest
 *
 * It runs migrate:fresh, so the database name must end in _test.
 */
class StockConcurrencyTest extends TestCase
{
    use BuildsProcurement;

    private const CONNECTION = 'procurement_concurrency';

    private const WORKERS = 8;

    private const ATTEMPTS = 5;

    private string $errorLog;

    protected function setUp(): void
    {
        $url = (string) getenv('PROCUREMENT_PGSQL_TEST_URL');

        if ($url === '') {
            $this->markTestSkipped('Set PROCUREMENT_PGSQL_TEST_URL to a throwaway Postgres database to run this.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension.');
        }

        if (! str_ends_with((string) parse_url($url, PHP_URL_PATH), '_test')) {
            $this->fail('Refusing to run migrate:fresh: the database name must end in _test.');
        }

        parent::setUp();

        config([
            'database.connections.'.self::CONNECTION => [...config('database.connections.pgsql'), 'url' => $url, 'sslmode' => 'disable'],
            'database.default' => self::CONNECTION,
        ]);
        $this->artisan('migrate:fresh', ['--force' => true]);

        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'stock-race');
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        if (isset($this->errorLog)) {
            @unlink($this->errorLog);
        }

        parent::tearDown();
    }

    /** Each worker opens its own connection, and exits hard so it runs no test teardown. */
    private function race(Closure $work, int $attempts = self::ATTEMPTS): void
    {
        DB::purge(self::CONNECTION);
        $pids = [];
        $startAt = microtime(true) + 0.25;

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'Could not fork.');

            if ($pid === 0) {
                // Hold the gap between reading stock and writing the movement wide open, so
                // workers really do collide. Locked code only gets slower. Unlocked code breaks.
                DB::listen(function ($query) {
                    if (str_contains($query->sql, 'sum("qty")')) {
                        usleep(20_000);
                    }
                });

                DB::connection()->getPdo();

                while (microtime(true) < $startAt) {
                    usleep(200);
                }

                try {
                    for ($attempt = 0; $attempt < $attempts; $attempt++) {
                        try {
                            $work($worker, $attempt);
                        } catch (ValidationException) {
                            // A refusal is the correct outcome once the stock has run out.
                        }
                    }
                } catch (Throwable $exception) {
                    file_put_contents($this->errorLog, get_class($exception).': '.$exception->getMessage()."\n", FILE_APPEND);
                }

                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge(self::CONNECTION);
        $this->assertSame('', (string) file_get_contents($this->errorLog), 'A worker hit an unexpected error.');
    }

    private function assertNeverNegative(MaterialStore $store, Material $material): void
    {
        $running = '0.000';

        foreach (StockLedgerEntry::where('store_id', $store->id)->where('material_id', $material->id)->orderBy('id')->get() as $row) {
            $running = bcadd($running, $row->qty, 3);
            $this->assertGreaterThanOrEqual(0, bccomp($running, '0', 3), "Stock went negative at ledger row {$row->id}.");
        }
    }

    public function test_racing_issues_can_only_take_the_stock_that_exists(): void
    {
        // 100 kg in stock. 8 workers try 5 issues of 5 kg each: 200 kg of demand.
        $this->stockIn($this->cement, '100');

        $this->race(fn ($worker, $attempt) => app(MaterialIssueService::class)->create($this->project, [
            'store_id' => $this->store->id,
            'issue_date' => '2026-11-02',
            'items' => [['material_id' => $this->cement->id, 'qty' => '5', 'activity' => 'Slab']],
        ], $this->owner));

        $this->assertSame('0.000', app(StockLedgerService::class)->balance($this->store->id, $this->cement->id));
        $this->assertSame(20, MaterialIssue::count());
        $this->assertNeverNegative($this->store, $this->cement);
    }

    /**
     * Different document types use different number counters, so nothing but the stock
     * lock stands between them. This is the race that needs it. Each round puts exactly
     * one take of stock on the shelf and lets every worker grab for it at the same moment.
     */
    public function test_issues_and_transfers_fighting_over_the_last_of_the_stock(): void
    {
        $yard = $this->makeStore($this->project, 'Yard');
        $rounds = 6;

        for ($round = 0; $round < $rounds; $round++) {
            app(StockAdjustmentService::class)->create($this->project, [
                'store_id' => $this->store->id,
                'material_id' => $this->cement->id,
                'adjustment_type' => 'increase',
                'qty' => '5',
                'adjustment_date' => '2026-11-01',
                'reason' => 'Next round',
            ], $this->owner);

            $this->race(function ($worker) use ($yard) {
                if ($worker % 2 === 0) {
                    app(MaterialIssueService::class)->create($this->project, [
                        'store_id' => $this->store->id,
                        'issue_date' => '2026-11-02',
                        'items' => [['material_id' => $this->cement->id, 'qty' => '5', 'activity' => 'Slab']],
                    ], $this->owner);

                    return;
                }

                app(StockTransferService::class)->dispatch($this->project, [
                    'from_store_id' => $this->store->id,
                    'to_store_id' => $yard->id,
                    'dispatch_date' => '2026-11-02',
                    'items' => [['material_id' => $this->cement->id, 'qty' => '5']],
                ], $this->owner);
            }, attempts: 1);

            $this->assertSame('0.000', app(StockLedgerService::class)->balance($this->store->id, $this->cement->id), "Round {$round} left the wrong stock.");
        }

        $this->assertSame($rounds, MaterialIssue::count() + StockTransfer::count());
        $this->assertNeverNegative($this->store, $this->cement);
    }

    public function test_racing_receipts_cannot_exceed_the_purchase_order(): void
    {
        // 100 bags ordered. 8 workers try 5 receipts of 5 bags each: 200 bags of receipts.
        $order = $this->issuedOrder();
        $lineId = $order->items[0]->id;

        $this->race(fn ($worker, $attempt) => app(GrnService::class)->create($this->project, [
            'purchase_order_id' => $order->id,
            'grn_date' => '2026-11-03',
            'items' => [['purchase_order_item_id' => $lineId, 'qty' => '5', 'unit_id' => $this->bag->id]],
        ], $this->owner));

        $this->assertSame('5000.000', app(StockLedgerService::class)->balance($this->store->id, $this->cement->id));
        $this->assertSame(20, Grn::count());
        $this->assertSame('RECEIVED', PurchaseOrder::findOrFail($order->id)->status);
    }

    public function test_the_same_uuid_racing_from_every_worker_posts_once(): void
    {
        $this->stockIn($this->cement, '100');
        $uuid = (string) Str::uuid();

        $this->race(fn ($worker, $attempt) => app(MaterialIssueService::class)->create($this->project, [
            'client_uuid' => $uuid,
            'store_id' => $this->store->id,
            'issue_date' => '2026-11-02',
            'items' => [['material_id' => $this->cement->id, 'qty' => '40', 'activity' => 'Slab']],
        ], $this->owner));

        $this->assertSame(1, MaterialIssue::count());
        $this->assertSame('60.000', app(StockLedgerService::class)->balance($this->store->id, $this->cement->id));
    }

    public function test_opposing_transfers_finish_without_deadlocking(): void
    {
        // Half the workers send cement then steel one way, half steel then cement the other.
        $yard = $this->makeStore($this->project, 'Yard');

        foreach ([$this->store, $yard] as $store) {
            $this->stockIn($this->cement, '1000', $store);
            $this->stockIn($this->steel, '1000', $store);
        }

        $this->race(function ($worker, $attempt) use ($yard) {
            [$from, $to, $first, $second] = $worker % 2 === 0
                ? [$this->store, $yard, $this->cement, $this->steel]
                : [$yard, $this->store, $this->steel, $this->cement];

            $transfer = app(StockTransferService::class)->dispatch($this->project, [
                'from_store_id' => $from->id,
                'to_store_id' => $to->id,
                'dispatch_date' => '2026-11-03',
                'items' => [
                    ['material_id' => $first->id, 'qty' => '1'],
                    ['material_id' => $second->id, 'qty' => '1'],
                ],
            ], $this->owner);

            app(StockTransferService::class)->receive($transfer, '2026-11-03', [], $this->owner);
        });

        // Every transfer completed, and equal numbers went each way.
        $ledger = app(StockLedgerService::class);
        $this->assertSame('1000.000', $ledger->balance($this->store->id, $this->cement->id));
        $this->assertSame('1000.000', $ledger->balance($yard->id, $this->steel->id));
    }
}
