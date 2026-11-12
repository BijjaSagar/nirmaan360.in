<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\ClientQuotation;
use App\Services\OwnerPack\QuotationShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Opens and withdraws the link to an issued quotation. The contractor sends the link himself. */
class QuotationShareController extends Controller
{
    public function __construct(private QuotationShareService $service) {}

    public function share(Request $request, ClientQuotation $quotation): RedirectResponse
    {
        $this->service->share($quotation, $request->user());

        return back()->with('success', __('A new link is ready. Any earlier link no longer works.'));
    }

    public function revoke(Request $request, ClientQuotation $quotation): RedirectResponse
    {
        $this->service->revoke($quotation, $request->user());

        return back()->with('success', __('The link has been withdrawn.'));
    }
}
