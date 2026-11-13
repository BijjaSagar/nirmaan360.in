<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\UserPresenter;
use App\Models\User;
use App\Services\Access\SignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthApiController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::findByEmail($data['email']);

        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('These details do not match our records.')]);
        }

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => UserPresenter::present($user),
        ]);
    }

    /**
     * A new company with its first owner, signed in on this device: the reply has the same shape as login.
     * The plan is not the app's to choose: any plan sent is ignored, and the company starts on the free plan
     * with no trial (SignupService::appPlan), so nothing started in the app needs paying for outside it.
     */
    public function register(Request $request, SignupService $signup): JsonResponse
    {
        $data = $request->validate([
            ...$signup->rules(),
            'device_name' => ['required', 'string', 'max:120'],
            'locale' => ['nullable', Rule::in(array_keys(config('buildos.locales')))],
        ], $signup->messages());

        $user = $signup->create($data, 'app');

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => UserPresenter::present($user),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => UserPresenter::present($request->user())]);
    }

    /**
     * GET /offline/status, which field app builds made while the offline plan existed (build 18 among them) ask after
     * every sign-in and every few hours. There is no offline plan any more, so every company syncs and the answer is
     * the one an online company always had. Those builds read only `offline`. A phone still holding a company that was on the offline plan reads
     * `false` as "the company syncs now" and offers to upload what it holds; without this reply it never can.
     */
    public function syncMode(Request $request): JsonResponse
    {
        return response()->json([
            'plan' => $request->user()->organization?->effectivePlan(),
            'offline' => false,
            'syncs' => true,
        ]);
    }
}
