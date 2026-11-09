<?php

namespace App\Http\Controllers\ContractorPortal;

use App\Http\Controllers\Controller;
use App\Models\RaBill;
use App\Models\WorkOrder;
use App\Services\ContractorPortal\ContractorPortalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** The contractor's own read-only view. Every page first resolves the contractor behind the login. */
class PortalController extends Controller
{
    public function __construct(private ContractorPortalService $portal) {}

    public function home(Request $request): View
    {
        $party = $this->portal->party($request->user());

        return view('modules.contractor_portal.home', ['party' => $party, ...$this->portal->overview($party)]);
    }

    public function contract(Request $request, WorkOrder $workOrder): View
    {
        $party = $this->portal->party($request->user());

        return view('modules.contractor_portal.contract', ['party' => $party, 'contract' => $this->portal->contract($party, $workOrder)]);
    }

    public function payments(Request $request): View
    {
        $party = $this->portal->party($request->user());

        return view('modules.contractor_portal.payments', ['party' => $party, 'payments' => $this->portal->payments($party)]);
    }

    public function bills(Request $request): View
    {
        $party = $this->portal->party($request->user());

        return view('modules.contractor_portal.bills', ['party' => $party, 'bills' => $this->portal->bills($party)]);
    }

    public function bill(Request $request, RaBill $bill): View
    {
        $party = $this->portal->party($request->user());

        return view('modules.contractor_portal.bill', ['party' => $party, 'bill' => $this->portal->bill($party, $bill)]);
    }

    /** The same document the company prints, since every figure on it is the contractor's own. */
    public function billPdf(Request $request, RaBill $bill): Response
    {
        $party = $this->portal->party($request->user());
        $bill = $this->portal->bill($party, $bill);

        return Pdf::loadView('pdf.ra-bill', ['bill' => $bill])
            ->setPaper('a4', 'landscape')
            ->download($bill->number.'.pdf');
    }
}
