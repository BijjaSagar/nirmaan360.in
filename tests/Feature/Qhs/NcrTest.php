<?php

namespace Tests\Feature\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\Ncr;
use App\Models\Site;
use App\Services\Qhs\NcrService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class NcrTest extends FieldOpsTestCase
{
    private function raise(object $w, array $data = []): Ncr
    {
        return app(NcrService::class)->create($w->project, ['title' => 'Honeycombing at C7', 'category' => 'workmanship', 'severity' => 'major', ...$data], $w->owner);
    }

    private function investigate(Ncr $ncr): void
    {
        $this->patch(route('ncrs.update', $ncr), ['root_cause' => 'Vibrator not used on the lift', 'root_cause_category' => 'method', 'corrective_action' => 'Chipped and repaired with polymer mortar'])->assertSessionHasNoErrors();
    }

    public function test_numbers_run_per_project(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $other = $this->projectOf($w->organization);

        $a = $this->raise($w);
        $b = $this->raise($w);
        $c = app(NcrService::class)->create($other, ['title' => 'x', 'category' => 'material', 'severity' => 'minor'], $w->owner);

        $this->assertSame('NCR-'.$w->project->code.'-001', $a->number);
        $this->assertSame('NCR-'.$w->project->code.'-002', $b->number);
        $this->assertSame('NCR-'.$other->code.'-001', $c->number);
        $this->assertSame('open', $a->status);
        $this->assertSame('0.00', $a->rework_cost);
        $this->assertSame($w->owner->id, $a->raised_by);
    }

    public function test_the_same_client_uuid_raises_one_ncr(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $uuid = (string) Str::uuid();

        $this->post(route('ncrs.store', $w->project), ['title' => 'Crack', 'category' => 'material', 'severity' => 'minor', 'client_uuid' => $uuid])->assertRedirect();
        $this->post(route('ncrs.store', $w->project), ['title' => 'Crack', 'category' => 'material', 'severity' => 'minor', 'client_uuid' => $uuid])->assertRedirect();

        $this->assertSame(1, Ncr::count());
    }

    public function test_it_cannot_be_resolved_without_a_root_cause_and_a_corrective_action(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->raise($w);

        $this->patch(route('ncrs.update', $ncr), ['status' => 'resolved'])->assertSessionHasErrors(['root_cause', 'corrective_action']);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'resolved', 'root_cause' => 'Poor compaction'])->assertSessionHasErrors(['corrective_action']);
        $this->assertSame('open', $ncr->refresh()->status);

        $this->patch(route('ncrs.update', $ncr), ['status' => 'resolved', 'root_cause' => 'Poor compaction', 'corrective_action' => 'Re-compacted and tested'])->assertSessionHasNoErrors();
        $ncr->refresh();
        $this->assertSame('resolved', $ncr->status);
        $this->assertNotNull($ncr->resolved_at);
    }

    public function test_the_life_of_an_ncr(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->raise($w);

        $this->patch(route('ncrs.update', $ncr), ['status' => 'closed'])->assertSessionHasErrors('status'); // cannot skip resolving
        $this->patch(route('ncrs.update', $ncr), ['status' => 'in_progress'])->assertSessionHasNoErrors();
        $this->investigate($ncr);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'resolved', 'rework_cost' => '18500.50'])->assertSessionHasNoErrors();
        $this->patch(route('ncrs.update', $ncr), ['status' => 'in_progress'])->assertSessionHasNoErrors(); // rework found lacking
        $this->assertNull($ncr->refresh()->resolved_at);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'resolved'])->assertSessionHasNoErrors();
        $this->patch(route('ncrs.update', $ncr), ['status' => 'closed'])->assertSessionHasNoErrors();

        $ncr->refresh();
        $this->assertSame('closed', $ncr->status);
        $this->assertSame($w->owner->id, $ncr->closed_by);
        $this->assertSame('18500.50', $ncr->rework_cost);
    }

    public function test_a_closed_ncr_cannot_be_changed(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->raise($w);
        $this->investigate($ncr);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'resolved']);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'closed']);

        $this->patch(route('ncrs.update', $ncr), ['rework_cost' => 1])->assertStatus(409);
        $this->patch(route('ncrs.update', $ncr), ['status' => 'open'])->assertStatus(409);
        $this->assertSame('0.00', $ncr->refresh()->rework_cost);

        try {
            $ncr->update(['title' => 'Edited behind the back']);
            $this->fail('The model must refuse too.');
        } catch (ImmutableRecordException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_rework_cost_is_added_up_exactly(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $this->raise($w, ['rework_cost' => '0.10']);
        $this->raise($w, ['rework_cost' => '0.20']);
        $this->raise($w, ['rework_cost' => '12345.67']);

        $this->assertSame('12345.97', app(NcrService::class)->reworkTotal($w->project)); // 0.10 + 0.20 + 12345.67
        $this->get(route('ncrs.index', $w->project))->assertOk()->assertSee('₹12,345.97');
    }

    public function test_input_is_checked_against_this_company_and_project(): void
    {
        $w = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($w->owner);
        $foreignSite = Site::create(['organization_id' => $w->organization->id, 'project_id' => $this->projectOf($w->organization)->id, 'name' => 'Elsewhere']);

        $this->post(route('ncrs.store', $w->project), ['title' => 'x', 'category' => 'nope', 'severity' => 'huge'])->assertSessionHasErrors(['category', 'severity']);
        $this->post(route('ncrs.store', $w->project), ['title' => 'x', 'category' => 'material', 'severity' => 'minor', 'assigned_to' => $theirs->owner->id])->assertSessionHasErrors('assigned_to');
        $this->post(route('ncrs.store', $w->project), ['title' => 'x', 'category' => 'material', 'severity' => 'minor', 'site_id' => $foreignSite->id])->assertSessionHasErrors('site_id');
        $this->post(route('ncrs.store', $w->project), ['title' => 'x', 'category' => 'material', 'severity' => 'minor', 'raised_on' => '2026-10-10', 'due_date' => '2026-10-01'])->assertSessionHasErrors('due_date');
        $this->post(route('ncrs.store', $w->project), ['title' => 'x', 'category' => 'material', 'severity' => 'minor', 'rework_cost' => -5])->assertSessionHasErrors('rework_cost');
        $this->assertSame(0, Ncr::count());
    }

    public function test_who_can_raise_and_update(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $ncr = $this->raise($w);

        foreach (['pm' => true, 'engineer' => true, 'qs' => false, 'viewer' => false, 'accounts' => false] as $role => $canWrite) {
            Auth::forgetUser();
            $this->actingAs($this->member($w->organization, $role));

            $this->get(route('ncrs.index', $w->project))->assertOk();
            $this->get(route('ncrs.show', $ncr))->assertOk();
            $this->post(route('ncrs.store', $w->project), ['title' => 'x', 'category' => 'material', 'severity' => 'minor'])->assertStatus($canWrite ? 302 : 403);
            $this->patch(route('ncrs.update', $ncr), ['status' => 'in_progress'])->assertStatus($canWrite ? 302 : 403);
        }

        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'store'))->get(route('ncrs.index', $w->project))->assertForbidden();
    }

    public function test_it_is_a_business_plan_module_and_another_company_cannot_see_it(): void
    {
        $small = $this->world('owner', 'contractor', 'Small Co');
        $this->actingAs($small->owner)->get(route('ncrs.index', $small->project))->assertForbidden();

        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirNcr = $this->raise($theirs);

        Auth::forgetUser();
        $this->actingAs($mine->owner);
        $this->get(route('ncrs.show', $theirNcr))->assertNotFound();
        $this->patch(route('ncrs.update', $theirNcr), ['status' => 'in_progress'])->assertNotFound();
        $this->get(route('ncrs.index', $theirs->project))->assertNotFound();
        $this->assertSame('open', Ncr::withoutGlobalScopes()->find($theirNcr->id)->status);
    }
}
