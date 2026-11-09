<?php

namespace App\Http\Controllers\OfflinePlan;

use App\Http\Controllers\Controller;
use App\Models\DeviceLicence;
use App\Services\OfflinePlan\DeviceLicenceService;
use App\Services\OfflinePlan\OfflinePlanPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The one screen the web app shows a company on the offline plan: its plan, its phones and the way up. */
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

    public function free(Request $request, DeviceLicence $deviceLicence): RedirectResponse
    {
        $this->licences->free($deviceLicence, $request->user());

        return back()->with('success', __('Phone freed. Its slot is open now; if that phone stays offline it keeps working until :date.', [
            'date' => $deviceLicence->expires_at->format('d M Y'),
        ]));
    }
}
