<?php

namespace Tests\Feature\BillCheck;

use App\Models\BillCheck;
use App\Models\BoqItem;
use App\Models\ContractTerm;
use App\Models\CustomRole;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\CheckResult;
use App\Services\BillCheck\Flag;
use App\Services\BillCheck\RaBillPreCheck;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * An invented company with one project, one BOQ line and, where a test asks for it, one subcontract.
 *
 * Every percentage, amount, rate, number of days, date and clause in these tests is made up for the
 * test. None of them comes from an agreement, a schedule of rates or a law, and none is a rate the
 * app knows: the bills here are worked out from what the test types on the contract form.
 */
abstract class BillCheckTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    /** What the test types on the project form. Made up. */
    protected const FORM = [
        'retention_pct' => '6.50',
        'tds_pct' => '1.75',
        'gst_pct' => '12.50',
        'advance_amount' => '40000.00',
        'advance_recovery_pct' => '8.00',
    ];

    protected const FROM = '2026-11-01';

    protected const TO = '2026-11-30';

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected User $accounts;

    protected User $engineer;

    protected User $viewer;

    protected Party $client;

    protected Project $project;

    protected BoqItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        // A fixed day, so "today" in a check never depends on when the tests run.
        $this->travelTo(Carbon::parse('2026-12-20 11:00:00'));

        $this->org = $this->tenant('Meghdoot Buildcon', 'business');
        $this->org->update(['gstin' => '27AAAAA0000A1Z5', 'state' => 'Maharashtra']);
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
        $this->qs = $this->member($this->org, 'qs');
        $this->accounts = $this->member($this->org, 'accounts');
        $this->engineer = $this->member($this->org, 'engineer');
        $this->viewer = $this->member($this->org, 'viewer');

        $this->client = Party::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'type' => 'client', 'name' => 'Sahyadri Estates']);
        $this->project = $this->makeProject($this->org, [
            'code' => 'MB1', 'name' => 'Canal Road Housing', 'client_id' => $this->client->id, 'state' => 'Maharashtra', ...self::FORM,
        ]);
        $this->item = $this->makeBoqItem($this->project, '100.000', '2400.00');

        $this->actingAs($this->owner);
    }

    /**
     * An approved measurement of a plain quantity, with a photo unless the test says otherwise.
     * A quantity that starts with a minus sign is entered as a deduction.
     */
    protected function measure(string $qty = '20', array $overrides = [], bool $photo = true, ?BoqItem $item = null): MeasurementEntry
    {
        $entry = $this->draft($qty, $overrides, $photo, $item);

        return $this->approveEntry($entry, $this->engineer, $this->owner);
    }

    /** The same, left as a draft. */
    protected function draft(string $qty = '20', array $overrides = [], bool $photo = true, ?BoqItem $item = null): MeasurementEntry
    {
        $entry = $this->draftEntry($this->project, $item ?? $this->item, $this->engineer, [
            'measured_on' => '2026-11-10',
            'nos' => ltrim($qty, '-'),
            'length' => null,
            'breadth' => null,
            'depth' => null,
            'is_deduction' => str_starts_with($qty, '-'),
            ...$overrides,
        ]);

        if ($photo) {
            MeasurementAttachment::create(['organization_id' => $this->org->id, 'measurement_entry_id' => $entry->id, 'kind' => 'photo', 'path' => 'measurements/'.Str::random(8).'.jpg']);
        }

        return $entry;
    }

    protected function clientBill(string $from = self::FROM, string $to = self::TO, bool $withGst = true): RaBill
    {
        return app(RaBillService::class)->generate($this->project, 'client', $from, $to, $this->owner, withGst: $withGst);
    }

    protected function finalClientBill(string $from = self::FROM, string $to = self::TO): RaBill
    {
        return app(RaBillService::class)->generateFinal($this->project, 'client', $from, $to, $this->owner);
    }

    /** A work order for the one BOQ line. Its percentages are made up, like everything else here. */
    protected function workOrder(array $attributes = [], string $qty = '50', string $rate = '900'): WorkOrder
    {
        $party = Party::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'type' => 'subcontractor', 'name' => 'Trimurti Shuttering']);

        $workOrder = WorkOrder::create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'party_id' => $party->id,
            'number' => 'WO-'.Str::upper(Str::random(5)), 'title' => 'Slab shuttering', 'status' => 'ACTIVE', 'rate_type' => 'item_rate',
            'retention_pct' => '4.25', 'tds_pct' => '0.75', 'gst_pct' => '0', 'advance_amount' => '0', 'advance_recovery_pct' => '0',
            ...$attributes,
        ]);
        $workOrder->items()->create(['organization_id' => $this->org->id, 'boq_item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]);

        return $workOrder;
    }

    protected function subBill(WorkOrder $workOrder, string $from = self::FROM, string $to = self::TO): RaBill
    {
        return app(RaBillService::class)->generate($this->project, 'subcontractor', $from, $to, $this->owner, $workOrder);
    }

    /** Takes a bill through every approval level. */
    protected function approveBill(RaBill $bill): RaBill
    {
        $approval = app(ApprovalService::class)->submit($bill, $this->owner);

        while ($approval && $approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $this->owner, 'approve');
        }

        return $bill->refresh();
    }

    /** A confirmed contract term, written straight to the table. */
    protected function term(array $attributes = []): ContractTerm
    {
        return ContractTerm::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id,
            'project_id' => $this->project->id,
            'kind' => 'deduction',
            'code' => 'retention',
            'label' => 'Retention',
            'method' => 'percent',
            'pct' => self::FORM['retention_pct'],
            'base' => 'gross',
            'effective_from' => '2026-04-01',
            'source_ref' => 'Sample agreement, part C',
            'entered_by' => $this->owner->id,
            'confirmed_by' => $this->owner->id,
            'confirmed_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * Confirms, for a contract, the four percentages a bill works out itself, with the values typed
     * on the form, so a test about something else is not told four times that nobody confirmed them.
     */
    protected function confirmFormPercentages(?WorkOrder $workOrder = null): void
    {
        $form = $workOrder ?? $this->project;

        foreach (ContractTerm::BILL_PERCENTAGES as $code => $key) {
            $this->term(['code' => $code, 'label' => Str::headline($code), 'pct' => $form->{$key}, 'work_order_id' => $workOrder?->id]);
        }
    }

    protected function inspect(RaBill $bill): CheckResult
    {
        return app(RaBillPreCheck::class)->inspect($bill);
    }

    /** @return list<string> The key of every finding. */
    protected function keys(CheckResult|RaBill $result): array
    {
        $result = $result instanceof RaBill ? $this->inspect($result) : $result;

        return array_map(fn (Flag $flag) => $flag->key, $result->flags);
    }

    /** The finding with this key, which must be there. */
    protected function found(CheckResult|RaBill $result, string $key): Flag
    {
        $result = $result instanceof RaBill ? $this->inspect($result) : $result;

        foreach ($result->flags as $flag) {
            if ($flag->key === $key) {
                return $flag;
            }
        }

        $this->fail("No finding {$key}. Found: ".implode(', ', $this->keys($result)));
    }

    protected function assertNotFound(CheckResult|RaBill $result, string $prefix): void
    {
        $matches = array_values(array_filter($this->keys($result), fn (string $key) => $key === $prefix || str_starts_with($key, $prefix.':')));

        $this->assertSame([], $matches, "Did not expect a finding {$prefix}.");
    }

    /** Stores a check of the bill, as pressing "Check this bill" does. */
    protected function check(RaBill $bill, ?User $by = null): BillCheck
    {
        return app(BillChecks::class)->check($bill, $by ?? $this->owner)[0];
    }

    /** Acknowledges every finding of a check that needs it. */
    protected function acknowledgeAll(BillCheck $check, ?User $by = null): void
    {
        foreach ($check->flags as $flag) {
            if ($flag['level'] !== Flag::NOTE) {
                app(BillChecks::class)->acknowledge($check, $flag['key'], 'Seen and checked with site', $by ?? $this->owner);
            }
        }
    }

    protected function requireCheck(bool $required = true, ?Organization $organization = null): void
    {
        app(BillChecks::class)->setRequired(($organization ?? $this->org)->id, $required, $this->owner);
    }

    /** Someone holding exactly these permissions. */
    protected function holding(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->org;
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = $this->member($organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    /** A second company with a checked bill of its own, for tenant isolation. */
    protected function rival(): array
    {
        $org = $this->tenant('Rival Builders', 'business');
        $owner = $this->member($org, 'owner');
        $project = $this->makeProject($org, ['code' => 'RV1', ...self::FORM]);
        $item = $this->makeBoqItem($project, '100.000', '2400.00');

        $this->actingAs($owner);
        $this->approveEntry($this->draftEntry($project, $item, $owner, ['measured_on' => '2026-11-10']), $owner, $owner);
        $bill = app(RaBillService::class)->generate($project, 'client', self::FROM, self::TO, $owner);
        $check = app(RaBillPreCheck::class)->run($bill, $owner);
        $this->actingAs($this->owner);

        return [$org, $owner, $bill, $check];
    }
}
