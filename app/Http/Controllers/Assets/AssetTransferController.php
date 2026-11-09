<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\Assets\AssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssetTransferController extends Controller
{
    use RedirectsToAsset;

    public function store(Request $request, AssetService $service): RedirectResponse
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', org_exists('assets')],
            ...$service->transferRules($request->user()),
        ]);

        $asset = Asset::findOrFail($data['asset_id']);
        $service->transfer($asset, $data, $request->user());

        return $this->backToAsset($request, $asset->id, __('Asset moved.'));
    }
}
