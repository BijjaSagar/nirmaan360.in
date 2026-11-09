<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.edit', [
            'organization' => $request->user()->organization,
            'rules' => ApprovalRule::get()->keyBy('doc_type'),
            'docTypes' => ['measurement' => 'Measurement', 'ra_bill' => 'RA bill', 'dpr' => 'DPR'],
            'levelRoles' => config('buildos.approval_level_roles'),
        ]);
    }

    public function updateOrganization(Request $request): RedirectResponse
    {
        $request->user()->organization->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'pan' => ['nullable', 'string', 'size:10'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string', 'max:60'],
        ]));

        return back()->with('success', __('Company details saved.'));
    }

    public function updateApprovals(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*' => ['nullable', 'array', 'max:4'],
            'rules.*.*' => ['nullable', Rule::in(array_keys(config('buildos.approval_level_roles')))],
        ]);

        foreach (['measurement', 'ra_bill', 'dpr'] as $docType) {
            ApprovalRule::updateOrCreate(
                ['organization_id' => $request->user()->organization_id, 'doc_type' => $docType],
                ['levels' => array_values(array_unique(array_filter($data['rules'][$docType] ?? []))), 'active' => true],
            );
        }

        return back()->with('success', __('Approval rules saved.'));
    }
}
