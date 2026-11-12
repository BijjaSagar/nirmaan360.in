<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access\SignupService;
use App\Services\Seo\Analytics;
use App\Services\SmsGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(SmsGateway $sms): View
    {
        return view('auth.login', ['otpEnabled' => $sms->isLive() || ! app()->isProduction()]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Signed in by the stored spelling, so a different letter case still works. When nobody matches,
        // the attempt still runs with what was typed, keeping its fixed-time failure and Failed event.
        $user = User::findByEmail($credentials['email']);

        if (! Auth::attempt(['email' => $user?->email ?? $credentials['email'], 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => __('These details do not match our records.')])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(Request $request, SignupService $signup): View
    {
        $plan = $request->query('plan');

        return view('auth.register', [
            'plan' => in_array($plan, $signup->selfServePlans(), true) ? $plan : $signup->defaultPlan(),
        ]);
    }

    public function register(Request $request, SignupService $signup): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['nullable', Rule::in($signup->selfServePlans())],
            ...$signup->rules(),
        ], $signup->messages());

        $user = $signup->create($data, 'web');
        $organization = $user->organization;

        Auth::login($user);
        $request->session()->regenerate();

        // Counted on the next page, and only the plan's key is sent: no name, email or company.
        app(Analytics::class)->record($request, 'sign_up', ['method' => 'email', 'plan' => $organization->plan]);

        return redirect()->route('dashboard')->with('success', $signup->welcomeMessage($organization));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
