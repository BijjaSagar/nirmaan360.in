<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\NotificationFailure;
use App\Services\Notify\Integrations;
use App\Services\Notify\Notifier;
use App\Services\Notify\PushDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/** Guarded by EnsurePlatformAdmin in routes/modules/notify.php. */
class IntegrationController extends Controller
{
    public function __construct(private Integrations $integrations) {}

    public function index(Request $request, Notifier $notifier): View
    {
        return view('modules.notify.integrations', [
            'cards' => $this->integrations->cards(),
            'emailConnected' => $notifier->connected('email'),
            // The admin's own phones, which is where the test push goes. Their company's scope does not matter here.
            'ownPhones' => DeviceToken::withoutGlobalScopes()->where('user_id', $request->user()->id)->count(),
            // The platform admin looks across every company, so the tenant scope must not apply.
            'failures' => NotificationFailure::withoutGlobalScopes()->with('organization')->latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, string $integration): RedirectResponse
    {
        $validator = Validator::make($request->all(), $this->integrations->rules($integration));

        if ($validator->fails()) {
            // Laravel would flash every field back into the session. A pasted key or service account must never rest there.
            return back()->withErrors($validator)->withInput($request->except($this->integrations->secretKeys($integration)));
        }

        $changed = $this->integrations->save($integration, $validator->validated(), $request->boolean('remove'));

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

    /** A real push to the admin's own phones, sent at once so the answer is on the page. */
    public function sendTestPush(Request $request, Notifier $notifier, PushDispatcher $push): RedirectResponse
    {
        if (! $notifier->connected('push')) {
            return back()->withErrors(['push_test' => __('Push is not connected yet. Save a service account first.')]);
        }

        $result = $push->test($request->user());

        if ($result['sent'] > 0) {
            return back()->with('success', __('Test notification sent. Phones reached: :count.', ['count' => $result['sent']]));
        }

        return back()->withErrors(['push_test' => match (true) {
            $result['error'] !== null => __('Firebase did not accept the test notification: :error', ['error' => $result['error']]),
            $result['removed'] > 0 => __('Firebase no longer knows that phone, so it was removed. Open the mobile app once and try again.'),
            default => __('No phone is registered for your login. Sign in to the mobile app, allow notifications, then try again.'),
        }]);
    }
}
