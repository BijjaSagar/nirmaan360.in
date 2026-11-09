<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Models\StaffLeave;
use App\Services\Payroll\StaffClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffClaimController extends Controller
{
    public function __construct(private StaffClaimService $service) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $canDecide = $user->can('claim.approve');

        return view('modules.payroll.staff', [
            'canDecide' => $canDecide,
            'leaveTypes' => StaffClaimService::LEAVE_TYPES,
            'categories' => config('buildos.expense_categories'),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'myLeaves' => StaffLeave::where('user_id', $user->id)->latest('id')->take(20)->get(),
            'myClaims' => ExpenseClaim::with('project')->where('user_id', $user->id)->latest('id')->take(20)->get(),
            'leavesToDecide' => $canDecide ? StaffLeave::with('user')->where('status', 'PENDING')->latest('id')->get() : collect(),
            'claimsToDecide' => $canDecide ? ExpenseClaim::with('user', 'project')->whereIn('status', ['PENDING', 'APPROVED'])->latest('id')->get() : collect(),
        ]);
    }

    public function storeLeave(Request $request): RedirectResponse
    {
        $this->service->requestLeave($request->user(), $request->validate($this->service->leaveRules()));

        return back()->with('success', __('Leave requested. It now waits for approval.'));
    }

    public function decideLeave(Request $request, StaffLeave $leave): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:190']]);

        $this->service->decideLeave($leave, $request->user(), $data['action'], $data['note'] ?? null);

        return back()->with('success', $data['action'] === 'approve' ? __('Leave approved.') : __('Leave rejected.'));
    }

    public function cancelLeave(Request $request, StaffLeave $leave): RedirectResponse
    {
        $this->service->cancelLeave($leave, $request->user());

        return back()->with('success', __('Leave request withdrawn.'));
    }

    public function storeClaim(Request $request): RedirectResponse
    {
        $this->service->submitClaim($request->user(), $request->validate($this->service->claimRules()));

        return back()->with('success', __('Claim submitted. It now waits for approval.'));
    }

    public function decideClaim(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:190']]);

        $this->service->decideClaim($claim, $request->user(), $data['action'], $data['note'] ?? null);

        return back()->with('success', $data['action'] === 'approve' ? __('Claim approved.') : __('Claim rejected.'));
    }

    public function payClaim(ExpenseClaim $claim): RedirectResponse
    {
        $this->service->markClaimPaid($claim);

        return back()->with('success', __('Claim marked as paid.'));
    }

    public function cancelClaim(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->service->cancelClaim($claim, $request->user());

        return back()->with('success', __('Claim withdrawn.'));
    }
}
