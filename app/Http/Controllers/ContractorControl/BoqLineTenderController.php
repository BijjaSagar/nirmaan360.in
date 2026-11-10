<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ContractorControlPresenter;
use App\Models\BoqItem;
use App\Models\Party;
use App\Services\ContractorControl\BoqLineTenders;
use App\Services\ContractorControl\ContractorTenderService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Opened from a line on the BOQ page: the tenders that line is in, and the quotes for it. */
class BoqLineTenderController extends Controller
{
    public function __construct(private BoqLineTenders $lines, private ContractorTenderService $tenders) {}

    /** Anyone with contractor.view sees who quoted. The presenter leaves every price out without money.view. */
    public function show(Request $request, BoqItem $item): View
    {
        $user = $request->user();
        $money = Money::visible($user);
        $canQuote = $money && $user->can('contractor.tender');

        return view('modules.contractor_control.tenders.line', [
            'project' => $item->project,
            'line' => ContractorControlPresenter::lineTenders($this->lines->forLine($item), $money),
            'money' => $money,
            'canQuote' => $canQuote,
            'canAward' => $money && $user->can('contractor.award'),
            'contractors' => $canQuote ? Party::where('type', 'subcontractor')->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    /** A new tender holding just this line. More lines can be added from the full tender form. */
    public function store(Request $request, BoqItem $item): RedirectResponse
    {
        $request->merge(['boq_item_ids' => [$item->id]]);

        $this->tenders->create($item->project, $request->validate($this->tenders->rules($item->project)), $request->user());

        return redirect()->route('boq.tenders.show', $item)->with('success', __('Tender created. Now add the quotes.'));
    }
}
