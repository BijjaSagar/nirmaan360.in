<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Services\OwnerPack\QuotationTranslations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A person accepts the translation of one line of a draft quotation, or takes it back. Only an
 * accepted translation is printed, and always beside the description. These routes call no model.
 */
class QuotationTranslationController extends Controller
{
    public function __construct(private QuotationTranslations $translations) {}

    public function accept(Request $request, ClientQuotation $quotation, ClientQuotationItem $item): RedirectResponse
    {
        $data = $request->validate(['seen' => ['required', 'string', 'size:64']]);
        $this->translations->accept($quotation, $item, $data['seen'], $request->user());

        return redirect()->to(route('quotations.show', $quotation).'#translations')
            ->with('success', __('Translation accepted. The quotation prints it beside the description.'));
    }

    public function withdraw(Request $request, ClientQuotation $quotation, ClientQuotationItem $item): RedirectResponse
    {
        $this->translations->withdraw($quotation, $item, $request->user());

        return redirect()->to(route('quotations.show', $quotation).'#translations')
            ->with('success', __('The translation is no longer printed. The line shows its description alone.'));
    }
}
