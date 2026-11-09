<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BillingPayment;
use App\Models\ContactRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The platform owner's view across every company. Anyone else gets a 404. */
class AdminController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('admin.index', [
            'organizations' => Organization::withCount('users')->latest('id')->paginate(30),
            'payments' => BillingPayment::withoutGlobalScopes()->with('organization')->where('status', 'paid')->latest('paid_at')->take(15)->get(),
            'requests' => ContactRequest::latest('id')->take(30)->get(),
            'plans' => config('buildos.plans'),
        ]);
    }

    public function updateOrganization(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('buildos.plans')))],
            'plan_status' => ['required', Rule::in(['active', 'trial'])],
            'trial_ends_at' => ['nullable', 'date'],
            'paid_until' => ['nullable', 'date'],
        ]);

        $old = $organization->only(array_keys($data));
        $organization->update($data);
        AuditLog::record('platform.plan_changed', $organization, $old, $data);

        return back()->with('success', __('Company plan updated.'));
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isPlatformAdmin(), 404);
    }
}
