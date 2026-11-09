<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Services\Sso\SsoException;
use App\Services\Sso\SsoGateway;
use App\Services\Sso\SsoSignInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Sign in with Google or Microsoft. It only ever signs in an existing user. */
class SsoLoginController extends Controller
{
    public function redirect(string $provider, SsoGateway $gateway): RedirectResponse
    {
        abort_unless($gateway->isConnected($provider), 404);

        return $gateway->redirect($provider);
    }

    public function callback(Request $request, string $provider, SsoGateway $gateway, SsoSignInService $signIn): RedirectResponse
    {
        abort_unless($gateway->isConnected($provider), 404);

        // The person said no at the provider's screen.
        if ($request->filled('error')) {
            return $this->failed(__('The sign-in with :provider was cancelled.', ['provider' => SsoGateway::PROVIDERS[$provider]]));
        }

        try {
            $user = $signIn->resolve($gateway->identity($provider));
        } catch (SsoException $exception) {
            return $this->failed($exception->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
