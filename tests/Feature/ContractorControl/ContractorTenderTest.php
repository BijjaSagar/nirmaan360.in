<?php

namespace Tests\Feature\ContractorControl;

use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ContractorControl\ContractorTenderService;
use App\Services\ContractorControl\Figures;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The flooring example in section 5: BOQ ₹1,00,000 and three quotes. */
class ContractorTenderTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    private ContractorTenderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site();
        $this->service = app(ContractorTenderService::class);
    }

    private function tender(array $itemIds = []): ContractorTender
    {
        return $this->service->create($this->project, ['title' => 'Flooring', 'boq_item_ids' => $itemIds ?: [$this->item->id]], $this->owner);
    }

    private function quote(ContractorTender $tender, Party $party, array $data): ContractorBid
    {
        return $this->service->addBid($tender, ['party_id' => $party->id, ...$data], $this->owner);
    }

    /** @return array{0: ContractorTender, 1: ContractorBid, 2: ContractorBid, 3: ContractorBid} */
    private function floorExample(): array
    {
        $tender = $this->tender();

        return [
            $tender,
            $this->quote($tender, $this->contractorNamed('Contractor A'), ['quoted_amount' => '110000']),
            $this->quote($tender, $this->contractorNamed('Contractor B'), ['quoted_amount' => '90000']),
            $this->quote($tender, $this->contractorNamed('Contractor C'), ['quoted_amount' => '195000']),
        ];
    }

    private function terms(ContractorBid $bid, array $overrides = []): array
    {
        return [
            'bid_id' => $bid->id, 'retention_pct' => '5', 'tds_pct' => '1', 'gst_pct' => '18',
            'advance_amount' => '0', 'advance_recovery_pct' => '0', 'deduction_basis' => 'gross',
            'deduction_order' => array_key_first(config('buildos.deduction_orders')), ...$overrides,
        ];
    }

    public function test_the_flooring_example_shows_each_quote_against_the_boq(): void
    {
        [$tender] = $this->floorExample();

        $comparison = $this->service->comparison($tender);
        $rows = collect($comparison['bids'])->keyBy('contractor');

        $this->assertSame('100000.00', $comparison['boq_amount']);
        $this->assertSame('90000.00', $comparison['lowest']);

        $this->assertSame('110000.00', $rows['Contractor A']['amount']);
        $this->assertSame('10000.00', $rows['Contractor A']['diff']);
        $this->assertSame('10.00', $rows['Contractor A']['diff_pct']);
        $this->assertSame('+10', Figures::signedPct($rows['Contractor A']['diff_pct']));

        $this->assertSame('-10000.00', $rows['Contractor B']['diff']);
        $this->assertSame('-10.00', $rows['Contractor B']['diff_pct']);
        $this->assertSame('−10', Figures::signedPct($rows['Contractor B']['diff_pct']));
        $this->assertTrue($rows['Contractor B']['lowest']);

        $this->assertSame('95.00', $rows['Contractor C']['diff_pct']);
        $this->assertFalse($rows['Contractor A']['lowest']);
        $this->assertFalse($rows['Contractor C']['lowest']);
    }

    public function test_the_cheapest_quote_comes_first(): void
    {
        [$tender] = $this->floorExample();

        $this->assertSame('Contractor B', $this->service->comparison($tender)['bids'][0]['contractor']);
    }

    public function test_the_manager_can_pick_any_quote_and_it_becomes_a_work_order_at_its_rates(): void
    {
        [$tender, $first] = $this->floorExample();

        $workOrder = $this->service->award($tender, $first, $this->terms($first, ['end_date' => '2026-12-31']), $this->owner);

        $this->assertSame('ACTIVE', $workOrder->status);
        $this->assertSame('110000.00', $workOrder->totalValue());
        $this->assertSame('1100.00', $workOrder->items()->first()->rate);
        $this->assertSame('2026-12-31', $workOrder->end_date->format('Y-m-d'));
        $this->assertSame($first->party_id, $workOrder->party_id);
        $this->assertSame('Flooring', $workOrder->title);
        $this->assertSame('AWARDED', $tender->refresh()->status);
        $this->assertSame($workOrder->id, $tender->work_order_id);
        $this->assertTrue($first->refresh()->is_selected);
    }

    public function test_every_quote_stays_on_record_after_the_award(): void
    {
        [$tender, $first] = $this->floorExample();

        $this->service->award($tender, $first, $this->terms($first), $this->owner);

        $this->assertCount(3, $this->service->comparison($tender->refresh())['bids']);
        $this->assertSame(3, ContractorBid::where('contractor_tender_id', $tender->id)->count());
    }

    public function test_existing_billing_works_on_the_awarded_contract(): void
    {
        [$tender, , $second] = $this->floorExample();
        $workOrder = $this->service->award($tender, $second, $this->terms($second), $this->owner);

        $engineer = $this->supervisor;
        $this->actingAs($engineer);
        $this->approveEntry($this->draftEntry($this->project, $this->item, $engineer, [
            'nos' => '10', 'length' => null, 'breadth' => null, 'depth' => null, 'work_order_id' => $workOrder->id,
        ]), $engineer, $this->owner);

        $bill = app(RaBillService::class)->generate($this->project, 'subcontractor', '2026-11-01', '2026-11-30', $this->owner, $workOrder);

        $this->assertSame('9000.00', $bill->gross_amount);
    }

    public function test_a_quote_with_rates_is_totalled_from_them(): void
    {
        $other = $this->makeBoqItem($this->project, '50.000', '200.00');
        $tender = $this->tender([$this->item->id, $other->id]);
        $tender->load('items');
        $lines = $tender->items->keyBy('boq_item_id');

        $bid = $this->quote($tender, $this->contractorNamed('X'), ['rates' => [$lines[$this->item->id]->id => '950', $lines[$other->id]->id => '180']]);

        $this->assertSame('104000.00', $bid->quoted_amount);
    }

    public function test_one_figure_for_several_items_is_spread_by_their_boq_amounts(): void
    {
        $other = $this->makeBoqItem($this->project, '50.000', '200.00');
        $tender = $this->tender([$this->item->id, $other->id]);

        $bid = $this->quote($tender, $this->contractorNamed('X'), ['quoted_amount' => '99000']);

        $tender->load('items');
        $lines = $tender->items->keyBy('boq_item_id');

        $this->assertSame('900.00', $bid->items->firstWhere('contractor_tender_item_id', $lines[$this->item->id]->id)->rate);
        $this->assertSame('180.00', $bid->items->firstWhere('contractor_tender_item_id', $lines[$other->id]->id)->rate);
    }

    public function test_a_quote_needs_an_amount_or_rates(): void
    {
        $this->expectException(ValidationException::class);

        $this->quote($this->tender(), $this->contractorNamed('X'), []);
    }

    public function test_rates_that_disagree_with_the_quoted_amount_are_refused(): void
    {
        $tender = $this->tender();
        $tender->load('items');

        $this->expectException(ValidationException::class);

        $this->quote($tender, $this->contractorNamed('X'), ['quoted_amount' => '500', 'rates' => [$tender->items[0]->id => '10']]);
    }

    public function test_a_revised_quote_replaces_the_old_one_in_the_comparison_but_the_old_one_is_kept(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Reviser');

        $old = $this->quote($tender, $party, ['quoted_amount' => '95000']);
        $new = $this->quote($tender, $party, ['quoted_amount' => '98000']);

        $comparison = $this->service->comparison($tender);

        $this->assertCount(2, $comparison['bids']);
        $this->assertSame('98000.00', $comparison['lowest']);
        $this->assertSame($new->id, $old->refresh()->superseded_by_id);
        $this->assertFalse(collect($comparison['bids'])->firstWhere('bid.id', $old->id)['lowest']);
    }

    public function test_a_replaced_quote_cannot_be_chosen(): void
    {
        $tender = $this->tender();
        $party = $this->contractorNamed('Reviser');
        $old = $this->quote($tender, $party, ['quoted_amount' => '95000']);
        $this->quote($tender, $party, ['quoted_amount' => '98000']);

        $this->expectException(ValidationException::class);
        $this->service->award($tender, $old, $this->terms($old), $this->owner);
    }

    public function test_two_equal_lowest_quotes_are_both_highlighted(): void
    {
        $tender = $this->tender();
        $this->quote($tender, $this->contractorNamed('A'), ['quoted_amount' => '90000']);
        $this->quote($tender, $this->contractorNamed('B'), ['quoted_amount' => '90000']);

        $this->assertCount(2, collect($this->service->comparison($tender)['bids'])->where('lowest', true));
    }

    public function test_an_awarded_tender_takes_no_more_quotes(): void
    {
        [$tender, $first] = $this->floorExample();
        $this->service->award($tender, $first, $this->terms($first), $this->owner);

        $this->expectException(ValidationException::class);
        $this->quote($tender->refresh(), $this->contractorNamed('Late'), ['quoted_amount' => '1000']);
    }

    public function test_awarding_twice_makes_one_work_order(): void
    {
        [$tender, $first] = $this->floorExample();

        $one = $this->service->award($tender, $first, $this->terms($first), $this->owner);
        $two = $this->service->award($tender, $first->refresh(), $this->terms($first), $this->owner);

        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, WorkOrder::where('project_id', $this->project->id)->count());
    }

    public function test_a_second_winner_is_refused(): void
    {
        [$tender, $first, $second] = $this->floorExample();
        $this->service->award($tender, $first, $this->terms($first), $this->owner);

        $this->expectException(ValidationException::class);
        $this->service->award($tender->refresh(), $second, $this->terms($second), $this->owner);
    }

    public function test_the_boq_is_copied_so_later_changes_do_not_rewrite_the_comparison(): void
    {
        $tender = $this->tender();
        $this->quote($tender, $this->contractorNamed('A'), ['quoted_amount' => '90000']);

        $this->item->update(['rate' => '2000.00']);

        $this->assertSame('100000.00', $this->service->comparison($tender->refresh())['boq_amount']);
    }

    public function test_a_repeated_client_uuid_returns_the_original_quote(): void
    {
        $tender = $this->tender();
        $uuid = (string) Str::uuid();
        $party = $this->contractorNamed('A');

        $one = $this->quote($tender, $party, ['quoted_amount' => '90000', 'client_uuid' => $uuid]);
        $two = $this->quote($tender, $party, ['quoted_amount' => '90000', 'client_uuid' => $uuid]);

        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, ContractorBid::count());
    }

    public function test_a_new_contractor_is_added_as_a_subcontractor(): void
    {
        $tender = $this->tender();

        $bid = $this->service->addBid($tender, ['new_contractor' => 'New Tiles Co', 'quoted_amount' => '90000'], $this->owner);

        $this->assertSame('subcontractor', $bid->party->type);
        $this->assertSame('New Tiles Co', $bid->party->name);
    }

    public function test_cancelling_keeps_the_quotes(): void
    {
        [$tender] = $this->floorExample();

        $this->service->cancel($tender);

        $this->assertSame('CANCELLED', $tender->refresh()->status);
        $this->assertSame(3, ContractorBid::count());
    }

    public function test_the_manager_chooses_through_the_screen(): void
    {
        $this->withoutVite();
        [$tender, , $second] = $this->floorExample();

        $this->actingAs($this->manager)
            ->get(route('contractor-control.tenders.show', $tender))
            ->assertOk()->assertSee('Lowest')->assertSee('−10%')->assertSee('+95%');

        $this->actingAs($this->manager)
            ->post(route('contractor-control.tenders.award', $tender), $this->terms($second))
            ->assertRedirect();

        $this->assertSame('AWARDED', $tender->refresh()->status);
    }

    public function test_a_supervisor_cannot_see_a_tender(): void
    {
        $this->withoutVite();
        [$tender] = $this->floorExample();

        $this->actingAs($this->supervisor)->get(route('contractor-control.tenders.show', $tender))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('contractor-control.tenders.index', $this->project))->assertForbidden();
    }

    public function test_an_estimator_can_add_quotes_but_cannot_choose_the_winner(): void
    {
        [$tender, $first] = $this->floorExample();
        $qs = User::factory()->create(['organization_id' => $this->organization->id, 'role' => 'qs', 'is_active' => true]);

        $this->actingAs($qs)->post(route('contractor-control.tenders.bids.store', $tender), ['party_id' => $first->party_id, 'quoted_amount' => '100'])->assertRedirect();
        $this->actingAs($qs)->post(route('contractor-control.tenders.award', $tender), $this->terms($first))->assertForbidden();
    }
}
