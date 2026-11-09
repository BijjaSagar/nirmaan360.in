<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\SmsGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class OtpController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private SmsGateway $sms) {}

    public function show(): View
    {
        abort_if(app()->isProduction() && ! $this->sms->isLive(), 404);

        return view('auth.otp', ['phone' => session('otp_phone')]);
    }

    public function send(Request $request): RedirectResponse
    {
        abort_if(app()->isProduction() && ! $this->sms->isLive(), 404);

        $phone = $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
        $devCode = null;

        // The response is the same either way, so a phone number can't be probed.
        if (User::where('phone', $phone)->where('is_active', true)->exists()) {
            $code = (string) random_int(100000, 999999);

            OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            OtpCode::create(['phone' => $phone, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
            $this->sms->sendOtp($phone, $code);

            $devCode = app()->isLocal() ? $code : null;
        }

        return redirect()->route('otp.show')
            ->with('otp_phone', $phone)
            ->with('dev_code', $devCode)
            ->with('success', __('If this number is registered, a code has been sent.'));
    }

    public function verify(Request $request): RedirectResponse
    {
        abort_if(app()->isProduction() && ! $this->sms->isLive(), 404);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'digits:6'],
        ]);

        $otp = OtpCode::where('phone', $data['phone'])
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS || ! Hash::check($data['code'], $otp->code_hash)) {
            $otp?->increment('attempts');

            return redirect()->route('otp.show')->with('otp_phone', $data['phone'])->withErrors(['code' => __('That code is wrong or has expired.')]);
        }

        $otp->update(['consumed_at' => now()]);

        $user = User::where('phone', $data['phone'])->where('is_active', true)->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);

        return redirect()->intended(route('dashboard'));
    }
}
