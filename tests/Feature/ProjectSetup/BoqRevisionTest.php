<?php

namespace Tests\Feature\ProjectSetup;

use App\Exceptions\ImmutableRecordException;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\MeasurementEntry;
use App\Models\RaBill;
use App\Models\RaBillItem;
use App\Services\ProjectSetup\BoqRevisions;

class BoqRevisionTest extends ProjectSetupTestCase
{
    private function revisions(?BoqItem $item = null)
    {
        return BoqRevision::withoutGlobalScopes()
            ->when($item, fn ($query) => $query->where('boq_item_id', $item->id))
            ->orderBy('id')->get();
    }

    public function test_adding_a_line_is_recorded_with_who_and_the_value_of_the_boq(): void
    {
        $this->actingAs($this->pm)->post(route('boq.store', $this->project), [
            'code' => '1', 'description' => 'Excavation', 'unit' => 'cum', 'qty' => '10', 'rate' => '200',
        ])->assertSessionHasNoErrors();

        $revision = $this->revisions()->sole();

        $this->assertSame('added', $revision->action);
        $this->assertSame($this->pm->id, $revision->user_id);
        $this->assertSame('1', $revision->item_code);
        $this->assertSame(['Excavation'], [$revision->changes['description'][1]]);
        $this->assertNull($revision->changes['description'][0]);
        $this->assertSame('10.000', $revision->changes['qty'][1]);
        $this->assertSame('0.00', $revision->valuation_before);
        $this->assertSame('2000.00', $revision->valuation_after);
    }

    public function test_editing_a_line_keeps_the_old_and_new_value_the_reason_and_the_valuation_either_side(): void
    {
        $other = $this->line('10.000', '1000.00');
        $item = $this->line('100.000', '5000.00', ['code' => 'R1']);
        BoqRevision::withoutGlobalScopes()->delete();

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => 'R1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '120', 'rate' => '5500',
            'reason' => 'Slab made thicker on the architect\'s revision',
        ])->assertSessionHasNoErrors();

        $revision = $this->revisions($item)->sole();

        $this->assertSame('edited', $revision->action);
        $this->assertSame($this->pm->id, $revision->user_id);
        $this->assertSame(['100.000', '120.000'], $revision->changes['qty']);
        $this->assertSame(['5000.00', '5500.00'], $revision->changes['rate']);
        $this->assertArrayNotHasKey('description', $revision->changes);
        $this->assertSame('Slab made thicker on the architect\'s revision', $revision->reason);
        // 10 × 1,000 + 100 × 5,000 before; 10 × 1,000 + 120 × 5,500 after.
        $this->assertSame('510000.00', $revision->valuation_before);
        $this->assertSame('670000.00', $revision->valuation_after);
        $this->assertNotNull($revision->created_at);
    }

    public function test_an_edit_needs_a_reason(): void
    {
        $item = $this->line();

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => 'X', 'description' => 'd', 'unit' => 'cum', 'qty' => '1', 'rate' => '1',
        ])->assertSessionHasErrors('reason');

        $this->assertSame('100.000', $item->fresh()->qty);
    }

    public function test_removing_a_line_is_recorded_and_the_line_keeps_its_history(): void
    {
        $this->line('10.000', '1000.00');
        $item = $this->line('5.000', '200.00', ['code' => 'DEL']);

        $this->actingAs($this->pm)->delete(route('boq.destroy', $item), ['reason' => 'Not needed'])->assertSessionHasNoErrors();

        $this->assertSoftDeleted($item);
        $revision = $this->revisions($item)->last();
        $this->assertSame('deleted', $revision->action);
        $this->assertSame('Not needed', $revision->reason);
        $this->assertSame(['5.000', null], $revision->changes['qty']);
        $this->assertSame('11000.00', $revision->valuation_before);
        $this->assertSame('10000.00', $revision->valuation_after);

        $this->actingAs($this->pm)->delete(route('boq.destroy', $this->line()), [])->assertSessionHasErrors('reason');
    }

    public function test_a_line_with_measurements_cannot_be_removed(): void
    {
        $item = $this->line();
        $this->draftEntry($this->project, $item, $this->engineer);

        $this->actingAs($this->pm)->delete(route('boq.destroy', $item), ['reason' => 'Oops'])->assertSessionHasErrors('boq');

        $this->assertNotSoftDeleted($item);
    }

    public function test_a_revision_leaves_measured_and_billed_records_exactly_as_they_were(): void
    {
        $item = $this->line('100.000', '5000.00', ['code' => 'RCC1']);
        $entry = $this->approveEntry($this->draftEntry($this->project, $item, $this->engineer), $this->engineer, $this->owner);
        $billItem = RaBillItem::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'ra_bill_id' => $this->makeBill()->id,
            'boq_item_id' => $item->id,
            'code' => 'RCC1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'rate' => '5000.00',
            'prev_qty' => '0', 'current_qty' => '50', 'cumulative_qty' => '50',
            'prev_amount' => '0', 'current_amount' => '250000.00', 'cumulative_amount' => '250000.00',
        ]);

        $measuredBefore = MeasurementEntry::withoutGlobalScopes()->find($entry->id)->getAttributes();
        $billedBefore = RaBillItem::withoutGlobalScopes()->find($billItem->id)->getAttributes();

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => 'RCC1', 'description' => 'RCC M30 in slab', 'unit' => 'cum', 'qty' => '150', 'rate' => '9000', 'reason' => 'Grade raised to M30',
        ])->assertSessionHasNoErrors();

        $this->assertSame('150.000', $item->fresh()->qty);
        $this->assertSame('9000.00', $item->fresh()->rate);
        $this->assertSame($measuredBefore, MeasurementEntry::withoutGlobalScopes()->find($entry->id)->getAttributes());
        $this->assertSame($billedBefore, RaBillItem::withoutGlobalScopes()->find($billItem->id)->getAttributes());
        $this->assertSame('5000.00', $billItem->fresh()->rate);
        $this->assertSame('250000.00', $billItem->fresh()->current_amount);
    }

    public function test_quantity_still_cannot_go_below_what_is_approved_and_the_unit_is_locked_once_measured(): void
    {
        $item = $this->line('100.000', '5000.00');
        $entry = $this->approveEntry($this->draftEntry($this->project, $item, $this->engineer), $this->engineer, $this->owner);
        $approved = $entry->qty;
        $before = $this->revisions()->count();

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => $item->code, 'description' => 'd', 'unit' => 'cum', 'qty' => '10', 'rate' => '5000', 'reason' => 'Cut back',
        ])->assertSessionHasErrors('qty');

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => $item->code, 'description' => 'd', 'unit' => 'sqm', 'qty' => $approved, 'rate' => '5000', 'reason' => 'Wrong unit',
        ])->assertSessionHasErrors('unit');

        $this->assertSame($before, $this->revisions()->count());
        $this->assertSame('cum', $item->fresh()->unit);
    }

    public function test_writing_the_same_values_again_records_nothing(): void
    {
        $item = $this->line('100.000', '5000.00');
        $before = $this->revisions()->count();

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => $item->code, 'description' => $item->description, 'unit' => 'cum', 'qty' => '100', 'rate' => '5000', 'reason' => 'No change',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->revisions()->count());
    }

    public function test_history_cannot_be_edited_or_deleted(): void
    {
        $this->line();
        $revision = BoqRevision::withoutGlobalScopes()->firstOrFail();

        $this->expectException(ImmutableRecordException::class);
        $revision->update(['reason' => 'rewritten']);
    }

    public function test_history_cannot_be_deleted(): void
    {
        $this->line();
        $revision = BoqRevision::withoutGlobalScopes()->firstOrFail();

        $this->expectException(ImmutableRecordException::class);
        $revision->delete();
    }

    public function test_a_batch_shares_one_id_and_carries_the_valuation_along(): void
    {
        BoqRevisions::with('Bulk', 'excel_import', function () {
            $this->line('10.000', '100.00');
            $this->line('20.000', '100.00');
            $this->line('30.000', '100.00');
        }, batch: true);

        $rows = $this->revisions();

        $this->assertCount(3, $rows);
        $this->assertCount(1, $rows->pluck('batch_uuid')->unique());
        $this->assertNotNull($rows[0]->batch_uuid);
        $this->assertSame(['0.00', '1000.00', '3000.00'], $rows->pluck('valuation_before')->all());
        $this->assertSame(['1000.00', '3000.00', '6000.00'], $rows->pluck('valuation_after')->all());
        $this->assertSame('Bulk', $rows[2]->reason);
        $this->assertSame('excel_import', $rows[2]->source);
    }

    public function test_a_failed_change_leaves_no_context_behind(): void
    {
        try {
            BoqRevisions::with('First', 'edit', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->line();

        $this->assertNull($this->revisions()->sole()->reason);
    }

    public function test_the_valuation_adds_up_rounded_line_amounts(): void
    {
        // 33.333 × 0.10 = 3.3333, which is 3.33 as a line amount.
        $this->line('33.333', '0.10');
        $this->line('33.333', '0.10');

        $this->assertSame('6.66', BoqRevisions::valuation($this->project->id));
        $this->assertSame('6.66', $this->revisions()->last()->valuation_after);
    }

    public function test_history_screen_shows_everything_to_someone_with_money_view(): void
    {
        $item = $this->line('100.000', '5000.00', ['code' => 'R1']);
        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => 'R1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '120', 'rate' => '5500', 'reason' => 'Thicker slab',
        ]);

        $this->actingAs($this->pm)->get(route('project-setup.history', $this->project))
            ->assertOk()
            ->assertSee('Thicker slab')
            ->assertSee($this->pm->name)
            ->assertSee('120.000')
            ->assertSee('5,500.00')
            ->assertSee('Value of the BOQ')
            ->assertSee('₹6,60,000.00', false);
    }

    public function test_history_screen_hides_rates_and_valuations_from_someone_without_money_view(): void
    {
        $item = $this->line('100.000', '5000.00', ['code' => 'R1']);
        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => 'R1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '120', 'rate' => '5500', 'reason' => 'Thicker slab',
        ]);

        $this->actingAs($this->engineer)->get(route('project-setup.history', $this->project))
            ->assertOk()
            ->assertSee('Thicker slab')
            ->assertSee('120.000')
            ->assertDontSee('5,500.00')
            ->assertDontSee('5,000.00')
            ->assertDontSee('Value of the BOQ')
            ->assertSee('Money figures are not shown to you.');
    }

    public function test_history_can_be_limited_to_one_item_and_is_paged(): void
    {
        $a = $this->line('1.000', '1.00', ['code' => 'AAA']);
        $this->line('1.000', '1.00', ['code' => 'BBB']);

        $this->actingAs($this->pm)->get(route('project-setup.history', [$this->project, 'item' => $a->id]))
            ->assertOk()->assertSee('AAA')->assertDontSee('BBB');

        foreach (range(1, 32) as $number) {
            $this->line('1.000', '1.00', ['code' => 'N'.$number]);
        }

        $this->actingAs($this->pm)->get(route('project-setup.history', $this->project))->assertOk()->assertSee('page=2', false);
    }

    public function test_history_is_private_to_the_company_and_to_the_projects_a_person_may_see(): void
    {
        $this->line('1.000', '1.00', ['code' => 'MINE']);
        [, $rivalOwner, $rivalProject] = $this->rival();
        $this->line('1.000', '1.00', ['code' => 'THEIRS'], $rivalProject);

        $this->actingAs($rivalOwner)->get(route('project-setup.history', $this->project))->assertNotFound();
        $this->actingAs($rivalOwner)->get(route('project-setup.history', $rivalProject))->assertOk()->assertSee('THEIRS')->assertDontSee('MINE');
    }

    public function test_a_line_created_with_nobody_signed_in_is_still_recorded(): void
    {
        $this->line('1.000', '1.00');

        $this->assertSame('added', $this->revisions()->sole()->action);
        $this->assertNull($this->revisions()->sole()->user_id);
    }

    private function makeBill()
    {
        return RaBill::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'type' => 'client',
            'bill_no' => 1, 'number' => 'SH1/RA/1', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'status' => 'DRAFT',
        ]);
    }
}
