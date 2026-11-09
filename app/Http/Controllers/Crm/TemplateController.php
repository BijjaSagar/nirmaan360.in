<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTemplate;
use App\Services\Crm\MessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(private MessageService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $this->service->create($request->user()->organization_id, $request->validate($this->service->rules()));

        return redirect()->route('crm.setup', ['tab' => 'templates'])->with('success', __('Template added.'));
    }

    public function update(Request $request, CrmTemplate $template): RedirectResponse
    {
        $data = $request->validate($this->service->rules());
        $this->service->update($template, [...$data, 'active' => $request->boolean('active')]);

        return redirect()->route('crm.setup', ['tab' => 'templates'])->with('success', __('Template updated.'));
    }

    public function destroy(CrmTemplate $template): RedirectResponse
    {
        $this->service->delete($template);

        return redirect()->route('crm.setup', ['tab' => 'templates'])->with('success', __('Template deleted.'));
    }
}
