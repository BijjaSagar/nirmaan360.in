<?php

namespace App\Http\Controllers\Assets;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Forms on an asset's page send "_tab" so the page reopens on the section that was used. */
trait RedirectsToAsset
{
    protected function backToAsset(Request $request, int $assetId, string $message): RedirectResponse
    {
        $tab = $request->input('_tab');

        return in_array($tab, ['log', 'fuel', 'breakdowns', 'maintenance', 'transfers', 'bills'], true)
            ? redirect()->route('assets.show', ['asset' => $assetId, 'tab' => $tab])->with('success', $message)
            : back()->with('success', $message);
    }
}
