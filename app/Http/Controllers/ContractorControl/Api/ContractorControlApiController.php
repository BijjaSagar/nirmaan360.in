<?php

namespace App\Http\Controllers\ContractorControl\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorControlPresenter;
use App\Models\BoqItem;
use App\Models\ContractorPayment;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\BillingExtras\PrivateFile;
use App\Services\ContractorControl\BoqLineTenders;
use App\Services\ContractorControl\ContractorLedgerService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\WorkPaymentRatio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractorControlApiController extends Controller
{
    public function __construct(
        private WorkPaymentRatio $ratio,
        private ContractorLedgerService $ledger,
        private ContractorPaymentService $service,
        private BoqLineTenders $lineTenders,
    ) {}

    public function board(Request $request, Project $project): JsonResponse
    {
        $money = $request->user()->can('money.view');

        return response()->json([
            'data' => $this->ratio->forProject($project)->map(fn (array $row) => ContractorControlPresenter::contract($row, $money))->values()->all(),
        ]);
    }

    public function contract(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $money = $request->user()->can('money.view');
        $data = $this->ledger->forContract($workOrder);

        return response()->json(['data' => [
            ...ContractorControlPresenter::contract($data['ratio'], $money),
            'lines' => array_map(fn (array $row) => ContractorControlPresenter::line($row, $money), $data['lines']),
            'milestones' => array_map(fn (array $row) => ContractorControlPresenter::milestone($row, $money), $data['milestones']['parts']),
        ]]);
    }

    /** Without money.view, only the requests this person raised, with no amounts. */
    public function payments(Request $request, Project $project): JsonResponse
    {
        $money = $request->user()->can('money.view');

        $payments = ContractorPayment::with('workOrder', 'party', 'enteredBy', 'currentApproval')
            ->where('project_id', $project->id)
            ->when(! $money, fn ($query) => $query->where('entered_by', $request->user()->id))
            ->latest('paid_on')
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $payments->getCollection()->map(fn (ContractorPayment $payment) => ContractorControlPresenter::payment($payment, $money))->all(),
            'meta' => ['current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage()],
        ]);
    }

    public function requestPayment(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate($this->service->rules($workOrder));

        $payment = $this->service->raise($workOrder, $data, $request->file('bill'), $request->user());
        $payment->load('workOrder', 'party', 'enteredBy', 'currentApproval');

        return response()->json(
            ['data' => ContractorControlPresenter::payment($payment, $request->user()->can('money.view'))],
            $payment->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function showPayment(Request $request, ContractorPayment $payment): JsonResponse
    {
        $money = $request->user()->can('money.view');
        abort_unless($money || $payment->entered_by === $request->user()->id, 403);

        $payment->load('workOrder', 'party', 'enteredBy', 'currentApproval');

        return response()->json(['data' => ContractorControlPresenter::payment($payment, $money, $money ? $this->ratio->impact($payment) : null)]);
    }

    /** The tenders a BOQ line is in. Without money.view: that they exist and who quoted, with no prices. */
    public function lineTenders(Request $request, BoqItem $item): JsonResponse
    {
        return response()->json([
            'data' => ContractorControlPresenter::lineTenders($this->lineTenders->forLine($item), $request->user()->can('money.view')),
        ]);
    }

    public function bill(ContractorPayment $payment): StreamedResponse
    {
        abort_unless($payment->bill_path, 404);

        return PrivateFile::response($payment->bill_path);
    }
}
