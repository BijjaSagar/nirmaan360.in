<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Models\Project;
use App\Services\BillingExtras\PrivateFile;
use App\Services\ContractorControl\ContractorTenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function create(Request $request, Project $project): View
    {
        $boqItems = $project->boqItems()->orderBy('sort')->orderBy('id')->get();

        return view('modules.contractor_control.tenders.create', [
            'project' => $project,
            'boqItems' => $boqItems,
            // Opened from a line on the BOQ page: that line starts ticked.
            'ticked' => $boqItems->where('id', $request->integer('item'))->pluck('id')->all(),
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
        $this->service->addBid($tender, $request->validate($this->service->bidRules($tender)), $request->user(), $request->file('quotation'));

        return back()->with('success', __('Quote recorded.'));
    }

    /** The quote forms add a contractor from a popup and need the row back. */
    public function storeContractor(Request $request): JsonResponse
    {
        $party = $this->service->addContractor($request->user()->organization_id, $request->validate($this->service->contractorRules()));
        $isNew = $party->wasRecentlyCreated;

        return response()->json([
            'id' => $party->id,
            'name' => $party->name,
            'message' => $isNew ? null : __(':name is already on your list.', ['name' => $party->name]),
        ], $isNew ? 201 : 200);
    }

    /** The quotation a contractor sent. It is all prices, so the route needs money.view. */
    public function quotation(ContractorBid $bid): StreamedResponse
    {
        abort_unless($bid->quotation_path, 404);

        return PrivateFile::response($bid->quotation_path);
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
