<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmOutcomeReason;
use App\Services\Crm\ReasonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReasonController extends Controller
{
    public function __construct(private ReasonService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(CrmOutcomeReason::KINDS)],
            'label' => ['required', 'string', 'max:120'],
        ]);

        $this->service->create($request->user()->organization_id, $data['kind'], $data['label']);

        return redirect()->route('crm.setup', ['tab' => 'reasons'])->with('success', __('Reason added.'));
    }

    /** Reasons are switched off, not deleted, so past tenders keep theirs. */
    public function update(Request $request, CrmOutcomeReason $reason): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120']]);

        $this->service->update($reason, $data['label'], $request->boolean('active'));

        return redirect()->route('crm.setup', ['tab' => 'reasons'])->with('success', __('Reason updated.'));
    }
}
