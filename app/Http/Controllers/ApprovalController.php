<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $service) {}

    public function index(Request $request): View
    {
        [$mine, $waiting] = $this->service->inbox($request->user());

        return view('approvals.index', [
            'mine' => $mine,
            'waiting' => $waiting,
            'recent' => Approval::with('approvable', 'submitter')->where('status', '!=', 'IN_REVIEW')->latest('completed_at')->take(15)->get(),
        ]);
    }

    public function act(Request $request, Approval $approval): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'return', 'reject'])],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->service->act($approval, $request->user(), $data['action'], $data['remark'] ?? null);

        return back()->with('success', [
            'approve' => __('Approved.'),
            'return' => __('Returned with remarks.'),
            'reject' => __('Rejected.'),
        ][$data['action']]);
    }
}
