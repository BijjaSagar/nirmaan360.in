<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\CustomRole;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/** PATCH and DELETE /boq-items/{item}: editing a BOQ line from the field app, and removing one as the company owner. */
class BoqItemEditApiTest extends ApiTestCase
{
    private Organization $org;

    private Project $project;

    private BoqItem $line;

    private User $owner;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->tenant('Deccan Builders');
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
        $this->project = $this->makeProject($this->org);
        $this->line = $this->makeBoqItem($this->project);
    }

    private function edit(BoqItem $item, array $changes = []): TestResponse
    {
        return $this->patchJson(self::API."/boq-items/{$item->id}", [
            'code' => $item->code,
            'description' => $item->description,
            'unit' => $item->unit,
            'qty' => $item->qty,
            'rate' => $item->rate,
            'reason' => 'Revised drawing R3',
            ...$changes,
        ]);
    }

    private function remove(BoqItem $item, array $body = ['reason' => 'Added twice by mistake']): TestResponse
    {
        return $this->deleteJson(self::API."/boq-items/{$item->id}", $body);
    }

    /** Someone holding exactly these permissions. */
    private function holding(array $permissions, string $baseRole = 'engineer'): User
    {
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = $this->member($this->org, $baseRole);
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function revisions(BoqItem $item)
    {
        return BoqRevision::withoutGlobalScopes()->where('boq_item_id', $item->id)->orderBy('id')->get();
    }

    private function audits(BoqItem $item)
    {
        return AuditLog::withoutGlobalScopes()->where('auditable_type', BoqItem::class)->where('auditable_id', $item->id)->orderBy('id')->get();
    }

    // Records that use a BOQ line, written straight to their tables.

    private function stamps(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    private function dprWork(BoqItem $item, string $qty = '5.000'): int
    {
        $dpr = DB::table('dprs')->insertGetId(['organization_id' => $item->organization_id, 'project_id' => $item->project_id, 'report_date' => '2026-11-02', 'status' => 'APPROVED', ...$this->stamps()]);
        DB::table('dpr_work_items')->insert(['organization_id' => $item->organization_id, 'dpr_id' => $dpr, 'boq_item_id' => $item->id, 'qty' => $qty, ...$this->stamps()]);

        return $dpr;
    }

    private function tender(BoqItem $item): int
    {
        $tender = DB::table('contractor_tenders')->insertGetId(['organization_id' => $item->organization_id, 'project_id' => $item->project_id, 'number' => 'CT-'.Str::random(6), 'title' => 'Slab work', ...$this->stamps()]);
        DB::table('contractor_tender_items')->insert(['organization_id' => $item->organization_id, 'contractor_tender_id' => $tender, 'boq_item_id' => $item->id, 'qty' => $item->qty, 'boq_rate' => $item->rate, ...$this->stamps()]);

        return $tender;
    }

    private function party(BoqItem $item): int
    {
        return DB::table('parties')->insertGetId(['organization_id' => $item->organization_id, 'type' => 'subcontractor', 'name' => 'Shree Constructions', ...$this->stamps()]);
    }

    private function workOrder(BoqItem $item): int
    {
        $order = DB::table('work_orders')->insertGetId(['organization_id' => $item->organization_id, 'project_id' => $item->project_id, 'party_id' => $this->party($item), 'number' => 'WO-'.Str::random(6), 'title' => 'Slab', ...$this->stamps()]);
        DB::table('work_order_items')->insert(['organization_id' => $item->organization_id, 'work_order_id' => $order, 'boq_item_id' => $item->id, 'qty' => '10.000', 'rate' => '4500.00', ...$this->stamps()]);

        return $order;
    }

    private function bill(BoqItem $item, string $qty = '10.000', string $status = 'DRAFT', string $type = 'client'): int
    {
        $bill = DB::table('ra_bills')->insertGetId(['organization_id' => $item->organization_id, 'project_id' => $item->project_id, 'type' => $type, 'bill_no' => 1, 'number' => 'RA-'.Str::random(6), 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => $status, ...$this->stamps()]);

        return DB::table('ra_bill_items')->insertGetId(['organization_id' => $item->organization_id, 'ra_bill_id' => $bill, 'boq_item_id' => $item->id, 'code' => $item->code, 'description' => $item->description, 'unit' => $item->unit, 'rate' => $item->rate, 'current_qty' => $qty, 'cumulative_qty' => $qty, ...$this->stamps()]);
    }

    private function progress(BoqItem $item): void
    {
        DB::table('progress_entries')->insert(['organization_id' => $item->organization_id, 'project_id' => $item->project_id, 'boq_item_id' => $item->id, 'work_date' => '2026-11-02', 'qty_done' => '4.000', ...$this->stamps()]);
    }

    private function labour(BoqItem $item): void
    {
        $worker = DB::table('workers')->insertGetId(['organization_id' => $item->organization_id, 'name' => 'Ramesh', 'trade' => 'Mason', ...$this->stamps()]);
        DB::table('labour_allocations')->insert(['organization_id' => $item->organization_id, 'project_id' => $item->project_id, 'worker_id' => $worker, 'boq_item_id' => $item->id, 'work_date' => '2026-11-02', 'man_days' => '1.000', ...$this->stamps()]);
    }

    private function measured(BoqItem $item): MeasurementEntry
    {
        return $this->draftEntry($this->project, $item, $this->member($this->org, 'engineer'));
    }

    // PATCH /boq-items/{item}

    public function test_a_manager_edits_a_line_and_gets_it_back_in_the_pull_shape(): void
    {
        $this->signIn($this->pm);

        $response = $this->edit($this->line, ['code' => '2.1', 'description' => 'RCC M30 in slab', 'qty' => '120.5', 'rate' => '5500', 'reason' => 'Grade raised to M30'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->line->id)
            ->assertJsonPath('data.code', '2.1')
            ->assertJsonPath('data.description', 'RCC M30 in slab')
            ->assertJsonPath('data.qty', '120.500')
            ->assertJsonPath('data.rate', '5500.00')
            ->assertJsonPath('data.amount', '662750.00')
            ->assertJsonPath('data.balance_qty', '120.500');

        $this->assertSame(
            ['id', 'project_id', 'section', 'code', 'description', 'unit', 'qty', 'rate', 'amount', 'balance_amount', 'sort', 'measured_qty', 'approved_qty', 'balance_qty', 'updated_at'],
            array_keys($response->json('data')),
        );

        $line = $this->line->fresh();
        $this->assertSame(['2.1', 'RCC M30 in slab', '120.500', '5500.00'], [$line->code, $line->description, $line->qty, $line->rate]);

        $revision = $this->revisions($this->line)->last();
        $this->assertSame(['edited', 'app_edit', 'Grade raised to M30', $this->pm->id], [$revision->action, $revision->source, $revision->reason, $revision->user_id]);
        $this->assertSame(['100.000', '120.500'], $revision->changes['qty']);
        $this->assertSame('updated', $this->audits($this->line)->last()->action);
    }

    public function test_an_edit_has_the_web_rules_and_needs_a_reason(): void
    {
        $this->signIn($this->pm);

        $this->patchJson(self::API."/boq-items/{$this->line->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'description', 'unit', 'qty', 'rate', 'reason']);

        $this->edit($this->line, ['qty' => '-1', 'category' => 'not-a-category', 'reason' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qty', 'category', 'reason']);

        $this->assertSame([], $this->revisions($this->line)->where('action', 'edited')->values()->all());
    }

    public function test_the_section_is_left_as_it_is(): void
    {
        $this->signIn($this->pm);

        $this->edit($this->line, ['section' => 'Superstructure', 'description' => 'Changed'])->assertOk()->assertJsonPath('data.section', null);
        $this->assertNull($this->line->fresh()->boq_section_id);
    }

    public function test_only_boq_managers_may_edit(): void
    {
        foreach (['engineer', 'store', 'accounts', 'viewer'] as $role) {
            $this->signIn($this->member($this->org, $role));
            $this->edit($this->line, ['description' => 'Changed by '.$role])->assertForbidden();
        }

        $this->assertSame('RCC M25 in slab', $this->line->fresh()->description);
    }

    public function test_someone_who_may_not_see_money_edits_without_touching_the_rate(): void
    {
        $this->signIn($this->holding(['project.view', 'boq.view', 'boq.manage']));

        $this->patchJson(self::API."/boq-items/{$this->line->id}", [
            'code' => $this->line->code, 'description' => 'RCC M25 in roof slab', 'unit' => 'cum', 'qty' => '110', 'rate' => '1', 'reason' => 'Roof added',
        ])->assertOk()->assertJsonPath('data.rate', null)->assertJsonPath('data.amount', null);

        $line = $this->line->fresh();
        $this->assertSame(['RCC M25 in roof slab', '110.000', '5000.00'], [$line->description, $line->qty, $line->rate]);
    }

    public function test_another_companys_line_is_not_found(): void
    {
        $this->signInElsewhere();

        $this->edit($this->line, ['description' => 'Hijacked'])->assertNotFound();
        $this->remove($this->line)->assertNotFound();

        $this->assertSame('RCC M25 in slab', $this->line->fresh()->description);
        $this->assertFalse($this->line->fresh()->trashed());
    }

    public function test_a_person_limited_to_other_projects_cannot_reach_the_line(): void
    {
        $other = $this->makeProject($this->org, ['name' => 'Other site']);
        $limited = $this->member($this->org, 'pm');
        $limited->forceFill(['limited_to_projects' => true])->save();
        $limited->assignedProjects()->attach($other->id, ['organization_id' => $this->org->id]);
        $this->signIn($limited->fresh());

        $this->edit($this->line, ['description' => 'Changed'])->assertNotFound();

        $limited->assignedProjects()->attach($this->project->id, ['organization_id' => $this->org->id]);
        $this->signIn($limited->fresh());
        $this->edit($this->line, ['description' => 'Changed'])->assertOk();
    }

    public function test_the_quantity_cannot_go_below_what_is_measured(): void
    {
        $entry = $this->measured($this->line);
        $this->assertSame('50.000', $entry->qty);
        $this->signIn($this->pm);

        $this->edit($this->line, ['qty' => '49.999'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.qty.0', 'Quantity cannot be lower than the quantity already measured (50.000).');
        $this->assertSame('100.000', $this->line->fresh()->qty);

        $this->edit($this->line, ['qty' => '50'])->assertOk()->assertJsonPath('data.qty', '50.000');
    }

    public function test_rejected_measurements_do_not_hold_the_quantity(): void
    {
        $entry = $this->measured($this->line);
        DB::table('measurement_entries')->where('id', $entry->id)->update(['status' => 'REJECTED']);
        $this->signIn($this->pm);

        $this->edit($this->line, ['qty' => '10'])->assertOk()->assertJsonPath('data.qty', '10.000');
    }

    public function test_the_quantity_cannot_go_below_what_is_billed_to_the_client(): void
    {
        $this->bill($this->line, '60.000');
        $this->bill($this->line, '30.000', 'CANCELLED');
        $this->bill($this->line, '80.000', 'DRAFT', 'subcontractor');
        $this->signIn($this->pm);

        $this->edit($this->line, ['qty' => '59'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.qty.0', 'Quantity cannot be lower than the quantity already billed (60.000).');

        $this->edit($this->line, ['qty' => '60'])->assertOk();
    }

    public function test_daily_report_figures_do_not_hold_the_quantity(): void
    {
        $this->dprWork($this->line, '90.000');
        $this->signIn($this->pm);

        $this->edit($this->line, ['qty' => '80'])->assertOk()->assertJsonPath('data.qty', '80.000');
    }

    public function test_the_unit_is_locked_once_anything_is_recorded_against_the_line(): void
    {
        $this->signIn($this->pm);
        $this->edit($this->line, ['unit' => 'sqm'])->assertOk();

        $this->tender($this->line);
        $this->edit($this->line, ['unit' => 'rmt'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.unit.0', 'The unit cannot change once work, tenders or bills are recorded against this item. Add a new item instead.');
        $this->assertSame('sqm', $this->line->fresh()->unit);

        $this->edit($this->line, ['unit' => 'sqm', 'description' => 'Still editable'])->assertOk();
    }

    public function test_approved_measurements_and_bills_stay_exactly_as_they_were(): void
    {
        $engineer = $this->member($this->org, 'engineer');
        $entry = $this->approveEntry($this->draftEntry($this->project, $this->line, $engineer), $engineer, $this->owner);
        $this->assertSame('APPROVED', $entry->status);
        $billItem = $this->bill($this->line, '50.000', 'APPROVED');
        $entryBefore = DB::table('measurement_entries')->where('id', $entry->id)->first();
        $billBefore = DB::table('ra_bill_items')->where('id', $billItem)->first();
        $this->signIn($this->pm);

        $this->edit($this->line, ['description' => 'RCC M30 in slab', 'qty' => '150', 'rate' => '9000'])->assertOk()
            ->assertJsonPath('data.approved_qty', '50.000')
            ->assertJsonPath('data.balance_qty', '100.000');

        $this->assertEquals($entryBefore, DB::table('measurement_entries')->where('id', $entry->id)->first());
        $this->assertEquals($billBefore, DB::table('ra_bill_items')->where('id', $billItem)->first());
    }

    public function test_sending_the_same_edit_again_changes_and_records_nothing(): void
    {
        $this->signIn($this->pm);

        $this->edit($this->line, ['description' => 'RCC M30 in slab'])->assertOk();
        $revisions = $this->revisions($this->line)->count();
        $audits = $this->audits($this->line)->count();

        $this->edit($this->line->fresh(), ['description' => 'RCC M30 in slab'])->assertOk()->assertJsonPath('data.description', 'RCC M30 in slab');

        $this->assertSame($revisions, $this->revisions($this->line)->count());
        $this->assertSame($audits, $this->audits($this->line)->count());
    }

    public function test_a_line_changed_since_it_was_opened_is_a_409_with_the_line_as_it_is_now(): void
    {
        $opened = $this->line->updated_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->line->update(['description' => 'Changed on the website']);
        $this->signIn($this->pm);

        $this->edit($this->line, ['description' => 'Changed in the app', 'updated_at' => $opened])
            ->assertStatus(409)
            ->assertJsonPath('code', 'changed')
            ->assertJsonPath('message', 'Someone changed this BOQ item after you opened it. Check it and save again.')
            ->assertJsonPath('data.description', 'Changed on the website');
        $this->assertSame('Changed on the website', $this->line->fresh()->description);

        $this->edit($this->line, ['description' => 'Changed in the app', 'updated_at' => $this->line->fresh()->updated_at->toIso8601String()])
            ->assertOk()->assertJsonPath('data.description', 'Changed in the app');
    }

    public function test_a_removed_line_cannot_be_edited(): void
    {
        $this->line->delete();
        $this->signIn($this->pm);

        $this->edit($this->line, ['description' => 'Back again'])->assertNotFound();
    }

    // DELETE /boq-items/{item}

    public function test_the_owner_removes_an_unused_line_and_it_is_kept_in_the_history(): void
    {
        $this->signIn($this->owner);

        $this->remove($this->line, ['reason' => 'Added twice by mistake'])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->line->id, 'deleted' => true]]);

        $this->assertSoftDeleted($this->line);
        $revision = $this->revisions($this->line)->last();
        $this->assertSame(['deleted', 'app_remove', 'Added twice by mistake', $this->owner->id], [$revision->action, $revision->source, $revision->reason, $revision->user_id]);
        $this->assertSame('deleted', $this->audits($this->line)->last()->action);
    }

    public function test_a_removal_needs_a_reason(): void
    {
        $this->signIn($this->owner);

        $this->remove($this->line, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertFalse($this->line->fresh()->trashed());
    }

    public function test_nobody_but_the_owner_may_remove_a_line(): void
    {
        $ownerByCustomRole = $this->holding(['*'], 'owner');
        $this->assertSame('owner', $ownerByCustomRole->role);

        foreach ([$this->member($this->org, 'admin'), $this->pm, $this->member($this->org, 'qs'), $ownerByCustomRole] as $user) {
            $this->signIn($user);
            $this->remove($this->line)->assertForbidden()->assertJsonPath('message', 'Only the company owner can delete a BOQ item.');
        }

        $this->signIn($this->member($this->org, 'engineer'));
        $this->remove($this->line)->assertForbidden();

        $this->assertFalse($this->line->fresh()->trashed());
        $this->assertSame([], $this->revisions($this->line)->where('action', 'deleted')->values()->all());
    }

    public function test_the_owner_refusal_is_in_the_persons_language(): void
    {
        $this->pm->update(['locale' => 'mr']);
        $this->signIn($this->pm->fresh());

        $this->remove($this->line)->assertForbidden()->assertJsonPath('message', 'BOQ आयटम फक्त कंपनीचा मालक काढू शकतो.');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function uses(): array
    {
        return [
            'measurements' => ['measured', 'This item has measurements and cannot be removed.'],
            'daily report work' => ['dprWork', 'This item has work in daily reports and cannot be removed.'],
            'a tender' => ['tender', 'This item is in a tender and cannot be removed.'],
            'a work order' => ['workOrder', 'This item is on a work order and cannot be removed.'],
            'a bill' => ['bill', 'This item is on a bill and cannot be removed.'],
            'site progress' => ['progress', 'This item has site progress entries and cannot be removed.'],
            'labour' => ['labour', 'This item has labour booked against it and cannot be removed.'],
        ];
    }

    #[DataProvider('uses')]
    public function test_a_line_with_records_against_it_is_not_removed(string $record, string $message): void
    {
        $this->{$record}($this->line);
        $this->signIn($this->owner);

        $this->remove($this->line)->assertUnprocessable()->assertJsonPath('errors.boq', [$message]);

        $this->assertFalse($this->line->fresh()->trashed());
        $this->assertSame([], $this->revisions($this->line)->where('action', 'deleted')->values()->all());
    }

    public function test_every_record_holding_a_line_is_named(): void
    {
        $this->measured($this->line);
        $this->tender($this->line);
        $this->signIn($this->owner);

        $this->remove($this->line)->assertUnprocessable()->assertJsonPath('errors.boq', [
            'This item has measurements and cannot be removed.',
            'This item is in a tender and cannot be removed.',
        ]);
    }

    public function test_a_deleted_daily_report_or_work_order_no_longer_holds_the_line(): void
    {
        $dpr = $this->dprWork($this->line);
        $order = $this->workOrder($this->line);
        DB::table('dprs')->where('id', $dpr)->update(['deleted_at' => now()]);
        DB::table('work_orders')->where('id', $order)->update(['deleted_at' => now()]);
        $this->signIn($this->owner);

        $this->remove($this->line)->assertOk();
        $this->assertSoftDeleted($this->line);
    }

    public function test_removing_again_answers_as_removed_and_records_nothing_more(): void
    {
        $this->signIn($this->owner);
        $this->remove($this->line)->assertOk();
        $revisions = $this->revisions($this->line)->count();
        $audits = $this->audits($this->line)->count();

        $this->remove($this->line)->assertOk()->assertExactJson(['data' => ['id' => $this->line->id, 'deleted' => true]]);

        $this->assertSame($revisions, $this->revisions($this->line)->count());
        $this->assertSame($audits, $this->audits($this->line)->count());
    }

    public function test_a_removed_line_is_still_hidden_from_other_companies(): void
    {
        $this->line->delete();
        $this->signInElsewhere();

        $this->remove($this->line)->assertNotFound();
    }

    public function test_the_website_refuses_the_same_records(): void
    {
        $this->dprWork($this->line);

        $this->actingAs($this->pm)->delete(route('boq.destroy', $this->line), ['reason' => 'Not needed'])->assertSessionHasErrors('boq');
        $this->assertFalse($this->line->fresh()->trashed());
    }

    // GET /me

    public function test_me_says_whether_the_person_is_the_company_owner(): void
    {
        $cases = [
            [$this->owner, true],
            [$this->member($this->org, 'admin'), false],
            [$this->pm, false],
            [$this->holding(['*'], 'owner'), false],
        ];

        foreach ($cases as [$user, $isOwner]) {
            $this->signIn($user);
            $this->getJson(self::API.'/me')->assertOk()->assertJsonPath('user.is_owner', $isOwner);
        }
    }
}
