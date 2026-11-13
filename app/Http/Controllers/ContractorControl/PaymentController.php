<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorControlPresenter;
use App\Models\ContractorMilestone;
use App\Models\ContractorPayment;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\BillingExtras\PrivateFile;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\PaymentAheadGuard;
use App\Services\ContractorControl\WorkPaymentRatio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    private const STATUSES = ['IN_REVIEW', 'APPROVED', 'RETURNED', 'REJECTED'];

    public function __construct(private ContractorPaymentService $service, private WorkPaymentRatio $ratio, private PaymentAheadGuard $ahead) {}

    /** Without money.view a person sees only the requests they raised, and no amounts. */
    public function index(Request $request, Project $project): View
    {
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)]]);

        $money = $request->user()->can('money.view');
        $status = $request->query('status');

        return view('modules.contractor_control.payments.index', [
            'project' => $project,
            'money' => $money,
            'status' => $status,
            'statuses' => self::STATUSES,
            'payments' => ContractorPayment::with('workOrder', 'party', 'enteredBy', 'reversal')
                ->where('project_id', $project->id)
                ->when(! $money, fn ($query) => $query->where('entered_by', $request->user()->id))
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('paid_on')
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function create(Request $request, WorkOrder $workOrder): View
    {
        return view('modules.contractor_control.payments.form', $this->formData($request, $workOrder, new ContractorPayment));
    }

    public function store(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $this->readAgainst($request);
        $data = $request->validate($this->service->rules($workOrder));

        $payment = $this->service->raise($workOrder, $data, $request->file('bill'), $request->user());

        return redirect()->route('contractor-control.payments.show', $payment)->with('success', __('Sent for approval.'));
    }

    public function show(Request $request, ContractorPayment $payment): View
    {
        $money = $request->user()->can('money.view');
        abort_unless($money || $payment->entered_by === $request->user()->id, 403);

        $payment->load('project', 'party', 'boqItem', 'milestone', 'enteredBy', 'overrideBy', 'reversal', 'approvals.steps.user');
        $workOrder = $payment->workOrder;

        return view('modules.contractor_control.payments.show', [
            'payment' => $payment,
            'project' => $payment->project,
            'workOrder' => $workOrder,
            'money' => $money,
            'canEdit' => $this->service->canEdit($payment, $request->user()),
            'impact' => $money ? $this->ratio->impact($payment) : null,
            'ratio' => $money ? null : ContractorControlPresenter::workOnly($this->ratio->forContract($workOrder)),
            // Whether approving it now would need the owner's override.
            'ahead' => $money && $payment->status === 'IN_REVIEW' ? $this->ahead->assess($payment) : null,
        ]);
    }

    public function edit(Request $request, ContractorPayment $payment): View
    {
        abort_unless($this->service->canEdit($payment, $request->user()), 403);

        return view('modules.contractor_control.payments.form', $this->formData($request, $payment->workOrder, $payment));
    }

    public function update(Request $request, ContractorPayment $payment): RedirectResponse
    {
        abort_unless($this->service->canEdit($payment, $request->user()), 403);

        $this->readAgainst($request);
        $data = $request->validate($this->service->rules($payment->workOrder));

        $this->service->resubmit($payment, $data, $request->file('bill'), $request->user());

        return redirect()->route('contractor-control.payments.show', $payment)->with('success', __('Sent for approval.'));
    }

    public function reverse(Request $request, ContractorPayment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->service->reverse($payment, $data['reason'], $request->user());

        return back()->with('success', __('Payment reversed.'));
    }

    public function bill(ContractorPayment $payment): StreamedResponse
    {
        abort_unless($payment->bill_path, 404);

        return PrivateFile::response($payment->bill_path);
    }

    private function formData(Request $request, WorkOrder $workOrder, ContractorPayment $payment): array
    {
        $money = $request->user()->can('money.view');
        $ratio = $this->ratio->forContract($workOrder);

        return [
            'workOrder' => $workOrder->load('items.boqItem', 'party'),
            'project' => $workOrder->project,
            'payment' => $payment,
            'money' => $money,
            'ratio' => $money ? $ratio : ContractorControlPresenter::workOnly($ratio),
            'parts' => ContractorMilestone::where('work_order_id', $workOrder->id)->orderBy('sort')->orderBy('id')->get(['id', 'title']),
        ];
    }

    /** The form offers one "against" list covering items and parts. */
    private function readAgainst(Request $request): void
    {
        [$kind, $id] = array_pad(explode(':', (string) $request->input('against')), 2, null);

        $request->merge([
            'boq_item_id' => $kind === 'item' ? $id : null,
            'contractor_milestone_id' => $kind === 'part' ? $id : null,
        ]);
    }
}
