<?php

namespace Tests\Feature\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Rfi;
use App\Services\ApprovalService;
use App\Services\Qhs\RfiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class RfiTest extends FieldOpsTestCase
{
    private function draft(object $w, array $data = []): Rfi
    {
        return app(RfiService::class)->create($w->project, [
            'subject' => 'Beam depth at gridline B', 'question' => 'Drawing S-12 shows 450 mm, S-14 shows 500 mm. Which governs?', 'priority' => 'high', ...$data,
        ], $w->owner);
    }

    private function approve(Rfi $rfi, $user): void
    {
        $approval = $rfi->approvals()->first();
        app(ApprovalService::class)->act($approval, $user, 'approve');
    }

    public function test_drafts_are_numbered_per_project(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $a = $this->draft($w);
        $b = $this->draft($w);

        $this->assertSame('RFI-'.$w->project->code.'-001', $a->number);
        $this->assertSame('RFI-'.$w->project->code.'-002', $b->number);
        $this->assertSame('DRAFT', $a->status);
        $this->assertSame('draft', $a->stage());
    }

    public function test_the_same_client_uuid_drafts_one_rfi(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $payload = ['subject' => 'Sleeve at slab', 'question' => 'Where?', 'priority' => 'low', 'client_uuid' => (string) Str::uuid()];

        $this->post(route('rfis.store', $w->project), $payload)->assertRedirect();
        $this->post(route('rfis.store', $w->project), $payload)->assertRedirect();

        $this->assertSame(1, Rfi::count());
    }

    public function test_submitting_sends_it_to_the_project_manager_by_default(): void
    {
        $w = $this->world('engineer');
        $this->actingAs($w->user);
        $rfi = app(RfiService::class)->create($w->project, ['subject' => 'x', 'question' => 'y', 'priority' => 'medium'], $w->user);

        $this->post(route('rfis.submit', $rfi))->assertRedirect()->assertSessionHas('success');

        $rfi->refresh();
        $approval = $rfi->approvals()->first();
        $this->assertSame('IN_REVIEW', $rfi->status);
        $this->assertSame('in_review', $rfi->stage());
        $this->assertSame(['pm'], $approval->levels);
        $this->assertSame('rfi', $approval->doc_type);
    }

    public function test_a_company_rule_for_rfis_replaces_the_default_chain(): void
    {
        $w = $this->world();
        ApprovalRule::create(['organization_id' => $w->organization->id, 'doc_type' => 'rfi', 'levels' => ['qs', 'pm'], 'active' => true]);
        $this->actingAs($w->owner);

        $rfi = $this->draft($w);
        app(RfiService::class)->submit($rfi, $w->owner);

        $this->assertSame(['qs', 'pm'], $rfi->approvals()->first()->levels);
    }

    public function test_the_project_manager_approves_and_the_rfi_goes_out(): void
    {
        $w = $this->world('engineer');
        $pm = $this->member($w->organization, 'pm');
        $this->actingAs($w->user);
        $rfi = app(RfiService::class)->create($w->project, ['subject' => 'x', 'question' => 'y', 'priority' => 'medium'], $w->user);
        $this->post(route('rfis.submit', $rfi));

        // The engineer who raised it cannot approve it, and has no right to approve RFIs at all.
        $approval = $rfi->approvals()->first();
        $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertForbidden();

        Auth::forgetUser();
        $this->actingAs($pm);
        $this->get(route('approvals.index'))->assertOk()->assertSee($rfi->number)->assertSee(route('rfis.show', $rfi), false);
        $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertRedirect();

        $rfi->refresh();
        $this->assertSame('APPROVED', $rfi->status);
        $this->assertSame('issued', $rfi->stage());
    }

    public function test_an_rfi_in_review_shows_in_the_mobile_approval_inbox_and_can_be_approved_there(): void
    {
        $w = $this->world('engineer');
        $pm = $this->member($w->organization, 'pm');
        $this->actingAs($w->user);
        $rfi = app(RfiService::class)->create($w->project, ['subject' => 'Slab edge detail', 'question' => 'Which detail applies?', 'priority' => 'medium'], $w->user);
        app(RfiService::class)->submit($rfi, $w->user);
        $approval = $rfi->approvals()->first();

        Sanctum::actingAs($pm);
        $this->getJson('/api/v1/approvals')->assertOk()
            ->assertJsonPath('data.0.doc_type', 'rfi')
            ->assertJsonPath('data.0.title', $rfi->number.' · Slab edge detail')
            ->assertJsonPath('data.0.can_act', true);

        $this->postJson("/api/v1/approvals/{$approval->id}/act", ['action' => 'approve'])->assertOk();
        $this->assertSame('APPROVED', $rfi->refresh()->status);

        Sanctum::actingAs($w->user);
        $this->postJson("/api/v1/approvals/{$approval->id}/act", ['action' => 'approve'])->assertStatus(403);
    }

    public function test_returned_with_remarks_it_can_be_edited_and_sent_again(): void
    {
        $w = $this->world('engineer');
        $pm = $this->member($w->organization, 'pm');
        $this->actingAs($w->user);
        $rfi = app(RfiService::class)->create($w->project, ['subject' => 'x', 'question' => 'unclear', 'priority' => 'medium'], $w->user);
        $this->post(route('rfis.submit', $rfi));

        Auth::forgetUser();
        $this->actingAs($pm);
        $this->post(route('approvals.act', $rfi->approvals()->first()), ['action' => 'return', 'remark' => 'Quote the drawing numbers'])->assertRedirect();

        Auth::forgetUser();
        $this->actingAs($w->user);
        $this->get(route('rfis.show', $rfi))->assertOk()->assertSee('Quote the drawing numbers')->assertSee('Returned');
        $this->patch(route('rfis.update', $rfi), ['subject' => 'x', 'question' => 'Drawings S-12 and S-14 disagree', 'priority' => 'medium'])->assertRedirect(route('rfis.show', $rfi));
        $this->post(route('rfis.submit', $rfi))->assertRedirect();

        $rfi->refresh();
        $this->assertSame('IN_REVIEW', $rfi->status);
        $this->assertSame('Drawings S-12 and S-14 disagree', $rfi->question);
        $this->assertSame(2, $rfi->approvals()->count());
    }

    public function test_an_rfi_under_review_cannot_be_edited(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $rfi = $this->draft($w);
        app(RfiService::class)->submit($rfi, $w->owner);

        $this->patch(route('rfis.update', $rfi), ['subject' => 'Changed', 'question' => 'Changed', 'priority' => 'low'])->assertStatus(409);
        $this->get(route('rfis.edit', $rfi))->assertStatus(409);
        $this->post(route('rfis.submit', $rfi))->assertSessionHasErrors('status');
        $this->assertSame('Beam depth at gridline B', $rfi->refresh()->subject);
    }

    public function test_the_answer_is_recorded_after_approval_and_the_question_stays_locked(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $rfi = $this->draft($w, ['addressed_to' => 'Structural consultant']);

        $this->post(route('rfis.respond', $rfi), ['response' => 'Too early', 'responded_on' => today()->toDateString()])->assertSessionHasErrors('status');

        app(RfiService::class)->submit($rfi, $w->owner);
        $this->approve($rfi, $w->owner);
        $this->assertSame('APPROVED', $rfi->refresh()->status);

        $this->post(route('rfis.respond', $rfi), ['response' => 'S-14 governs: 500 mm.', 'responded_on' => today()->toDateString(), 'responded_by' => 'Mr Kulkarni', 'cost_impact' => 1, 'time_impact_days' => 3])->assertSessionHasNoErrors();

        $rfi->refresh();
        $this->assertSame('answered', $rfi->stage());
        $this->assertSame('S-14 governs: 500 mm.', $rfi->response);
        $this->assertTrue($rfi->cost_impact);
        $this->assertSame(3, $rfi->time_impact_days);

        // The approved question cannot change.
        $this->patch(route('rfis.update', $rfi), ['subject' => 'Changed', 'question' => 'Changed', 'priority' => 'low'])->assertStatus(409);
        try {
            $rfi->update(['question' => 'Rewritten history']);
            $this->fail('The model must refuse too.');
        } catch (ImmutableRecordException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_closing_needs_an_answer_and_then_locks_the_answer(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $rfi = $this->draft($w);
        app(RfiService::class)->submit($rfi, $w->owner);
        $this->approve($rfi, $w->owner);

        $this->post(route('rfis.close', $rfi))->assertSessionHasErrors('response');
        $this->post(route('rfis.respond', $rfi), ['response' => 'Use 500 mm', 'responded_on' => today()->toDateString()]);
        $this->post(route('rfis.close', $rfi))->assertSessionHasNoErrors();

        $rfi->refresh();
        $this->assertSame('closed', $rfi->stage());
        $this->assertSame($w->owner->id, $rfi->closed_by);
        $this->post(route('rfis.respond', $rfi), ['response' => 'Changed', 'responded_on' => today()->toDateString()])->assertStatus(409);
        $this->assertSame('Use 500 mm', $rfi->refresh()->response);
    }

    public function test_an_answer_cannot_be_dated_before_the_question(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $rfi = $this->draft($w);
        app(RfiService::class)->submit($rfi, $w->owner);
        $this->approve($rfi, $w->owner);

        $this->post(route('rfis.respond', $rfi), ['response' => 'x', 'responded_on' => today()->subDays(3)->toDateString()])->assertSessionHasErrors('responded_on');
    }

    public function test_an_unanswered_rfi_past_its_date_is_overdue(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $rfi = $this->draft($w, ['required_by' => today()->subDays(2)->toDateString()]);
        $this->assertFalse($rfi->isOverdue(), 'A draft has not gone out yet.');

        app(RfiService::class)->submit($rfi, $w->owner);
        $this->approve($rfi, $w->owner);

        $this->assertTrue($rfi->refresh()->isOverdue());
        $this->get(route('rfis.index', $w->project))->assertOk()->assertSee('text-laterite', false);
    }

    public function test_input_is_checked(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->post(route('rfis.store', $w->project), ['priority' => 'urgent'])->assertSessionHasErrors(['subject', 'question', 'priority']);
        $this->assertSame(0, Rfi::count());
    }

    public function test_who_can_raise_approve_and_answer(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $rfi = $this->draft($w);
        $payload = ['subject' => 'x', 'question' => 'y', 'priority' => 'low'];

        foreach (['pm' => true, 'engineer' => true, 'qs' => false, 'viewer' => false] as $role => $canWrite) {
            Auth::forgetUser();
            $this->actingAs($this->member($w->organization, $role));

            $this->get(route('rfis.index', $w->project))->assertOk();
            $this->get(route('rfis.show', $rfi))->assertOk();
            $this->post(route('rfis.store', $w->project), $payload)->assertStatus($canWrite ? 302 : 403);
            $this->get(route('rfis.create', $w->project))->assertStatus($canWrite ? 200 : 403);
            $this->patch(route('rfis.update', $rfi), $payload)->assertStatus($canWrite ? 302 : 403);
        }

        foreach (['store', 'accounts'] as $role) {
            Auth::forgetUser();
            $this->actingAs($this->member($w->organization, $role))->get(route('rfis.index', $w->project))->assertForbidden();
        }
    }

    public function test_only_pm_owner_and_admin_may_approve_rfis(): void
    {
        $w = $this->world();

        foreach (['pm' => true, 'owner' => true, 'admin' => true, 'engineer' => false, 'qs' => false, 'accounts' => false, 'viewer' => false] as $role => $may) {
            $this->assertSame($may, $this->member($w->organization, $role)->can('rfi.approve'), $role);
        }
    }

    public function test_another_companys_rfis_are_out_of_reach(): void
    {
        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirRfi = $this->draft($theirs);
        app(RfiService::class)->submit($theirRfi, $theirs->owner);
        $approval = $theirRfi->approvals()->first();

        Auth::forgetUser();
        $this->actingAs($mine->owner);

        $this->get(route('rfis.show', $theirRfi))->assertNotFound();
        $this->get(route('rfis.edit', $theirRfi))->assertNotFound();
        $this->post(route('rfis.submit', $theirRfi))->assertNotFound();
        $this->post(route('rfis.respond', $theirRfi), ['response' => 'x', 'responded_on' => today()->toDateString()])->assertNotFound();
        $this->post(route('rfis.close', $theirRfi))->assertNotFound();
        $this->get(route('rfis.index', $theirs->project))->assertNotFound();
        $this->post(route('rfis.store', $theirs->project), ['subject' => 'x', 'question' => 'y', 'priority' => 'low'])->assertNotFound();
        $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertNotFound();
        $this->assertSame('IN_REVIEW', Rfi::withoutGlobalScopes()->find($theirRfi->id)->status);
        $this->assertSame('IN_REVIEW', Approval::withoutGlobalScopes()->find($approval->id)->status);
    }
}
