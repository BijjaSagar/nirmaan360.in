@php $ssoProviders = app(\App\Services\Sso\SsoGateway::class)->connected(); @endphp
@if ($ssoProviders)
    <div class="mt-6 border-t border-rule pt-5">
        <p class="eyebrow mb-3 text-center text-[10px] text-ink-3">{{ __('Or continue with') }}</p>
        <div class="grid gap-2 {{ count($ssoProviders) > 1 ? 'sm:grid-cols-2' : '' }}">
            @foreach ($ssoProviders as $provider)
                <a href="{{ route('sso.redirect', ['provider' => $provider]) }}" class="btn btn-ghost w-full">{{ __(\App\Services\Sso\SsoGateway::PROVIDERS[$provider]) }}</a>
            @endforeach
        </div>
        <p class="mt-2 text-center text-xs text-ink-3">{{ __('For existing accounts on plans with single sign-on.') }}</p>
    </div>
@endif
