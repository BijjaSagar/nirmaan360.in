<?php

namespace App\Http\Controllers\ContractorPortal\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorPortalPresenter;
use App\Models\RaBill;
use App\Models\WorkOrder;
use App\Services\ContractorPortal\ContractorPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The field app's view of the contractor portal: the same data as the web pages, read-only. */
class ContractorPortalApiController extends Controller
{
    public function __construct(private ContractorPortalService $portal) {}

    public function overview(Request $request): JsonResponse
    {
        $party = $this->portal->party($request->user());
        $overview = $this->portal->overview($party);

        return response()->json(['data' => [
            'contractor' => ContractorPortalPresenter::contractor($party),
            'summary' => $overview['summary'],
            'contracts' => array_map(ContractorPortalPresenter::contract(...), $overview['contracts']),
        ]]);
    }

    public function contract(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $party = $this->portal->party($request->user());
        $contract = $this->portal->contract($party, $workOrder);

        return response()->json(['data' => [
            ...ContractorPortalPresenter::contract($contract),
            'payments' => $contract['payments']->values()->all(),
            'bills' => $contract['bills']->map(fn (RaBill $bill) => $this->portal->billFields($bill))->values()->all(),
        ]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $party = $this->portal->party($request->user());

        return response()->json(['data' => $this->portal->payments($party)->values()->all()]);
    }

    public function bills(Request $request): JsonResponse
    {
        $party = $this->portal->party($request->user());

        return response()->json(['data' => $this->portal->bills($party)->map(fn (RaBill $bill) => $this->portal->billFields($bill))->values()->all()]);
    }

    public function bill(Request $request, RaBill $bill): JsonResponse
    {
        $party = $this->portal->party($request->user());

        return response()->json(['data' => $this->portal->billFields($this->portal->bill($party, $bill), withLines: true)]);
    }
}
