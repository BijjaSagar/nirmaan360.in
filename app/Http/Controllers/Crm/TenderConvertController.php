<?php

namespace App\Http\Controllers\Crm;

use App\Exceptions\PlanLimitReachedException;
use App\Http\Controllers\Controller;
use App\Models\CrmTender;
use App\Services\Crm\TenderConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenderConvertController extends Controller
{
    public function __construct(private TenderConversionService $service) {}

    public function show(CrmTender $tender): View|RedirectResponse
    {
        if ($tender->isConverted()) {
            return redirect()->route('projects.show', $tender->project_id);
        }

        if ($tender->stage !== 'won') {
            return redirect()->route('crm.tenders.show', $tender)->withErrors(['tender' => __('Only a won tender can be converted to a project.')]);
        }

        return view('modules.crm.tenders.convert', ['tender' => $tender->load('party'), 'preview' => $this->service->preview($tender)]);
    }

    public function store(Request $request, CrmTender $tender): RedirectResponse
    {
        $data = $request->validate($this->service->rules($request->user()));

        try {
            $result = $this->service->convert($tender, $data, $request->user());
        } catch (PlanLimitReachedException $exception) {
            return back()->withInput()->withErrors(['plan' => $exception->getMessage()]);
        }

        return redirect()->route('projects.show', $result['project'])->with('success', $result['created']
            ? __('Project created from the tender with :lines BOQ lines and :documents documents.', ['lines' => $result['boq_lines'], 'documents' => $result['documents']])
            : __('This tender is already a project.'));
    }
}
