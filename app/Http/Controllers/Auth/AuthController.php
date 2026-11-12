<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Seo\Analytics;
use App\Services\SmsGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            return back()->withErrors(['email' => __('These details do not match our records.')])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(Request $request): View
    {
        $plan = $request->query('plan');

        return view('auth.register', [
            'plan' => in_array($plan, $this->selfServePlans(), true) ? $plan : 'contractor',
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['nullable', Rule::in($this->selfServePlans())],
            'company' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            // Taken in any letter case, or kept for the platform's own staff. Both read the same, so the
            // message never tells a stranger which addresses run the platform.
            'email' => ['required', 'email', 'max:190', function (string $attribute, mixed $value, \Closure $fail) {
                if (User::emailTaken((string) $value) || User::emailReservedForPlatform((string) $value)) {
                    $fail(__('This email already has an account.'));
                }
            }],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $plan = $data['plan'] ?? 'contractor';
        $isFree = $plan === 'free';

        $user = DB::transaction(function () use ($data, $plan, $isFree) {
            $organization = Organization::create([
                'name' => $data['company'],
                'slug' => Str::slug($data['company']).'-'.Str::lower(Str::random(6)),
                'plan' => $plan,
                'plan_status' => $isFree ? 'active' : 'trial',
                'trial_ends_at' => $isFree ? null : now()->addDays(config('buildos.trial_days')),
            ]);
            $organization->seedDefaults();

            return User::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => Str::lower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'role' => 'owner',
                'password' => $data['password'],
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        // Counted on the next page, and only the plan's key is sent: no name, email or company.
        app(Analytics::class)->record($request, 'sign_up', ['method' => 'email', 'plan' => $plan]);

        return redirect()->route('dashboard')->with('success', $isFree ? __('Welcome! Your free plan is ready.') : __('Welcome! Your :days-day trial has started.', ['days' => config('buildos.trial_days')]));
    }

    /**
     * Plans a company can start by itself: anything with a price, free included.
     * Enterprise has none and is priced by conversation.
     *
     * @return list<string>
     */
    private function selfServePlans(): array
    {
        return array_keys(array_filter(config('buildos.plans'), fn (array $plan) => $plan['monthly'] !== null));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
