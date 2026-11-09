@extends('layouts.app')
@section('title', __('Contractor login'))
@section('content')
    <x-page-header :title="$party->name" :subtitle="__('Contractor login')">
        @if (\Illuminate\Support\Facades\Route::has('contractor-control.contractors.show'))
            <a href="{{ route('contractor-control.contractors.show', $party) }}" class="btn btn-ghost">{{ __('Back to the ledger') }}</a>
        @endif
    </x-page-header>

    <p class="mb-5 max-w-2xl text-sm text-ink-3">{{ __('A contractor login opens a read-only portal with this contractor\'s own work orders, work done against payments, payments and bills. It never shows your BOQ rates, other contractors, budgets or any other screen, and it does not use a seat on your plan.') }}</p>

    @if (session('invite_link'))
        <div x-data="{ copied: false }" class="card mb-5 space-y-2 border-moss p-4">
            <p class="text-sm font-semibold">
                @if (session('invite_emailed'))
                    {{ __('We emailed this link to :email. You can also share it yourself.', ['email' => session('invite_email')]) }}
                @else
                    {{ __('Email is not connected, so nothing was sent. Share this link with :email yourself, for example on WhatsApp.', ['email' => session('invite_email')]) }}
                @endif
            </p>
            <div class="flex flex-wrap gap-2">
                <input x-ref="link" readonly value="{{ session('invite_link') }}" class="input min-w-0 flex-1 text-xs" aria-label="{{ __('Invitation link') }}">
                <button type="button" class="btn btn-ghost" @click="navigator.clipboard.writeText($refs.link.value); copied = true" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</button>
            </div>
            <p class="hint">{{ __('This link works once and expires in :days days. It is shown only now.', ['days' => \App\Services\Access\InvitationService::VALID_DAYS]) }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('contractor-portal.access.invite', $party) }}" class="card mb-5 space-y-3 p-4">
        @csrf
        <h2 class="card-title">{{ __('Invite the contractor') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <x-field :label="__('Email')" name="email"><input id="email" name="email" type="email" value="{{ old('email', $party->email) }}" class="input" required autocomplete="off"></x-field>
            <x-field :label="__('Mobile')" name="phone" :hint="__('Optional. Lets them sign in by OTP where SMS is connected.')"><input id="phone" name="phone" value="{{ old('phone', $party->phone) }}" class="input" inputmode="tel"></x-field>
        </div>
        @error('party')<p class="error">{{ $message }}</p>@enderror
        <div>
            <button class="btn btn-primary">{{ __('Send invitation') }}</button>
            <p class="hint mt-1">{{ __('They choose their own name and password from the link.') }}</p>
        </div>
    </form>

    @if ($invitations->isNotEmpty())
        <div class="card mb-5 overflow-x-auto">
            <div class="card-head"><span class="card-title">{{ __('Pending invitations') }}</span></div>
            <table class="ledger min-w-[560px]">
                <thead><tr><th>{{ __('Email') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Expires') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($invitations as $invitation)
                    <tr>
                        <td>{{ $invitation->email }}</td>
                        <td class="num text-xs">{{ $invitation->phone ?? '—' }}</td>
                        <td class="num text-xs">{{ $invitation->expires_at->format('d M Y') }}</td>
                        <td class="r">
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('contractor-portal.access.reissue', [$party, $invitation]) }}">@csrf<button class="btn btn-ghost py-0.5 text-xs">{{ __('New link') }}</button></form>
                                <form method="POST" action="{{ route('contractor-portal.access.cancel', [$party, $invitation]) }}">@csrf @method('DELETE')<button class="btn btn-danger py-0.5 text-xs">{{ __('Cancel') }}</button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card overflow-x-auto">
        <div class="card-head"><span class="card-title">{{ __('Logins') }}</span></div>
        @if ($logins->isEmpty())
            <x-empty :title="__('No login yet')" :note="__('Invite the contractor above. Their login appears here once they accept.')" />
        @else
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Last sign-in') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($logins as $login)
                    <tr>
                        <td class="font-semibold">{{ $login->name }} @unless ($login->is_active)<x-stamp status="revoked" />@endunless</td>
                        <td>{{ $login->email }}</td>
                        <td class="num text-xs">{{ $login->phone ?? '—' }}</td>
                        <td class="num text-xs">{{ $login->last_login_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="r">
                            @if ($login->is_active)
                                <form method="POST" action="{{ route('contractor-portal.access.revoke', [$party, $login]) }}" onsubmit="return confirm(@js(__('Take this login away? They are signed out everywhere at once.')))">@csrf<button class="btn btn-danger py-0.5 text-xs">{{ __('Take access away') }}</button></form>
                            @else
                                <form method="POST" action="{{ route('contractor-portal.access.restore', [$party, $login]) }}">@csrf<button class="btn btn-ghost py-0.5 text-xs">{{ __('Restore access') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
