<?php

namespace Tests\Feature\Qhs;

use App\Models\Rfi;
use App\Services\Qhs\RfiService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\FieldOps\FieldOpsTestCase;

/** An RFI in the phone's approvals inbox: GET /approvals and POST /approvals/{id}/act. */
class RfiApprovalApiTest extends FieldOpsTestCase
{
    private function waiting(object $w): Rfi
    {
        $rfi = app(RfiService::class)->create($w->project, [
            'subject' => 'Beam depth at gridline B',
            'question' => 'Drawing S-12 shows 450 mm, S-14 shows 500 mm. Which governs?',
            'drawing_ref' => 'S-12 / S-14',
            'location' => 'Level 6, grid B',
            'addressed_to' => 'Structural consultant',
            'priority' => 'high',
            'required_by' => '2026-12-01',
        ], $w->user);
        app(RfiService::class)->submit($rfi, $w->user);

        return $rfi->refresh();
    }

    public function test_the_project_manager_reads_the_question_before_it_goes_out(): void
    {
        $w = $this->world('engineer');
        $rfi = $this->waiting($w);
        Sanctum::actingAs($this->member($w->organization, 'pm'));

        $row = $this->getJson('/api/v1/approvals')->assertOk()->json('data.0');

        $this->assertSame('rfi', $row['doc_type']);
        $this->assertSame([
            'id' => $rfi->id,
            'number' => $rfi->number,
            'subject' => 'Beam depth at gridline B',
            'question' => 'Drawing S-12 shows 450 mm, S-14 shows 500 mm. Which governs?',
            'drawing_ref' => 'S-12 / S-14',
            'location' => 'Level 6, grid B',
            'addressed_to' => 'Structural consultant',
            'priority' => 'high',
            'required_by' => '2026-12-01',
            'raised_by_name' => $w->user->name,
        ], $row['document']);
    }

    public function test_the_manager_approves_it_from_the_phone(): void
    {
        $w = $this->world('engineer');
        $rfi = $this->waiting($w);
        Sanctum::actingAs($this->member($w->organization, 'pm'));

        $this->postJson('/api/v1/approvals/'.$rfi->currentApproval->id.'/act', ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->assertSame('issued', $rfi->fresh()->stage());
    }

    public function test_another_company_never_sees_the_rfi(): void
    {
        $w = $this->world('engineer');
        $approvalId = $this->waiting($w)->currentApproval->id;
        Sanctum::actingAs($this->member($this->tenant('Other Co', 'business'), 'owner'));

        $this->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/approvals/'.$approvalId.'/act', ['action' => 'approve'])->assertNotFound();
    }
}
