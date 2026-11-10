<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Seo\SeoSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Search and analytics settings for platform staff. Guarded by EnsurePlatformAdmin in routes/modules/seo.php. */
class SeoSettingsController extends Controller
{
    public function __construct(private SeoSettings $settings) {}

    public function edit(Request $request): View
    {
        $locale = (string) $request->query('locale', 'en');

        return view('modules.seo.admin', $this->settings->screen(array_key_exists($locale, config('buildos.locales')) ? $locale : 'en'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate($this->settings->rules(), $this->settings->messages());

        $changed = $this->settings->save(
            $data,
            $request->file('share_image'),
            $request->boolean('remove_share_image'),
            $request->boolean('ga4_include_app'),
        );

        // Names only, as on the other settings screens.
        AuditLog::record('platform.settings_changed', $request->user(), null, ['settings' => $changed]);

        return redirect()->route('admin.seo', ['locale' => $data['locale']])->with('success', __('Search and analytics settings saved.'));
    }
}
