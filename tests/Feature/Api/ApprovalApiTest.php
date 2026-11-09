<?php

namespace Tests\Feature\Api;

use App\Models\Approval;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\DprService;
use App\Services\MeasurementService;
use App\Services\RaBillService;
use Illuminate\Support\Str;

class ApprovalApiTest extends ApiTestCase
{
    /** @return array{0: object, 1: User, 2: Approval} A measurement waiting at the QS. */
    private function waitingAtQs(): array
    {
        $site = $this->site();
        $qs = $this->member($site->organization, 'qs');
        $approval = app(MeasurementService::class)->submit($this->draftEntry($site->project, $site->item, $site->user), $site->user);

        return [$site, $qs, $approval];
    }

    public function test_list_has_only_what_the_user_can_act_on_plus_the_waiting_count(): void
    {
        [$site, $qs] = $this->waitingAtQs();
        $dpr = app(DprService::class)->create($site->project, $this->dprData(), $site->user);
        app(DprService::class)->submit($dpr, $site->user);

        $this->signIn($qs);
        $this->getJson(self::API.'/approvals')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doc_type', 'measurement')
            ->assertJsonPath('waiting_count', 1);

        $this->signIn($site->user);
        $this->getJson(self::API.'/approvals')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('waiting_count', 2);
    }

    public function test_the_inbox_needs_a_token(): void
    {
        $this->getJson(self::API.'/approvals')->assertUnauthorized();
    }

    public function test_a_measurement_item_matches_the_contract(): void
    {
        [$site, $qs, $approval] = $this->waitingAtQs();
        $this->signIn($qs);

        $response = $this->getJson(self::API.'/approvals')->assertOk();

        $response->assertJsonPath('data.0.id', $approval->id)
            ->assertJsonPath('data.0.status', 'IN_REVIEW')
            ->assertJsonPath('data.0.project.id', $site->project->id)
            ->assertJsonPath('data.0.project.code', $site->project->code)
            ->assertJsonPath('data.0.submitted_by', ['id' => $site->user->id, 'name' => $site->user->name])
            ->assertJsonPath('data.0.current_level', 1)
            ->assertJsonPath('data.0.levels', ['qs', 'pm'])
            ->assertJsonPath('data.0.current_role', 'qs')
            ->assertJsonPath('data.0.current_role_label', 'Estimator / QS')
            ->assertJsonPath('data.0.can_act', true)
            ->assertJsonPath('data.0.document.boq_item.code', $site->item->code)
            ->assertJsonPath('data.0.document.qty', '50.000')
            ->assertJsonPath('data.0.document.balance_qty', '50.000')
            ->assertJsonPath('data.0.steps.0.action', 'submit')
            ->assertJsonPath('data.0.steps.0.level', 0)
            ->assertJsonPath('data.0.steps.0.actor', $site->user->name);

        $this->assertStringStartsWith('Measurement · '.$site->item->code.' · 50.000 cum', $response->json('data.0.title'));
    }

    public function test_dpr_and_ra_bill_documents_match_the_contract(): void
    {
        $site = $this->site();
        $owner = $this->member($site->organization, 'owner');
        $dpr = app(DprService::class)->create($site->project, $this->dprData(['work' => [['boq_item_id' => $site->item->id, 'qty' => 4]]]), $site->user);
        app(DprService::class)->submit($dpr, $site->user);
        $this->approveEntry($this->draftEntry($site->project, $site->item, $site->user), $site->user, $owner);
        $bill = app(RaBillService::class)->generate($site->project, 'client', '2026-11-01', '2026-11-30', $owner);
        app(ApprovalService::class)->submit($bill, $owner);

        $this->signIn($owner);
        $response = $this->getJson(self::API.'/approvals')->assertOk()->assertJsonCount(2, 'data');
        $documents = collect($response->json('data'))->keyBy('doc_type');

        $this->assertSame(12, $documents['dpr']['document']['manpower_total']);
        $this->assertSame(['code' => $site->item->code, 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '4.000'], $documents['dpr']['document']['work_items'][0]);
        $this->assertSame($bill->number, $documents['ra_bill']['document']['number']);
        $this->assertSame('2026-11-30', $documents['ra_bill']['document']['period_to']);
        $this->assertSame($bill->fresh()->net_payable, $documents['ra_bill']['document']['net_payable']);
    }

    public function test_approve_moves_to_the_next_level(): void
    {
        [, $qs, $approval] = $this->waitingAtQs();
        $this->signIn($qs);

        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'approve', 'client_uuid' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.status', 'IN_REVIEW')
            ->assertJsonPath('data.current_level', 2)
            ->assertJsonPath('data.current_role', 'pm')
            ->assertJsonPath('data.can_act', false);
    }

    public function test_return_sends_it_back_and_needs_a_remark(): void
    {
        [, $qs, $approval] = $this->waitingAtQs();
        $this->signIn($qs);

        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'return'])->assertUnprocessable()->assertJsonValidationErrors('remark');

        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'return', 'remark' => 'Check depth'])->assertOk()
            ->assertJsonPath('data.status', 'RETURNED')
            ->assertJsonPath('data.steps.1.remark', 'Check depth');
        $this->assertSame('RETURNED', $approval->approvable->fresh()->status);
    }

    public function test_an_engineer_cannot_act(): void
    {
        [$site, , $approval] = $this->waitingAtQs();
        $this->signIn($this->member($site->organization, 'engineer'));

        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'approve'])->assertForbidden();

        $this->assertSame(1, ApprovalStep::count());
    }

    public function test_a_repeated_client_uuid_returns_the_same_result_rather_than_403(): void
    {
        [, $qs, $approval] = $this->waitingAtQs();
        $this->signIn($qs);
        $payload = ['action' => 'approve', 'client_uuid' => (string) Str::uuid()];

        $first = $this->postJson(self::API."/approvals/{$approval->id}/act", $payload)->assertOk()->json('data');
        $second = $this->postJson(self::API."/approvals/{$approval->id}/act", $payload)->assertOk()->json('data');

        $this->assertSame($first, $second);
        $this->assertSame(2, $approval->fresh()->current_level);
        $this->assertSame(2, ApprovalStep::count());
    }

    public function test_a_client_uuid_cannot_be_reused_on_another_approval(): void
    {
        [$site, $qs, $approval] = $this->waitingAtQs();
        $other = app(MeasurementService::class)->submit($this->draftEntry($site->project, $site->item, $site->user), $site->user);
        $this->signIn($qs);
        $uuid = (string) Str::uuid();

        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'approve', 'client_uuid' => $uuid])->assertOk();
        $this->postJson(self::API."/approvals/{$other->id}/act", ['action' => 'approve', 'client_uuid' => $uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_another_companys_approval_is_a_404_and_never_listed(): void
    {
        [, , $approval] = $this->waitingAtQs();
        $this->signInElsewhere();

        $this->getJson(self::API.'/approvals')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('waiting_count', 0);
        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'approve'])->assertNotFound();
    }
}
