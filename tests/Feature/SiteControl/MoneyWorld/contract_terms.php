<?php

use App\Models\ContractTerm;
use App\Models\WorkOrder;

/*
 * Contract terms for the route walks: one confirmed term with rupee amounts on the client contract,
 * one unconfirmed term on the work order, and a notice period that carries no money at all.
 * The percentages and the number of days are invented for the test and come from no agreement.
 */
return function (): void {
    $owner = $this->users['owner'];
    $common = [
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'effective_from' => '2026-01-01',
        'source_ref' => 'Sample agreement, section B',
        'entered_by' => $owner->id,
    ];

    $term = ContractTerm::create([
        ...$common,
        'kind' => 'deduction', 'code' => 'other', 'label' => 'Site office rent',
        'method' => 'fixed_per_bill', 'amount' => self::MONEY['bill_deduction'], 'nature' => 'cost',
        'confirmed_by' => $owner->id, 'confirmed_at' => now(),
    ]);

    ContractTerm::create([
        ...$common,
        'work_order_id' => $this->rows[WorkOrder::class]->id,
        'kind' => 'deduction', 'code' => 'security_deposit', 'label' => 'Security deposit',
        'method' => 'percent', 'pct' => '1.5', 'base' => 'gross', 'cap_amount' => self::MONEY['wo_advance'], 'nature' => 'withheld_refundable',
    ]);

    ContractTerm::create([
        ...$common,
        'kind' => 'notice', 'code' => 'delay_notice', 'label' => 'Notice of delay',
        'days' => 9, 'counted_from' => 'hindrance_start',
        'confirmed_by' => $owner->id, 'confirmed_at' => now(),
    ]);

    $this->rows[ContractTerm::class] = $term;
};
