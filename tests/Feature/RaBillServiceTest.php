<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\MeasurementEntry;
use App\Models\Party;
use App\Models\RaBill;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

class RaBillServiceTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private function setUpProject(): array
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);

        $project = $this->makeProject($organization, [
            'retention_pct' => '5',
            'tds_pct' => '2',
            'gst_pct' => '18',
            'advance_amount' => '100000',
            'advance_recovery_pct' => '10',
        ]);

        return [$project, $this->makeBoqItem($project), $engineer, $owner];
    }

    private function generate($project, $user): RaBill
    {
        return app(RaBillService::class)->generate($project, 'client', '2026-11-01', '2026-11-30', $user);
    }

    public function test_bill_totals_match_a_worked_example(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $bill = $this->generate($project, $owner);

        $this->assertSame('250000.00', $bill->gross_amount);
        $this->assertSame('42500.00', $bill->deduction_total);
        $this->assertSame('45000.00', $bill->gst_amount);
        $this->assertSame('252500.00', $bill->net_payable);
        $this->assertSame('RA-'.$project->code.'-001', $bill->number);
    }

    public function test_bill_can_leave_out_gst_without_changing_the_contract(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $bill = app(RaBillService::class)->generate($project, 'client', '2026-11-01', '2026-11-30', $owner, withGst: false);

        $this->assertSame('250000.00', $bill->gross_amount);
        $this->assertSame('42500.00', $bill->deduction_total);
        $this->assertSame('0.00', $bill->gst_amount);
        $this->assertSame('207500.00', $bill->net_payable);
        $this->assertFalse($bill->hasGst());
        $this->assertSame('18.00', $project->fresh()->gst_pct);
    }

    public function test_user_picks_with_or_without_gst_on_the_bill_form(): void
    {
        $this->withoutVite();
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);
        $this->actingAs($owner);
        $form = ['type' => 'client', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'];

        $this->get(route('ra-bills.create', $project))->assertOk()->assertSee('With GST')->assertSee('Without GST');
        $this->post(route('ra-bills.store', $project), [...$form, 'gst' => 'perhaps'])->assertSessionHasErrors('gst');
        $this->post(route('ra-bills.store', $project), [...$form, 'gst' => 'without'])->assertSessionHasNoErrors();

        $bill = RaBill::latest('id')->firstOrFail();
        $this->assertSame('0.00', $bill->gst_amount);
        $this->get(route('ra-bills.show', $bill))->assertOk()->assertSee('Not charged');
        $this->get(route('ra-bills.pdf', $bill))->assertOk();
    }

    public function test_next_bill_carries_previous_and_cumulative_figures(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);
        $this->generate($project, $owner);

        // 1 × 10 × 1 = 10 more
        $this->approveEntry($this->draftEntry($project, $item, $engineer, ['nos' => '1', 'length' => '10', 'breadth' => '1', 'depth' => null]), $engineer, $owner);
        $second = $this->generate($project, $owner);
        $line = $second->items()->first();

        $this->assertSame(2, $second->bill_no);
        $this->assertSame('50.000', $line->prev_qty);
        $this->assertSame('10.000', $line->current_qty);
        $this->assertSame('60.000', $line->cumulative_qty);
        $this->assertSame('250000.00', $line->prev_amount);
        $this->assertSame('50000.00', $line->current_amount);
        $this->assertSame('300000.00', $line->cumulative_amount);
        $this->assertSame('5000.00', $second->deductions()->where('type', 'advance')->value('amount'));
    }

    public function test_a_measurement_cannot_be_billed_twice(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);
        $this->generate($project, $owner);

        $this->expectException(ValidationException::class);
        $this->generate($project, $owner);
    }

    public function test_only_approved_measurements_are_billed(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->draftEntry($project, $item, $engineer);

        $this->expectException(ValidationException::class);
        $this->generate($project, $owner);
    }

    public function test_cancelling_a_bill_frees_its_measurements(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $entry = $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);
        $bill = $this->generate($project, $owner);
        $this->assertSame($bill->id, $entry->refresh()->billed_client_bill_id);

        app(RaBillService::class)->cancel($bill);
        $this->assertNull($entry->refresh()->billed_client_bill_id);

        $replacement = $this->generate($project, $owner);
        $this->assertSame(2, $replacement->bill_no);
        $this->assertSame('0.000', $replacement->items()->first()->prev_qty);
    }

    public function test_approved_bill_is_locked(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);
        $bill = $this->generate($project, $owner);

        $approval = app(ApprovalService::class)->submit($bill, $owner);
        while ($approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $owner, 'approve');
        }

        $this->assertSame('APPROVED', $bill->refresh()->status);
        $this->assertNotNull($bill->approved_at);

        $this->expectException(ImmutableRecordException::class);
        $bill->update(['gross_amount' => '1.00']);
    }

    public function test_subcontractor_bill_uses_work_order_rates_and_terms(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $party = Party::create(['organization_id' => $project->organization_id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $workOrder = WorkOrder::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'number' => 'WO-1',
            'title' => 'Slab casting',
            'retention_pct' => '10',
            'tds_pct' => '1',
            'gst_pct' => '0',
        ]);
        $workOrder->items()->create(['organization_id' => $project->organization_id, 'boq_item_id' => $item->id, 'qty' => '100', 'rate' => '1200']);

        $this->approveEntry($this->draftEntry($project, $item, $engineer, ['work_order_id' => $workOrder->id]), $engineer, $owner);

        $bill = app(RaBillService::class)->generate($project, 'subcontractor', '2026-11-01', '2026-11-30', $owner, $workOrder);

        // 50 × 1,200 = 60,000. Retention 10% = 6,000. TDS 1% = 600.
        $this->assertSame('60000.00', $bill->gross_amount);
        $this->assertSame('53400.00', $bill->net_payable);
        $this->assertSame($party->id, $bill->party_id);
        $this->assertSame($bill->id, MeasurementEntry::first()->billed_sub_bill_id);
        $this->assertNull(MeasurementEntry::first()->billed_client_bill_id);
    }

    public function test_work_order_must_have_a_rate_for_every_billed_item(): void
    {
        [$project, $item, $engineer, $owner] = $this->setUpProject();
        $party = Party::create(['organization_id' => $project->organization_id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $workOrder = WorkOrder::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'party_id' => $party->id, 'number' => 'WO-2', 'title' => 'No rates']);

        $this->approveEntry($this->draftEntry($project, $item, $engineer, ['work_order_id' => $workOrder->id]), $engineer, $owner);

        $this->expectException(ValidationException::class);
        app(RaBillService::class)->generate($project, 'subcontractor', '2026-11-01', '2026-11-30', $owner, $workOrder);
    }
}
