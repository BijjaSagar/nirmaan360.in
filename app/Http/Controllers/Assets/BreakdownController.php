<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetBreakdown;
use App\Services\Assets\PlantLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BreakdownController extends Controller
{
    use RedirectsToAsset;

    public function __construct(private PlantLogService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $breakdown = $this->service->reportBreakdown($request->validate($this->service->breakdownRules($request->user())), $request->user());

        return $this->backToAsset($request, $breakdown->asset_id, __('Breakdown reported.'));
    }

    public function update(Request $request, AssetBreakdown $breakdown): RedirectResponse
    {
        $this->service->resolveBreakdown($breakdown, $request->validate($this->service->resolveRules()));

        return $this->backToAsset($request, $breakdown->asset_id, __('Breakdown resolved.'));
    }
}
