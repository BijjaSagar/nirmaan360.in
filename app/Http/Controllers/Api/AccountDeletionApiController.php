<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AccountDeletionChanged;
use App\Http\Controllers\Controller;
use App\Services\Access\AccountDeletionService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The field app's "Delete account". Self-service like /me, so there is no permission to check. */
class AccountDeletionApiController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    public function show(Request $request): JsonResponse
    {
        $preview = $this->deletions->preview($request->user());

        return response()->json(['data' => [
            'mode' => $preview['mode'],
            'company_name' => $preview['company_name'],
            'other_people' => $preview['other_people'],
            'requires_confirm_name' => $preview['requires_confirm_name'],
            'grace_days' => $preview['grace_days'],
            'erase_after' => $this->iso($preview['erase_after']),
            'pending' => $preview['pending'] === null ? null : [
                'erase_after' => $this->iso($preview['pending']['erase_after']),
                'can_cancel' => $preview['pending']['can_cancel'],
            ],
        ]]);
    }

    /** The token this call came with is revoked by the time the reply is sent, so the app must not call /auth/logout. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(AccountDeletionService::MODES)],
            'password' => ['required', 'string'],
            'confirm_name' => ['required_if:mode,company', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->deletions->deleteAccount($request->user(), $data['mode'], $data['password'], $data['confirm_name'] ?? null, 'app');
        } catch (AccountDeletionChanged $changed) {
            return response()->json(['message' => $changed->getMessage(), 'code' => AccountDeletionChanged::CODE], 409);
        }

        return response()->json(['data' => $result['result'] === 'scheduled'
            ? ['result' => 'scheduled', 'erase_after' => $this->iso($result['erase_after'])]
            : ['result' => 'deleted']]);
    }

    /** Cancels a pending company deletion. Owners only. */
    public function destroy(Request $request): JsonResponse
    {
        return response()->json(['data' => ['result' => $this->deletions->cancelCompanyDeletion($request->user())]]);
    }

    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone(config('app.timezone'))->toIso8601String();
    }
}
