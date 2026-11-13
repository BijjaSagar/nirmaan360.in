<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorControlPresenter;
use App\Models\Project;
use App\Services\ContractorControl\ProjectLimits;
use App\Services\ContractorControl\WorkPaymentRatio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BoardController extends Controller
{
    public function __construct(private WorkPaymentRatio $ratio, private ProjectLimits $limits) {}

    public function index(Request $request, Project $project): View
    {
        $money = $request->user()->can('money.view');
        $rows = $this->ratio->forProject($project);

        return view('modules.contractor_control.board', [
            'project' => $project,
            'money' => $money,
            'contracts' => $money ? $rows : $rows->map(ContractorControlPresenter::workOnly(...)),
            'summary' => $money ? $this->ratio->summary($rows) : null,
            'canSetLimit' => $this->limits->canChangeLimit($request->user()),
        ]);
    }

    public function settings(Request $request, Project $project): RedirectResponse
    {
        $this->limits->update($project, $request->validate($this->limits->rules($request->user())), $request->user());

        return back()->with('success', __('Saved.'));
    }
}
