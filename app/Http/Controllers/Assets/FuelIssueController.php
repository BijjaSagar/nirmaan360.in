<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Services\Assets\PlantLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FuelIssueController extends Controller
{
    use RedirectsToAsset;

    public function store(Request $request, PlantLogService $service): RedirectResponse
    {
        $issue = $service->issueFuel($request->validate($service->fuelRules($request->user())), $request->user());

        return $this->backToAsset($request, $issue->asset_id, __('Fuel issue saved.'));
    }
}
