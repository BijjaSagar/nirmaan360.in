<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Services\OwnerPack\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The payment stages of a quotation. They change only while it is a draft; reaching a stage is recorded after it is issued. */
class QuotationStageController extends Controller
{
    public function __construct(private QuotationService $service) {}

    public function store(Request $request, ClientQuotation $quotation): RedirectResponse
    {
        $this->service->addStage($quotation, $request->validate($this->service->stageRules()), $request->user());

        return back()->with('success', __('Stage added.'));
    }

    public function update(Request $request, ClientPaymentStage $stage): RedirectResponse
    {
        $this->service->updateStage($stage, $request->validate($this->service->stageRules()), $request->user());

        return back()->with('success', __('Stage saved.'));
    }

    public function destroy(Request $request, ClientPaymentStage $stage): RedirectResponse
    {
        $this->service->removeStage($stage, $request->user());

        return back()->with('success', __('Stage removed.'));
    }

    public function reached(Request $request, ClientPaymentStage $stage): RedirectResponse
    {
        $this->service->markReached($stage, $request->validate($this->service->reachedRules()), $request->user());

        return back()->with('success', __('Saved.'));
    }
}
