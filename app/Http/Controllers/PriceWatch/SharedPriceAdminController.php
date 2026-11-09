<?php

namespace App\Http\Controllers\PriceWatch;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PriceWatchSharedPrice;
use App\Services\PriceWatch\PriceWatchSettings;
use App\Services\PriceWatch\SharedPriceList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The shared price list and the module's limits, for platform staff. Guarded by EnsurePlatformAdmin. */
class SharedPriceAdminController extends Controller
{
    public function __construct(private SharedPriceList $list, private PriceWatchSettings $settings) {}

    public function edit(): View
    {
        return view('modules.price_watch.admin', [
            'rows' => PriceWatchSharedPrice::orderBy('name')->get(),
            'defaults' => config('modules.price_watch.defaults', []),
            'staleDays' => $this->settings->staleDays(),
            'risePct' => $this->settings->risePct(),
            'impactPct' => $this->settings->impactPct(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $row = $this->list->save($request->validate($this->list->rules()), $request->user());

        return back()->with('success', __('Shared price saved for :name.', ['name' => $row->name]));
    }

    public function destroy(PriceWatchSharedPrice $sharedPrice): RedirectResponse
    {
        $this->list->delete($sharedPrice);

        return back()->with('success', __('Removed from the shared list.'));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate($this->settings->rules());
        $this->settings->save($data);
        AuditLog::record('platform.settings_changed', $request->user(), null, ['settings' => [PriceWatchSettings::STALE_DAYS, PriceWatchSettings::RISE_PCT, PriceWatchSettings::IMPACT_PCT]]);

        return back()->with('success', __('Price watch settings saved.'));
    }
}
