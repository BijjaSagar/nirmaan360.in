<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorControlPresenter;
use App\Models\WorkOrder;
use App\Services\ContractorControl\ContractorLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function __construct(private ContractorLedgerService $ledger) {}

    public function show(Request $request, WorkOrder $workOrder): View
    {
        $money = $request->user()->can('money.view');
        $data = $this->ledger->forContract($workOrder);

        return view('modules.contractor_control.contract', [
            'workOrder' => $workOrder,
            'project' => $workOrder->project,
            'money' => $money,
            'ratio' => $money ? $data['ratio'] : ContractorControlPresenter::workOnly($data['ratio']),
            'lines' => $money ? $data['lines'] : array_map(ContractorControlPresenter::workOnlyLine(...), $data['lines']),
            'parts' => $money ? $data['milestones']['parts'] : array_map(fn (array $row) => ['part' => $row['part']], $data['milestones']['parts']),
            'partsTotal' => $money ? $data['milestones']['total'] : null,
            'partsLeft' => $money ? $data['milestones']['unallocated'] : null,
            'unallocated' => $money ? $data['unallocated'] : null,
            'history' => $money ? $data['history'] : [],
            'notCounted' => $money ? $data['not_counted'] : collect(),
            'financePaid' => $money ? $data['finance_paid'] : null,
        ]);
    }

    /** A fixed date for this contract, such as "before Lakshmi Puja". */
    public function deadline(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $workOrder->update($request->validate([
            'end_date' => ['nullable', 'date'],
            'deadline_label' => ['nullable', 'string', 'max:80'],
        ]));

        return back()->with('success', __('Saved.'));
    }
}
