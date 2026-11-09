<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\RaBill;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Lets a client review and sign off a bill through a signed link, without an account. */
class ClientSignoffController extends Controller
{
    public function show(RaBill $bill): View
    {
        $bill->load('items', 'deductions', 'project', 'organization');

        return view('public.client-bill', ['bill' => $bill, 'approval' => $this->approvalFor($bill)]);
    }

    public function act(Request $request, RaBill $bill, ApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'action' => ['required', Rule::in(['approve', 'return'])],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $approval = $this->approvalFor($bill);
        abort_unless($approval, 409, __('This document is not waiting for client sign-off.'));

        $approvals->actAsClient($approval, $data['name'], $data['action'], $data['remark'] ?? null);

        return back()->with('success', __('Thank you. Your response has been recorded.'));
    }

    private function approvalFor(RaBill $bill): ?Approval
    {
        $approval = Approval::withoutGlobalScopes()
            ->where('approvable_type', $bill->getMorphClass())
            ->where('approvable_id', $bill->id)
            ->latest('id')
            ->first();

        return $approval?->status === 'IN_REVIEW' && $approval->currentRole() === 'client' ? $approval : null;
    }
}
