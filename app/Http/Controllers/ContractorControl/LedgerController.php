<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\ContractorControl\ContractorLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LedgerController extends Controller
{
    public function __construct(private ContractorLedgerService $ledger) {}

    public function index(): View
    {
        return view('modules.contractor_control.contractors.index', ['contractors' => $this->ledger->contractors()]);
    }

    public function show(Party $party): View
    {
        return view('modules.contractor_control.contractors.show', $this->ledger->forContractor($party));
    }

    public function pdf(Party $party): Response
    {
        return Pdf::loadView('modules.contractor_control.pdf.ledger', [...$this->ledger->forContractor($party), 'organization' => org()])
            ->setPaper('a4')
            ->download('ledger-'.Str::slug($party->name).'.pdf');
    }
}
