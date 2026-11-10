<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Notify\DeviceRegistry;
use App\Services\Notify\Notifier;
use App\Services\Notify\PushCategory;
use App\Services\Notify\QuietHours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The field app's side of notifications: its push token and each person's own switches.
 * Staff call /devices and /notification-preferences. A contractor login reaches the same
 * actions under /contractor-portal, the only part of the API its gate lets through.
 */
class NotifyApiController extends Controller
{
    public function __construct(private Notifier $notifier, private DeviceRegistry $devices) {}

    /**
     * Register this phone, or refresh it. The app calls this after sign-in, on every start, and when
     * Firebase hands it a new token (then with previous_token, so the old one is retired). The token
     * is the natural key: registering again updates the row, even after a change of owner.
     */
    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'previous_token' => ['nullable', 'string', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:35'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $signIn = $request->user()->currentAccessToken();
        $device = $this->devices->register($request->user(), $data, $signIn instanceof PersonalAccessToken ? $signIn : null);

        return response()->json([
            'data' => $this->device($device),
            'push_connected' => $this->notifier->connected('push'),
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /** Called on sign-out, before the token is given up. Removes only this user's device. */
    public function destroyDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        $this->devices->forget($request->user(), $data['token']);

        return response()->json(['message' => 'Device removed.']);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notifier->channelSummary($request->user())]);
    }

    /** Only what is sent is changed: a channel, a kind of push under "categories", or the quiet hours. */
    public function updatePreferences(Request $request): JsonResponse
    {
        $choices = $request->validate([
            ...array_fill_keys(Notifier::CHANNELS, ['sometimes', 'boolean']),
            'categories' => ['sometimes', 'array'],
            ...array_fill_keys(array_map(fn (string $name) => 'categories.'.$name, PushCategory::all()), ['sometimes', 'boolean']),
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.from' => ['sometimes', 'string', 'regex:'.QuietHours::TIME],
            'quiet_hours.until' => ['sometimes', 'string', 'regex:'.QuietHours::TIME],
        ]);

        $this->notifier->savePreferences($request->user(), $choices);

        return response()->json(['data' => $this->notifier->channelSummary($request->user())]);
    }

    /** The token itself is never returned. */
    private function device(DeviceToken $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'locale' => $device->locale,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }
}
