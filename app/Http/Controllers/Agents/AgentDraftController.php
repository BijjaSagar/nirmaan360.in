<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\Dpr;
use App\Services\DprService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The "Edit" button on a draft report an agent wrote. */
class AgentDraftController extends Controller
{
    public function edit(Dpr $dpr): View
    {
        abort_unless($dpr->isEditable(), 409, __('Only draft or returned DPRs can be changed.'));

        return view('modules.agents.dpr-edit', [
            'dpr' => $dpr->load('manpower', 'machinery', 'workItems'),
            'project' => $dpr->project,
            'sites' => $dpr->project->sites,
            'boqItems' => $dpr->project->boqItems()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, Dpr $dpr, DprService $service): RedirectResponse
    {
        $data = $request->validate($service->rules($dpr->project));
        unset($data['client_uuid']);

        $service->update($dpr, $data);

        return redirect()->route('dprs.show', $dpr)->with('success', __('DPR saved as draft.'));
    }
}
