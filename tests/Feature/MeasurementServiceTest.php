<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

class MeasurementServiceTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    public function test_quantity_is_nos_times_dimensions_and_reduces_the_balance(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);

        $entry = $this->draftEntry($project, $item, $engineer);

        $this->assertSame('50.000', $entry->qty);
        $this->assertSame('50.000', BoqItem::withLedger()->find($item->id)->balanceQty());
    }

    public function test_deductions_are_negative_and_blank_dimensions_are_skipped(): void
    {
        $this->assertSame('-12.000', MeasurementEntry::computeQty('2', '3', '2', null, true));
        $this->assertSame('5.000', MeasurementEntry::computeQty('5', null, null, null));
    }

    public function test_same_client_uuid_creates_one_row(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $uuid = (string) Str::uuid();

        $first = $this->draftEntry($project, $item, $engineer, ['client_uuid' => $uuid]);
        $second = $this->draftEntry($project, $item, $engineer, ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MeasurementEntry::count());
    }

    public function test_return_with_remark_notifies_the_engineer_and_is_audited(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $qs = $this->member($organization, 'qs');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $entry = $this->draftEntry($project, $this->makeBoqItem($project), $engineer);

        $approval = app(MeasurementService::class)->submit($entry, $engineer);
        $this->assertSame('IN_REVIEW', $entry->refresh()->status);

        $this->actingAs($qs);
        $approval = app(ApprovalService::class)->act($approval, $qs, 'return', 'Depth does not match the pour card');

        $this->assertSame('RETURNED', $approval->status);
        $this->assertSame('RETURNED', $entry->refresh()->status);
        $this->assertCount(1, $engineer->notifications);
        $this->assertStringContainsString('Returned', $engineer->notifications->first()->data['title']);

        $actions = AuditLog::where('auditable_id', $entry->id)->pluck('action');
        $this->assertContains('approval.submit', $actions);
        $this->assertContains('approval.return', $actions);
    }

    public function test_return_needs_a_remark(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $qs = $this->member($organization, 'qs');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $approval = app(MeasurementService::class)->submit($this->draftEntry($project, $this->makeBoqItem($project), $engineer), $engineer);

        $this->expectException(ValidationException::class);
        app(ApprovalService::class)->act($approval, $qs, 'return', '');
    }

    public function test_levels_are_taken_in_order_and_only_the_right_role_can_act(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $qs = $this->member($organization, 'qs');
        $pm = $this->member($organization, 'pm');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $entry = $this->draftEntry($project, $this->makeBoqItem($project), $engineer);
        $service = app(ApprovalService::class);

        $approval = app(MeasurementService::class)->submit($entry, $engineer);

        $this->assertFalse($service->canAct($approval, $pm), 'PM must wait for QS');
        $this->assertFalse($service->canAct($approval, $engineer), 'Engineer cannot approve');
        $this->assertTrue($service->canAct($approval, $qs));

        $approval = $service->act($approval, $qs, 'approve');
        $this->assertSame(2, $approval->current_level);
        $this->assertSame('IN_REVIEW', $entry->refresh()->status);
        $this->assertFalse($service->canAct($approval, $qs), 'QS has already acted');

        $approval = $service->act($approval, $pm, 'approve');
        $this->assertSame('APPROVED', $approval->status);
        $this->assertSame('APPROVED', $entry->refresh()->status);
    }

    public function test_submitter_cannot_approve_their_own_entry(): void
    {
        $organization = $this->tenant();
        $qs = $this->member($organization, 'qs');
        $this->actingAs($qs);
        $project = $this->makeProject($organization);
        $entry = $this->draftEntry($project, $this->makeBoqItem($project), $qs);

        $approval = app(MeasurementService::class)->submit($entry, $qs);

        $this->assertFalse(app(ApprovalService::class)->canAct($approval, $qs));
    }

    public function test_quantity_beyond_tolerance_is_flagged_and_needs_the_pm(): void
    {
        $organization = $this->tenant();
        ApprovalRule::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('doc_type', 'measurement')->update(['levels' => json_encode(['qs'])]);
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);

        // 100 of 100 approved. The 5% tolerance allows up to 105.
        $this->approveEntry($this->draftEntry($project, $item, $engineer, ['nos' => '1', 'length' => '100', 'breadth' => null, 'depth' => null]), $engineer, $owner);

        $within = $this->draftEntry($project, $item, $engineer, ['nos' => '1', 'length' => '5', 'breadth' => null, 'depth' => null]);
        $this->assertFalse(app(MeasurementService::class)->isDeviation($within));

        $over = $this->draftEntry($project, $item, $engineer, ['nos' => '1', 'length' => '6', 'breadth' => null, 'depth' => null]);
        $approval = app(MeasurementService::class)->submit($over, $engineer);

        $this->assertTrue($over->refresh()->deviation);
        $this->assertSame(['qs', 'pm'], $approval->levels);
    }

    public function test_approved_entries_cannot_change_or_be_deleted(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $entry = $this->approveEntry($this->draftEntry($project, $this->makeBoqItem($project), $engineer), $engineer, $owner);

        try {
            $entry->update(['nos' => '3']);
            $this->fail('Approved entry was changed');
        } catch (ImmutableRecordException) {
            $this->assertSame('2.000', $entry->fresh()->nos);
        }

        $this->expectException(ImmutableRecordException::class);
        $entry->fresh()->delete();
    }

    public function test_reversal_is_a_new_opposite_entry_that_needs_approval(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $entry = $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $reversal = app(MeasurementService::class)->reverse($entry, $engineer, 'Wrong grid');

        $this->assertSame('-50.000', $reversal->qty);
        $this->assertSame('DRAFT', $reversal->status);
        $this->assertEquals(50, BoqItem::withLedger()->find($item->id)->approved_qty + 0, 'A draft reversal must not change the approved quantity');

        $this->approveEntry($reversal, $engineer, $owner);
        $this->assertEquals(0, BoqItem::withLedger()->find($item->id)->approved_qty + 0);

        $this->expectException(ValidationException::class);
        app(MeasurementService::class)->reverse($entry->refresh(), $engineer);
    }
}
