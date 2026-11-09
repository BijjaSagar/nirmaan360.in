<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationFailure;
use App\Services\Notify\Integrations;
use App\Services\Notify\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Guarded by EnsurePlatformAdmin in routes/modules/notify.php. */
class IntegrationController extends Controller
{
    public function __construct(private Integrations $integrations) {}

    public function index(Notifier $notifier): View
    {
        return view('modules.notify.integrations', [
            'cards' => $this->integrations->cards(),
            'emailConnected' => $notifier->connected('email'),
            // The platform admin looks across every company, so the tenant scope must not apply.
            'failures' => NotificationFailure::withoutGlobalScopes()->with('organization')->latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, string $integration): RedirectResponse
    {
        $data = $request->validate($this->integrations->rules($integration));
        $changed = $this->integrations->save($integration, $data, $request->boolean('remove'));

        // Names only, never values: some of these are secrets.
        AuditLog::record('platform.settings_changed', $request->user(), null, ['settings' => $changed]);

        return back()->with('success', __('Integration settings saved.'));
    }

    public function test(Request $request, string $integration): RedirectResponse
    {
        $phone = $integration === 'sms' ? $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'] : null;
        $error = $this->integrations->test($integration, $phone);

        return $error === null
            ? back()->with('success', $this->integrations->successMessage($integration))
            : back()->withErrors([$integration => $error]);
    }
}
