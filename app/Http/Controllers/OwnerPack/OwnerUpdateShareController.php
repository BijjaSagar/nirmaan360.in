<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\OwnerUpdate;
use App\Services\OwnerPack\OwnerUpdateShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Opens and withdraws the link to an approved weekly update. The contractor sends the link himself. */
class OwnerUpdateShareController extends Controller
{
    public function __construct(private OwnerUpdateShareService $service) {}

    public function share(Request $request, OwnerUpdate $update): RedirectResponse
    {
        $this->service->share($update, $request->user());

        return back()->with('success', __('A new link is ready. Any earlier link no longer works.'));
    }

    public function revoke(Request $request, OwnerUpdate $update): RedirectResponse
    {
        $this->service->revoke($update, $request->user());

        return back()->with('success', __('The link has been withdrawn.'));
    }
}
