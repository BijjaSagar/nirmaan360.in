<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use App\Services\ContractorPortal\ContractorPortalService;
use App\Services\Notify\Notifier;
use App\Services\Notify\Phone;
use App\Services\Notify\PushCategory;
use App\Services\Notify\QuietHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Each person's own notification settings. Staff reach it from the menu; a contractor login
 * reaches the same two actions inside the portal, where it is shown only what it can receive.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function edit(Request $request, ContractorPortalService $portal): View
    {
        $user = $request->user();

        $data = [
            'channels' => $this->notifier->channelSummary($user),
            'deviceCount' => DeviceToken::where('user_id', $user->id)->count(),
        ];

        if ($user->isContractor()) {
            return view('modules.notify.contractor-preferences', [...$data, 'party' => $portal->party($user)]);
        }

        return view('modules.notify.preferences', [
            ...$data,
            'preference' => NotificationPreference::for($user),
            'phoneOk' => Phone::digits($user->phone) !== null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            ...array_fill_keys(Notifier::CHANNELS, ['nullable', 'boolean']),
            'categories' => ['nullable', 'array'],
            'categories.*' => ['boolean'],
            'quiet_hours.enabled' => ['nullable', 'boolean'],
            'quiet_hours.from' => ['nullable', 'regex:'.QuietHours::TIME],
            'quiet_hours.until' => ['nullable', 'regex:'.QuietHours::TIME],
        ]);

        $user = $request->user();

        // A channel that is not connected is drawn disabled and not posted, so leave its choice alone.
        // A contractor login has no channel switches: it is told by push, one kind at a time.
        $choices = $user->isContractor() ? [] : collect(Notifier::CHANNELS)
            ->filter(fn (string $channel) => $this->notifier->connected($channel))
            ->mapWithKeys(fn (string $channel) => [$channel => $request->boolean($channel)])
            ->all();

        // The kinds of push and the quiet hours are drawn disabled as well until push is connected.
        if ($this->notifier->connected('push')) {
            $choices['categories'] = collect(PushCategory::namesFor($user))
                ->mapWithKeys(fn (string $name) => [$name => $request->boolean('categories.'.$name)])
                ->all();

            $choices['quiet_hours'] = [
                'enabled' => $request->boolean('quiet_hours.enabled'),
                'from' => $request->input('quiet_hours.from'),
                'until' => $request->input('quiet_hours.until'),
            ];
        }

        $this->notifier->savePreferences($user, $choices);

        return back()->with('success', __('Notification settings saved.'));
    }
}
