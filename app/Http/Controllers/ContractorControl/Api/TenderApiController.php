<?php

namespace App\Http\Controllers\ContractorControl\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorControlPresenter;
use App\Models\BoqItem;
use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Models\User;
use App\Services\BillingExtras\PrivateFile;
use App\Services\ContractorControl\BoqLineTenders;
use App\Services\ContractorControl\ContractorTenderService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The field app's side of the web's "Tenders for this BOQ line" page: start a tender from a line,
 * add contractors and their quotes, compare them, and choose one. Each route carries the same
 * permissions as its web twin, and the presenter leaves every price out without money.view.
 */
class TenderApiController extends Controller
{
    public function __construct(private ContractorTenderService $tenders, private BoqLineTenders $lines) {}

    /** A new tender holding just this line. A list of lines sent along is ignored, as on the web. */
    public function storeForLine(Request $request, BoqItem $item): JsonResponse
    {
        $request->merge(['boq_item_ids' => [$item->id]]);

        $tender = $this->tenders->create($item->project, $request->validate($this->tenders->rules($item->project)), $request->user());

        return response()->json(['data' => $this->present($tender, $request->user())], $tender->wasRecentlyCreated ? 201 : 200);
    }

    /** The company's subcontractors, for the picker on the quote form. */
    public function contractors(Request $request): JsonResponse
    {
        $search = $request->validate(['q' => ['nullable', 'string', 'max:160']])['q'] ?? null;

        return response()->json(
            $this->tenders->contractors($request->user()->organization_id, $search)
                ->paginate(100)
                ->withQueryString()
                ->through(fn (Party $party) => ContractorControlPresenter::contractorOption($party)),
        );
    }

    /** A name the company already has comes back as that contractor, with 200 instead of 201. */
    public function storeContractor(Request $request): JsonResponse
    {
        $party = $this->tenders->addContractor($request->user()->organization_id, $request->validate($this->tenders->contractorRules()));
        $isNew = $party->wasRecentlyCreated;

        return response()->json([
            'data' => ContractorControlPresenter::contractorOption($party),
            'message' => $isNew ? null : __(':name is already on your list.', ['name' => $party->name]),
        ], $isNew ? 201 : 200);
    }

    public function show(Request $request, ContractorTender $tender): JsonResponse
    {
        return response()->json(['data' => $this->present($tender, $request->user())]);
    }

    /** Multipart, so the contractor's quotation file can come along. */
    public function storeBid(Request $request, ContractorTender $tender): JsonResponse
    {
        $bid = $this->tenders->addBid($tender, $request->validate($this->tenders->bidRules($tender)), $request->user(), $request->file('quotation'));

        // A repeated client_uuid returns the quote first saved, and the tender it belongs to.
        return response()->json([
            'data' => $this->present($bid->tender, $request->user()),
            'bid_id' => $bid->id,
        ], $bid->wasRecentlyCreated ? 201 : 200);
    }

    /** The quotation a contractor sent. It is all prices, so the route needs money.view. */
    public function quotation(ContractorBid $bid): StreamedResponse
    {
        abort_unless($bid->quotation_path, 404);

        return PrivateFile::response($bid->quotation_path);
    }

    /** Choosing a quote makes the work order at its rates. Choosing the same quote again returns that work order. */
    public function award(Request $request, ContractorTender $tender): JsonResponse
    {
        $data = $request->validate($this->tenders->awardRules($tender));

        $workOrder = $this->tenders->award($tender, ContractorBid::findOrFail($data['bid_id']), $data, $request->user());

        return response()->json([
            'data' => $this->present($tender, $request->user()),
            'work_order' => ['id' => $workOrder->id, 'number' => $workOrder->number],
        ], $workOrder->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Read again after a write, so the quotes, the lowest and the status are the ones now saved.
     * The comparison is what GET /tenders/{tender} shows, so it goes only to someone allowed that read.
     * A role that may enter quotes or choose one without seeing the comparison gets the tender's own
     * record and nothing about the other quotes, as on the web, where those actions just go back.
     */
    private function present(ContractorTender $tender, User $user): array
    {
        $tender = $tender->fresh();

        if (! $user->can('boq.view') || ! $user->can('contractor.view')) {
            return [
                'id' => $tender->id,
                'number' => $tender->number,
                'title' => $tender->title,
                'status' => $tender->status,
                'work_order_id' => $tender->work_order_id,
                'work_order_number' => $tender->workOrder?->number,
            ];
        }

        return ContractorControlPresenter::tender($tender, $this->tenders->comparison($tender), Money::visible($user), $this->lines->abilities($user, $tender));
    }
}
