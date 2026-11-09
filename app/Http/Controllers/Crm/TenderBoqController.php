<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTender;
use App\Models\CrmTenderBoqItem;
use App\Services\Crm\TenderBoqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenderBoqController extends Controller
{
    public function __construct(private TenderBoqService $service) {}

    public function store(Request $request, CrmTender $tender): RedirectResponse
    {
        $this->service->add($tender, $request->validate($this->service->rules()));

        return redirect()->to(route('crm.tenders.show', $tender).'#boq')->with('success', __('BOQ line added.'));
    }

    public function update(Request $request, CrmTenderBoqItem $line): RedirectResponse
    {
        $this->service->update($line, $request->validate($this->service->rules()));

        return redirect()->to(route('crm.tenders.show', $line->tender_id).'#boq')->with('success', __('BOQ line updated.'));
    }

    public function destroy(CrmTenderBoqItem $line): RedirectResponse
    {
        $this->service->delete($line);

        return redirect()->to(route('crm.tenders.show', $line->tender_id).'#boq')->with('success', __('BOQ line removed.'));
    }

    public function import(Request $request, CrmTender $tender): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx']]);

        $file = $request->file('file');
        $count = $this->service->import($tender, $file->getRealPath(), $file->getClientOriginalExtension());

        return redirect()->to(route('crm.tenders.show', $tender).'#boq')->with('success', __(':count BOQ rows imported.', ['count' => $count]));
    }
}
