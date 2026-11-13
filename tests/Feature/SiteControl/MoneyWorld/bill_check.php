<?php

use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\ContractTerm;
use App\Models\RaBill;
use App\Services\BillCheck\RaBillPreCheck;

/*
 * A stored pre-check of the money world's bill, for the route walks.
 *
 * That bill was typed in with a different rupee figure in every column, so nothing on it adds up and
 * the check names nearly all of them: the line amount, the gross value, the deduction, GST and net
 * payable. Two confirmed terms of its own add a contract percentage, words from a term and a sheet of
 * deductions that are not on the bill. A site role opens the bill page and the check's JSON in the
 * walks and must receive none of it. The percentage and the clause are invented for the test.
 */
return function (): void {
    $owner = $this->users['owner'];
    $bill = $this->rows[RaBill::class];
    $common = [
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'kind' => 'deduction',
        'effective_from' => '2026-01-01',
        'source_ref' => 'Sample agreement, schedule K',
        'entered_by' => $owner->id,
        'confirmed_by' => $owner->id,
        'confirmed_at' => now(),
    ];

    ContractTerm::create([...$common, 'code' => 'retention', 'label' => 'Retention', 'method' => 'percent', 'pct' => '6.25', 'base' => 'gross']);
    ContractTerm::create([...$common, 'code' => 'testing', 'label' => 'Testing charges', 'method' => 'fixed_per_bill', 'amount' => self::MONEY['bill_gst']]);

    $check = app(RaBillPreCheck::class)->run($bill, $owner);

    BillCheckAck::create([
        'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'bill_check_id' => $check->id,
        'flag_key' => 'A2', 'remark' => 'Figures typed in for the walk', 'user_id' => $owner->id,
    ]);

    $this->rows[BillCheck::class] ??= $check;
};
