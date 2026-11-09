<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomField;
use App\Services\Crm\CustomFieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    private const MAX_PER_ENTITY = 20;

    public function __construct(private CustomFieldService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entity' => ['required', Rule::in(CrmCustomField::ENTITIES)],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(CrmCustomField::TYPES)],
            'options' => ['nullable', 'string', 'max:1000'],
            'required' => ['nullable', 'boolean'],
        ]);

        $organizationId = $request->user()->organization_id;

        if (CrmCustomField::query()->where('organization_id', $organizationId)->where('entity', $data['entity'])->count() >= self::MAX_PER_ENTITY) {
            return back()->withInput()->withErrors(['label' => __('You can add at most :count fields here.', ['count' => self::MAX_PER_ENTITY])]);
        }

        $this->service->create($organizationId, $data);

        return redirect()->route('crm.setup', ['tab' => 'fields'])->with('success', __('Field added.'));
    }

    /** Fields are switched off, not deleted, so saved values are not lost. */
    public function update(Request $request, CrmCustomField $field): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'options' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->service->update($field, [...$data, 'required' => $request->boolean('required'), 'active' => $request->boolean('active')]);

        return redirect()->route('crm.setup', ['tab' => 'fields'])->with('success', __('Field updated.'));
    }
}
