<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Sso\SsoGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SsoSettingsController extends Controller
{
    public function __construct(private SsoGateway $gateway) {}

    /** Shows whether each provider is connected. Platform staff also get the form for the keys. */
    public function show(Request $request): View
    {
        return view('modules.sso.settings', [
            'providers' => SsoGateway::PROVIDERS,
            'gateway' => $this->gateway,
            'planAllows' => $request->user()->organization->allowsFeature('sso'),
            'canSaveKeys' => $request->user()->isPlatformAdmin(),
            'tenant' => $this->gateway->tenant(),
        ]);
    }

    /** Platform staff only. A blank secret keeps the one already saved. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(SsoGateway::PROVIDERS))],
            'action' => ['required', Rule::in(['save', 'disconnect'])],
            'client_id' => ['required_if:action,save', 'nullable', 'string', 'max:190'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9.\-]+$/'],
        ]);

        $provider = $data['provider'];

        if ($data['action'] === 'disconnect') {
            $this->gateway->disconnect($provider);
            AuditLog::record('sso.disconnected', $request->user(), null, ['provider' => $provider]);

            return back()->with('success', __(':provider disconnected. Its sign-in button is gone.', ['provider' => SsoGateway::PROVIDERS[$provider]]));
        }

        if (! $this->gateway->isConnected($provider) && blank($data['client_secret'] ?? null)) {
            return back()->withErrors(['client_secret' => __('Enter the client secret.')])->withInput();
        }

        $this->gateway->saveKeys($provider, $data['client_id'], $data['client_secret'] ?? null, $data['tenant'] ?? null);
        AuditLog::record('sso.keys_saved', $request->user(), null, ['provider' => $provider]);

        return back()->with('success', __(':provider keys saved.', ['provider' => SsoGateway::PROVIDERS[$provider]]));
    }
}
