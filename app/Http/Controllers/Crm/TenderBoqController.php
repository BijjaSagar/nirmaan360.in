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
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,tsv,xlsx,xlsm,xls,ods']]);

        $file = $request->file('file');
        $result = $this->service->import($tender, $file->getRealPath(), $file->getClientOriginalExtension());
        $message = __(':count BOQ rows imported.', ['count' => $result['count']]);

        if ($result['kept'] !== []) {
            // Nor does a line already on the tender change a figure silently: the result says which kept their own.
            $message .= ' '.__(':count lines already on the tender kept their own quantity or rate, because the file gives none or only an amount to work it out from (:codes).', [
                'count' => count($result['kept']),
                'codes' => implode(', ', array_slice($result['kept'], 0, 10)).(count($result['kept']) > 10 ? ', …' : ''),
            ]);
        }

        if ($result['renumbered'] !== []) {
            // There is no preview on this page: the result says which lines did not update the line with their number.
            $codes = array_map(fn (array $line) => $line['from'].' → '.$line['to'], array_slice($result['renumbered'], 0, 10));
            $message .= ' '.__(':count lines carry the number of a different line already on the tender, so they were added as new lines (:codes). The lines already there were left as they were: check them, and delete any line the file replaces.', [
                'count' => count($result['renumbered']),
                'codes' => implode(', ', $codes).(count($result['renumbered']) > 10 ? ', …' : ''),
            ]);
        }

        return redirect()->to(route('crm.tenders.show', $tender).'#boq')->with('success', $message);
    }
}
