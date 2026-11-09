<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access\DeviceService;
use App\Services\Access\TeamAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function __construct(private DeviceService $devices, private TeamAccessService $access) {}

    public function mine(Request $request): View
    {
        return view('modules.access.devices', ['devices' => $this->devices->list($request->user())]);
    }

    public function revokeMine(Request $request, int $token): RedirectResponse
    {
        $this->devices->revoke($request->user(), $token);

        return back()->with('success', __('Device signed out.'));
    }

    public function revokeAllMine(Request $request): RedirectResponse
    {
        $this->devices->revokeAll($request->user());

        return back()->with('success', __('All devices signed out.'));
    }

    public function revoke(Request $request, User $user, int $token): RedirectResponse
    {
        $this->access->assertCanManage($request->user(), $user);
        $this->devices->revoke($user, $token);

        return back()->with('success', __('Device signed out.'));
    }

    public function revokeAll(Request $request, User $user): RedirectResponse
    {
        $this->access->assertCanManage($request->user(), $user);
        $this->devices->revokeAll($user);

        return back()->with('success', __('All devices signed out.'));
    }
}
