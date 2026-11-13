<?php

namespace App\Http\Controllers\OfflinePlan;

use App\Http\Controllers\Controller;
use App\Models\DeviceLicence;
use App\Services\OfflinePlan\DeviceLicenceService;
use App\Services\OfflinePlan\OfflinePlanPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The web app's screens for a company on the offline plan: its plan, its phones and the way up, and its phones alone. */
class OfflineDeviceController extends Controller
{
    public function __construct(private DeviceLicenceService $licences, private OfflinePlanPolicy $policy) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $organization = $user->organization;
        $canManage = $user->can('billing.manage');

        return view('modules.offline_plan.devices', [
            'organization' => $organization,
            'offline' => $this->policy->isOffline($organization),
            'plan' => $organization->planConfig(),
            'maxDevices' => $this->policy->maxDevices($organization),
            'activeDevices' => $this->licences->activeCount($organization),
            'licences' => $canManage ? $this->licences->list($organization) : collect(),
            'canManage' => $canManage,
            'onlinePlans' => array_filter(config('buildos.plans'), fn ($plan, $key) => $plan['buyable'] && $key !== OfflinePlanPolicy::plan(), ARRAY_FILTER_USE_BOTH),
        ]);
    }

    /**
     * Only the phones, for the address the field app shows (manage_url): the full page also lists plan prices
     * and an upgrade button, which the app may not point people to (App Store guideline 3.1.1).
     */
    public function phones(Request $request): View
    {
        $organization = $request->user()->organization;
        $canManage = $request->user()->can('billing.manage');

        return view('modules.offline_plan.phones', [
            'maxDevices' => $this->policy->maxDevices($organization),
            'activeDevices' => $this->licences->activeCount($organization),
            'licences' => $canManage ? $this->licences->list($organization) : collect(),
            'canManage' => $canManage,
        ]);
    }

    public function free(Request $request, DeviceLicence $deviceLicence): RedirectResponse
    {
        $this->licences->free($deviceLicence, $request->user());

        return back()->with('success', __('Phone freed. Its slot is open now; if that phone stays offline it keeps working until :date.', [
            'date' => $deviceLicence->expires_at->format('d M Y'),
        ]));
    }
}
