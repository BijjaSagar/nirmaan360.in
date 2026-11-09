<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\ImmutableRecordException;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\StockAdjustment;
use App\Models\StockLedgerEntry;
use App\Services\Procurement\DocumentNumbers;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\StockAdjustmentService;
use App\Services\Procurement\StockLedgerService;
use App\Services\Procurement\UnitConverter;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockLedgerServiceTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_worked_example_stock_is_the_sum_of_its_movements(): void
    {
        // Opening 500 kg, issue 120 kg, issue 75.5 kg: 500 - 120 - 75.5 = 304.5 kg.
        $this->stockIn($this->cement, '500');
        $this->issue($this->cement, '120');
        $this->issue($this->cement, '75.5');

        $this->assertSame('304.500', $this->balanceOf($this->cement));

        $sum = StockLedgerEntry::where('store_id', $this->store->id)->where('material_id', $this->cement->id)->get()
            ->reduce(fn ($total, $row) => bcadd($total, $row->qty, 3), '0.000');

        $this->assertSame('304.500', $sum);
    }

    public function test_worked_example_unit_conversion_goes_to_the_base_unit(): void
    {
        $converter = app(UnitConverter::class);
        $this->cement->load('conversions');
        $this->steel->load('conversions');

        // 1 bag = 50 kg, so 12.5 bags = 625 kg.
        $this->assertSame('625.000', $converter->toBase($this->cement, '12.5', $this->bag->id));
        // 1 MT = 1000 kg, so 1.234 MT = 1234 kg.
        $this->assertSame('1234.000', $converter->toBase($this->steel, '1.234', $this->mt->id));
        // The base unit needs no conversion.
        $this->assertSame('42.000', $converter->toBase($this->cement, '42', $this->kg->id));
    }

    public function test_a_unit_with_no_conversion_is_refused(): void
    {
        $this->cement->load('conversions');

        $this->expectException(ValidationException::class);
        app(UnitConverter::class)->toBase($this->cement, '1', $this->mt->id);
    }

    public function test_stock_cannot_go_negative(): void
    {
        $this->stockIn($this->cement, '100');
        $this->issue($this->cement, '40');

        try {
            $this->issue($this->cement, '60.001');
            $this->fail('An issue larger than stock must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stock', $exception->errors());
        }

        $this->assertSame('60.000', $this->balanceOf($this->cement));
        $this->assertSame(2, StockLedgerEntry::count());
    }

    public function test_exactly_all_the_stock_can_be_issued(): void
    {
        $this->stockIn($this->cement, '100');
        $this->issue($this->cement, '100');

        $this->assertSame('0.000', $this->balanceOf($this->cement));
    }

    public function test_two_lines_of_one_issue_are_checked_together(): void
    {
        $this->stockIn($this->cement, '100');

        $this->expectException(ValidationException::class);

        try {
            app(MaterialIssueService::class)->create($this->project, [
                'store_id' => $this->store->id,
                'issue_date' => '2026-11-02',
                'items' => [
                    ['material_id' => $this->cement->id, 'qty' => '60', 'activity' => 'Slab'],
                    ['material_id' => $this->cement->id, 'qty' => '41', 'activity' => 'Column'],
                ],
            ], $this->owner);
        } finally {
            $this->assertSame('100.000', $this->balanceOf($this->cement));
        }
    }

    public function test_a_refused_document_leaves_nothing_behind(): void
    {
        $this->stockIn($this->cement, '10');

        try {
            $this->issue($this->cement, '11');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame(0, MaterialIssue::count());
        $this->assertSame(0, MaterialIssueItem::count());
    }

    public function test_the_ledger_model_refuses_updates_and_deletes(): void
    {
        $this->stockIn($this->cement, '10');
        $entry = StockLedgerEntry::firstOrFail();

        try {
            $entry->update(['qty' => '999']);
            $this->fail('A ledger row must not be editable.');
        } catch (ImmutableRecordException) {
            $this->assertSame('10.000', $entry->fresh()->qty);
        }

        $this->expectException(ImmutableRecordException::class);
        $entry->delete();
    }

    public function test_the_database_refuses_a_raw_update(): void
    {
        $this->stockIn($this->cement, '10');

        $this->expectException(QueryException::class);
        DB::table('stock_ledger')->update(['qty' => 999]);
    }

    public function test_the_database_refuses_a_raw_delete(): void
    {
        $this->stockIn($this->cement, '10');

        $this->expectException(QueryException::class);
        DB::table('stock_ledger')->delete();
    }

    public function test_one_posting_key_can_only_post_once(): void
    {
        $adjustment = $this->stockIn($this->cement, '10');

        $this->expectException(QueryException::class);
        app(StockLedgerService::class)->post($this->organization->id, [[
            'project_id' => $this->project->id,
            'store_id' => $this->store->id,
            'material_id' => $this->cement->id,
            'type' => StockLedgerService::ADJUSTMENT,
            'qty' => '10',
            'date' => '2026-11-01',
            'source' => $adjustment,
            'key' => 'adjustment:'.$adjustment->id,
        ]], $this->owner);
    }

    public function test_stock_card_shows_a_running_balance(): void
    {
        $this->stockIn($this->cement, '100');
        $this->issue($this->cement, '30');
        $this->issue($this->cement, '20.5');

        $rows = app(StockLedgerService::class)->stockCard($this->store, $this->cement)->items();

        // Newest first.
        $this->assertEqualsWithDelta(49.5, (float) $rows[0]->running_balance, 0.0005);
        $this->assertEqualsWithDelta(70.0, (float) $rows[1]->running_balance, 0.0005);
        $this->assertEqualsWithDelta(100.0, (float) $rows[2]->running_balance, 0.0005);
    }

    public function test_opening_stock_only_works_on_a_store_with_no_movements(): void
    {
        $this->stockIn($this->cement, '10');

        $this->expectException(ValidationException::class);
        $this->stockIn($this->cement, '10');
    }

    public function test_a_decrease_cannot_take_stock_negative_and_a_reversal_restores_it(): void
    {
        $service = app(StockAdjustmentService::class);
        $this->stockIn($this->cement, '10');

        $decrease = $service->create($this->project, [
            'store_id' => $this->store->id,
            'material_id' => $this->cement->id,
            'adjustment_type' => 'decrease',
            'qty' => '4',
            'adjustment_date' => '2026-11-02',
            'reason' => 'Count short',
        ], $this->owner);

        $this->assertSame('6.000', $this->balanceOf($this->cement));

        $service->reverse($decrease, 'Miscounted', $this->owner);
        $this->assertSame('10.000', $this->balanceOf($this->cement));
        $this->assertSame('REVERSED', $decrease->fresh()->status);

        // Three rows now exist: opening, decrease, and its reversal. Nothing was removed.
        $this->assertSame(3, StockLedgerEntry::count());

        $this->expectException(ValidationException::class);
        $service->reverse($decrease, 'Again', $this->owner);
    }

    public function test_repeating_an_adjustment_with_the_same_uuid_posts_once(): void
    {
        $service = app(StockAdjustmentService::class);
        $data = [
            'store_id' => $this->store->id,
            'material_id' => $this->cement->id,
            'adjustment_type' => 'increase',
            'qty' => '5',
            'adjustment_date' => '2026-11-02',
            'reason' => 'Found on recount',
            'client_uuid' => (string) Str::uuid(),
        ];

        $first = $service->create($this->project, $data, $this->owner);
        $second = $service->create($this->project, $data, $this->owner);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('5.000', $this->balanceOf($this->cement));
        $this->assertSame(1, StockAdjustment::count());
    }

    public function test_a_clash_with_no_uuid_fails_instead_of_returning_some_other_row(): void
    {
        // Without a client_uuid there is nothing to replay, so a duplicate code must not
        // be mistaken for a retry and handed back as if it had been saved.
        $this->expectException(UniqueConstraintViolationException::class);

        app(MaterialCatalogService::class)->createMaterial([
            'code' => $this->cement->code,
            'name' => 'Something else entirely',
            'base_unit_id' => $this->kg->id,
            'gst_pct' => '18',
        ]);
    }

    public function test_financial_year_runs_april_to_march(): void
    {
        $this->assertSame('2026-27', DocumentNumbers::financialYear(Carbon::parse('2026-04-01')));
        $this->assertSame('2026-27', DocumentNumbers::financialYear(Carbon::parse('2027-03-31')));
        $this->assertSame('2027-28', DocumentNumbers::financialYear(Carbon::parse('2027-04-01')));
    }

    public function test_document_numbers_run_in_sequence_per_company_and_series(): void
    {
        $numbers = app(DocumentNumbers::class);
        $date = Carbon::parse('2026-11-09');

        $this->assertSame('PO-2026-27-0001', $numbers->next($this->organization->id, 'PO', $date));
        $this->assertSame('PO-2026-27-0002', $numbers->next($this->organization->id, 'PO', $date));
        $this->assertSame('GRN-2026-27-0001', $numbers->next($this->organization->id, 'GRN', $date));
        $this->assertSame('PO-2026-27-0001', $numbers->next($this->tenant('Other Co')->id, 'PO', $date));
    }
}
