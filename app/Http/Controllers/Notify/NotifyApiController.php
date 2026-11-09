<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Services\Notify\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotifyApiController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    /** The token is the natural key: registering again updates the row, even after a change of owner. */
    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();

        // Not limited to this company: a phone handed to someone elsewhere has to move with them.
        $device = DeviceToken::withoutGlobalScopes()->updateOrCreate(
            ['token_hash' => DeviceToken::hashOf($data['token'])],
            [
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'token' => $data['token'],
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'data' => ['id' => $device->id, 'platform' => $device->platform],
            'push_connected' => $this->notifier->connected('push'),
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /** Called on sign-out. Removes only this user's device. */
    public function destroyDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        DeviceToken::where('user_id', $request->user()->id)->where('token_hash', DeviceToken::hashOf($data['token']))->delete();

        return response()->json(['message' => 'Device removed.']);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notifier->channelSummary($request->user())]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $choices = $request->validate(array_fill_keys(Notifier::CHANNELS, ['sometimes', 'boolean']));

        $this->notifier->savePreferences($request->user(), $choices);

        return response()->json(['data' => $this->notifier->channelSummary($request->user())]);
    }
}
