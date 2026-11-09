<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Models\Project;
use App\Services\ContractorControl\ContractorTenderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenderController extends Controller
{
    public function __construct(private ContractorTenderService $service) {}

    public function index(Project $project): View
    {
        return view('modules.contractor_control.tenders.index', [
            'project' => $project,
            'tenders' => ContractorTender::with('items', 'bids.party', 'workOrder')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.contractor_control.tenders.create', [
            'project' => $project,
            'boqItems' => $project->boqItems()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $tender = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('contractor-control.tenders.show', $tender)->with('success', __('Tender created. Now add the quotes.'));
    }

    public function show(ContractorTender $tender): View
    {
        return view('modules.contractor_control.tenders.show', [
            'tender' => $tender->load('workOrder'),
            'project' => $tender->project,
            'comparison' => $this->service->comparison($tender),
            'contractors' => Party::where('type', 'subcontractor')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function addBid(Request $request, ContractorTender $tender): RedirectResponse
    {
        $this->service->addBid($tender, $request->validate($this->service->bidRules($tender)), $request->user());

        return back()->with('success', __('Quote recorded.'));
    }

    public function award(Request $request, ContractorTender $tender): RedirectResponse
    {
        $data = $request->validate($this->service->awardRules($tender));
        $bid = ContractorBid::findOrFail($data['bid_id']);

        $workOrder = $this->service->award($tender, $bid, $data, $request->user());

        return redirect()->route('contractor-control.contracts.show', $workOrder)->with('success', __('Contractor selected and work order created.'));
    }

    public function cancel(ContractorTender $tender): RedirectResponse
    {
        $this->service->cancel($tender);

        return back()->with('success', __('Tender cancelled.'));
    }
}
