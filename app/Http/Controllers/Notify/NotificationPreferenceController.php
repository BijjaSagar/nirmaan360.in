<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use App\Services\Notify\Notifier;
use App\Services\Notify\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationPreferenceController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('modules.notify.preferences', [
            'channels' => $this->notifier->channelSummary($user),
            'preference' => NotificationPreference::for($user),
            'deviceCount' => DeviceToken::where('user_id', $user->id)->count(),
            'phoneOk' => Phone::digits($user->phone) !== null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(array_fill_keys(Notifier::CHANNELS, ['nullable', 'boolean']));

        // A channel that is not connected is drawn disabled and not posted, so leave its choice alone.
        $choices = collect(Notifier::CHANNELS)
            ->filter(fn (string $channel) => $this->notifier->connected($channel))
            ->mapWithKeys(fn (string $channel) => [$channel => $request->boolean($channel)])
            ->all();

        $this->notifier->savePreferences($request->user(), $choices);

        return back()->with('success', __('Notification settings saved.'));
    }
}
