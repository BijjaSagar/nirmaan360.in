<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\ImmutableRecordException;
use App\Models\ApprovalRule;
use App\Models\Indent;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\RfqQuotation;
use App\Services\ApprovalService;
use App\Services\Procurement\IndentService;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\RfqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementChainServiceTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    private Party $vendorA;

    private Party $vendorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->vendorA = $this->makeVendor('Alpha Steel');
        $this->vendorB = $this->makeVendor('Beta Traders');
    }

    private function draftIndent(array $overrides = []): Indent
    {
        return app(IndentService::class)->create($this->project, [
            'store_id' => $this->store->id,
            'required_by' => '2026-12-01',
            'items' => [
                ['material_id' => $this->cement->id, 'qty' => '100', 'unit_id' => $this->bag->id],
                ['material_id' => $this->steel->id, 'qty' => '10', 'unit_id' => $this->mt->id],
            ],
            ...$overrides,
        ], $this->owner);
    }

    private function approvedIndent(): Indent
    {
        $indent = $this->draftIndent();
        $engineer = $this->member($this->organization, 'engineer');
        $approval = app(IndentService::class)->submit($indent, $engineer);
        app(ApprovalService::class)->act($approval, $this->owner, 'approve');

        return $indent->refresh();
    }

    private function openRfq(): Rfq
    {
        return app(RfqService::class)->createFromIndent($this->approvedIndent(), [
            'party_ids' => [$this->vendorA->id, $this->vendorB->id],
        ], $this->owner);
    }

    /**
     * Worked example, lines are 100 bags of cement and 10 MT of steel.
     * Alpha: cement 395 at 18% = 39,500 + 7,110 = 46,610; steel 60,000 at 18% = 6,00,000 + 1,08,000 = 7,08,000.
     * Beta:  cement 380 at 28% = 38,000 + 10,640 = 48,640; steel 61,000 at 18% = 6,10,000 + 1,09,800 = 7,19,800.
     * So Alpha is L1 on both, even though Beta's cement rate is lower before tax.
     */
    private function quoteBoth(Rfq $rfq): void
    {
        $lines = $rfq->items()->get()->keyBy('material_id');
        $service = app(RfqService::class);

        $service->saveQuotation($rfq, ['party_id' => $this->vendorA->id, 'items' => [
            ['rfq_item_id' => $lines[$this->cement->id]->id, 'rate' => '395', 'gst_pct' => '18'],
            ['rfq_item_id' => $lines[$this->steel->id]->id, 'rate' => '60000', 'gst_pct' => '18'],
        ]]);

        $service->saveQuotation($rfq, ['party_id' => $this->vendorB->id, 'items' => [
            ['rfq_item_id' => $lines[$this->cement->id]->id, 'rate' => '380', 'gst_pct' => '28'],
            ['rfq_item_id' => $lines[$this->steel->id]->id, 'rate' => '61000', 'gst_pct' => '18'],
        ]]);
    }

    private function quotationFrom(Rfq $rfq, Party $vendor): RfqQuotation
    {
        return RfqQuotation::where('rfq_id', $rfq->id)->where('party_id', $vendor->id)->firstOrFail();
    }

    public function test_an_indent_converts_each_line_to_base_units(): void
    {
        $indent = $this->draftIndent()->load('items');

        $this->assertSame('DRAFT', $indent->status);
        $this->assertSame('5000.000', $indent->items[0]->base_qty);
        $this->assertSame('10000.000', $indent->items[1]->base_qty);
        $this->assertMatchesRegularExpression('/^IND-\d{4}-\d{2}-0001$/', $indent->number);
    }

    public function test_repeating_an_indent_with_the_same_uuid_returns_the_original(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->draftIndent(['client_uuid' => $uuid]);
        $second = $this->draftIndent(['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Indent::count());
    }

    public function test_an_indent_goes_to_the_pm_by_default_and_locks_when_approved(): void
    {
        $indent = $this->draftIndent();
        $engineer = $this->member($this->organization, 'engineer');
        $pm = $this->member($this->organization, 'pm');
        $approval = app(IndentService::class)->submit($indent, $engineer);

        $this->assertSame(['pm'], $approval->levels);
        $this->assertSame('IN_REVIEW', $indent->fresh()->status);
        $this->assertTrue(app(ApprovalService::class)->canAct($approval, $pm));

        app(ApprovalService::class)->act($approval, $pm, 'approve');
        $this->assertSame('APPROVED', $indent->fresh()->status);

        $this->expectException(ImmutableRecordException::class);
        $indent->fresh()->update(['remarks' => 'Changed after approval']);
    }

    public function test_a_company_rule_replaces_the_default_approval_chain(): void
    {
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'indent', 'levels' => ['accounts', 'owner'], 'active' => true]);

        $approval = app(IndentService::class)->submit($this->draftIndent(), $this->member($this->organization, 'engineer'));

        $this->assertSame(['accounts', 'owner'], $approval->levels);
    }

    public function test_a_company_rule_with_no_levels_approves_straight_away(): void
    {
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'indent', 'levels' => [], 'active' => true]);
        $indent = $this->draftIndent();

        $this->assertNull(app(IndentService::class)->submit($indent, $this->member($this->organization, 'engineer')));
        $this->assertSame('APPROVED', $indent->fresh()->status);
    }

    public function test_a_returned_indent_can_be_edited_and_resubmitted(): void
    {
        $indent = $this->draftIndent();
        $service = app(IndentService::class);
        $approval = $service->submit($indent, $this->member($this->organization, 'engineer'));
        app(ApprovalService::class)->act($approval, $this->owner, 'return', 'Quantity looks high');

        $service->update($indent->fresh(), [
            'store_id' => $this->store->id,
            'items' => [['material_id' => $this->cement->id, 'qty' => '60', 'unit_id' => $this->bag->id]],
        ]);

        $indent->refresh()->load('items');
        $this->assertCount(1, $indent->items);
        $this->assertSame('3000.000', $indent->items[0]->base_qty);
        $this->assertNotNull($service->submit($indent, $this->member($this->organization, 'store')));
    }

    public function test_an_indent_in_review_cannot_be_edited(): void
    {
        $indent = $this->draftIndent();
        $service = app(IndentService::class);
        $service->submit($indent, $this->member($this->organization, 'engineer'));

        $this->expectException(ValidationException::class);
        $service->update($indent->fresh(), [
            'store_id' => $this->store->id,
            'items' => [['material_id' => $this->cement->id, 'qty' => '1']],
        ]);
    }

    public function test_an_inactive_material_cannot_be_indented(): void
    {
        $this->cement->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->draftIndent();
    }

    public function test_only_an_approved_indent_can_go_for_quotation(): void
    {
        $this->expectException(ValidationException::class);
        app(RfqService::class)->createFromIndent($this->draftIndent(), ['party_ids' => [$this->vendorA->id]], $this->owner);
    }

    public function test_one_open_rfq_per_indent(): void
    {
        $rfq = $this->openRfq();

        try {
            app(RfqService::class)->createFromIndent($rfq->indent, ['party_ids' => [$this->vendorA->id]], $this->owner);
            $this->fail('A second open RFQ must be refused.');
        } catch (ValidationException) {
            $this->assertSame(1, Rfq::count());
        }

        app(RfqService::class)->close($rfq);
        $this->assertNotNull(app(RfqService::class)->createFromIndent($rfq->indent, ['party_ids' => [$this->vendorA->id]], $this->owner));
    }

    public function test_worked_example_comparison_picks_the_lowest_cost_including_gst(): void
    {
        $rfq = $this->openRfq();
        $this->quoteBoth($rfq);

        $comparison = app(RfqService::class)->compare($rfq);
        $alpha = $this->quotationFrom($rfq, $this->vendorA);
        $beta = $this->quotationFrom($rfq, $this->vendorB);

        $this->assertSame('754610.00', $alpha->total);
        $this->assertSame('768440.00', $beta->total);

        [$cement, $steel] = $comparison['lines'];
        $this->assertSame($alpha->id, $cement['l1']);
        $this->assertSame('46610.00', $cement['cells'][$alpha->id]['total']);
        $this->assertSame('48640.00', $cement['cells'][$beta->id]['total']);
        $this->assertSame($alpha->id, $steel['l1']);
        $this->assertSame($alpha->id, $comparison['cheapest_full']);
    }

    public function test_an_expired_quote_cannot_be_l1_and_cannot_be_awarded(): void
    {
        $rfq = $this->openRfq();
        $this->quoteBoth($rfq);
        $alpha = $this->quotationFrom($rfq, $this->vendorA);
        $alpha->update(['valid_until' => today()->subDay()]);

        $comparison = app(RfqService::class)->compare($rfq);
        $this->assertSame($this->quotationFrom($rfq, $this->vendorB)->id, $comparison['lines'][0]['l1']);

        $this->expectException(ValidationException::class);
        app(RfqService::class)->award($rfq, [['rfq_item_id' => $comparison['lines'][0]['item']->id, 'quotation_id' => $alpha->id]], (string) Str::uuid(), $this->owner);
    }

    public function test_a_vendor_need_not_quote_every_line(): void
    {
        $rfq = $this->openRfq();
        $cementLine = $rfq->items()->where('material_id', $this->cement->id)->firstOrFail();
        app(RfqService::class)->saveQuotation($rfq, ['party_id' => $this->vendorB->id, 'items' => [
            ['rfq_item_id' => $cementLine->id, 'rate' => '380', 'gst_pct' => '28'],
        ]]);

        $comparison = app(RfqService::class)->compare($rfq);

        $this->assertArrayNotHasKey($this->quotationFrom($rfq, $this->vendorB)->id, $comparison['lines'][1]['cells']);
        $this->assertNull($comparison['cheapest_full']);
    }

    public function test_worked_example_split_award_makes_one_po_per_vendor(): void
    {
        $rfq = $this->openRfq();
        $this->quoteBoth($rfq);
        $lines = $rfq->items()->get()->keyBy('material_id');

        $orders = app(RfqService::class)->award($rfq, [
            ['rfq_item_id' => $lines[$this->cement->id]->id, 'quotation_id' => $this->quotationFrom($rfq, $this->vendorB)->id],
            ['rfq_item_id' => $lines[$this->steel->id]->id, 'quotation_id' => $this->quotationFrom($rfq, $this->vendorA)->id],
        ], (string) Str::uuid(), $this->owner);

        $this->assertCount(2, $orders);
        $this->assertSame('AWARDED', $rfq->fresh()->status);

        $beta = $orders->firstWhere('party_id', $this->vendorB->id);
        $this->assertSame('38000.00', $beta->fresh()->subtotal);
        $this->assertSame('10640.00', $beta->fresh()->gst_amount);
        $this->assertSame('48640.00', $beta->fresh()->total);

        $alpha = $orders->firstWhere('party_id', $this->vendorA->id)->fresh()->load('items');
        $this->assertSame('708000.00', $alpha->total);
        $this->assertSame('DRAFT', $alpha->status);
        $this->assertSame('10000.000', $alpha->items[0]->base_qty);
        $this->assertSame($this->store->id, $alpha->store_id);
    }

    public function test_repeating_an_award_with_the_same_uuid_returns_the_same_orders(): void
    {
        $rfq = $this->openRfq();
        $this->quoteBoth($rfq);
        $alpha = $this->quotationFrom($rfq, $this->vendorA);
        $picks = $rfq->items()->get()->map(fn ($line) => ['rfq_item_id' => $line->id, 'quotation_id' => $alpha->id])->all();
        $uuid = (string) Str::uuid();

        $first = app(RfqService::class)->award($rfq, $picks, $uuid, $this->owner);
        $second = app(RfqService::class)->award($rfq, $picks, $uuid, $this->owner);

        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_a_line_can_only_be_awarded_once(): void
    {
        $rfq = $this->openRfq();
        $this->quoteBoth($rfq);
        $line = $rfq->items()->firstOrFail();
        $pick = [['rfq_item_id' => $line->id, 'quotation_id' => $this->quotationFrom($rfq, $this->vendorA)->id]];

        app(RfqService::class)->award($rfq, $pick, (string) Str::uuid(), $this->owner);

        $this->assertSame('OPEN', $rfq->fresh()->status);
        $this->expectException(ValidationException::class);
        app(RfqService::class)->award($rfq, $pick, (string) Str::uuid(), $this->owner);
    }

    public function test_an_issued_po_is_locked_and_can_be_cancelled_only_while_nothing_arrived(): void
    {
        $service = app(PurchaseOrderService::class);
        $rfq = $this->openRfq();
        $this->quoteBoth($rfq);
        $alpha = $this->quotationFrom($rfq, $this->vendorA);
        $order = app(RfqService::class)->award($rfq, $rfq->items()->get()->map(fn ($line) => ['rfq_item_id' => $line->id, 'quotation_id' => $alpha->id])->all(), (string) Str::uuid(), $this->owner)->first();

        $service->issue($order);
        $this->assertSame('ISSUED', $order->fresh()->status);

        try {
            $order->fresh()->update(['subtotal' => '1.00']);
            $this->fail('An issued PO must not change.');
        } catch (ImmutableRecordException) {
            $this->assertSame('639500.00', $order->fresh()->subtotal);
        }

        $this->assertSame('CANCELLED', $service->close($order)->status);

        $this->expectException(ValidationException::class);
        $service->issue($order->fresh());
    }
}
