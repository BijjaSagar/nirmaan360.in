<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetMaintenance;
use App\Services\Assets\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    use RedirectsToAsset;

    public function __construct(private MaintenanceService $service) {}

    public function index(): View
    {
        return view('modules.assets.maintenance', ['schedule' => $this->service->schedule()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = $this->service->add($request->validate($this->service->rules($request->user())), $request->user());

        return $this->backToAsset($request, $item->asset_id, __('Service scheduled.'));
    }

    public function complete(Request $request, AssetMaintenance $item): RedirectResponse
    {
        $this->service->markDone($item, $request->validate($this->service->doneRules()), $request->user());

        return $this->backToAsset($request, $item->asset_id, __('Service recorded.'));
    }

    public function toggle(Request $request, AssetMaintenance $item): RedirectResponse
    {
        $this->service->toggle($item);

        return $this->backToAsset($request, $item->asset_id, __('Schedule updated.'));
    }
}
