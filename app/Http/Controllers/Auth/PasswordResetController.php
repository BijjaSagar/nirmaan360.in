<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    /** With the log mailer in production, a reset email would silently go nowhere. */
    public static function available(): bool
    {
        return ! app()->isProduction() || config('mail.default') !== 'log';
    }

    public function showForgot(): View
    {
        abort_unless(self::available(), 404);

        return view('auth.forgot');
    }

    public function send(Request $request): RedirectResponse
    {
        abort_unless(self::available(), 404);

        Password::sendResetLink($request->validate(['email' => ['required', 'email']]));

        // The response is the same either way, so an email address can't be probed.
        return back()->with('success', __('If this email is registered, a reset link has been sent.'));
    }

    public function showReset(Request $request, string $token): View
    {
        abort_unless(self::available(), 404);

        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless(self::available(), 404);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __('This reset link is invalid or has expired.')]);
        }

        return redirect()->route('login')->with('success', __('Password changed. Please sign in.'));
    }
}
