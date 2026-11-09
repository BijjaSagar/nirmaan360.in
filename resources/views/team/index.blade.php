@extends('layouts.app')
@section('title', __('Team'))
@section('content')
    <x-page-header :title="__('Team')">
        <a href="{{ route('team.roles.index') }}" class="btn btn-ghost">{{ __('Roles') }}</a>
    </x-page-header>

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

    @if ($canAdd)
        <form method="POST" action="{{ route('team.store') }}" class="card mb-5 space-y-3 p-4">
            @csrf
            <div class="grid gap-3 sm:grid-cols-3">
                <x-field :label="__('Email')" name="email"><input id="email" name="email" type="email" value="{{ old('email') }}" class="input" required autocomplete="off"></x-field>
                <x-field :label="__('Mobile')" name="phone"><input id="phone" name="phone" value="{{ old('phone') }}" class="input" inputmode="tel"></x-field>
                <x-field :label="__('Role')" name="role">
                    <select id="role" name="role" class="input">
                        @foreach ($roleOptions as $key => $label)<option value="{{ $key }}" @selected(old('role', 'engineer') === (string) $key)>{{ $label }}</option>@endforeach
                    </select>
                </x-field>
            </div>
            @include('modules.access._project-access', [
                'access' => old('project_access', 'all'),
                'selected' => array_map('intval', old('project_ids', [])),
            ])
            <div>
                <button class="btn btn-primary">{{ __('Invite member') }}</button>
                <p class="hint mt-1">{{ __('They choose their own name and password from the link.') }}</p>
            </div>
        </form>
    @else
        <p class="mb-5 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('Your plan user limit has been reached. Upgrade to add more.') }}</p>
    @endif

    @if ($invitations->isNotEmpty())
        <div class="card mb-5 overflow-x-auto">
            <div class="card-head"><span class="card-title">{{ __('Pending invitations') }}</span></div>
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('Email') }}</th><th>{{ __('Role') }}</th><th>{{ __('Expires') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($invitations as $invitation)
                    <tr>
                        <td>{{ $invitation->email }}</td>
                        <td>{{ __((string) $invitationRoles($invitation)) }}</td>
                        <td class="num text-xs">{{ $invitation->expires_at->format('d M Y') }}</td>
                        <td class="r">
                            <div class="flex justify-end gap-2">
                                <form method="POST" action="{{ route('team.invitations.reissue', $invitation) }}">@csrf<button class="btn btn-ghost py-0.5 text-xs">{{ __('New link') }}</button></form>
                                <form method="POST" action="{{ route('team.invitations.destroy', $invitation) }}">@csrf @method('DELETE')<button class="btn btn-danger py-0.5 text-xs">{{ __('Cancel') }}</button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card overflow-x-auto">
        <table class="ledger min-w-[720px]">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Role') }}</th><th>{{ __('Projects') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $user)
                <tr>
                    <td class="font-semibold">
                        {{ $user->name }}
                        @if ($user->is(auth()->user()))<span class="text-xs font-normal text-ink-3">· {{ __('You') }}</span>@endif
                        @unless ($user->is_active)<x-stamp status="inactive" />@endunless
                    </td>
                    <td>{{ $user->email }}</td>
                    <td class="num text-xs">{{ $user->phone ?? '—' }}</td>
                    <td>{{ __($user->roleLabel()) }}</td>
                    <td class="text-sm">{{ $user->isProjectLimited() ? trans_choice(':count project|:count projects', $user->assigned_projects_count, ['count' => $user->assigned_projects_count]) : __('All projects') }}</td>
                    <td class="r">@if ($canManage($user))<a href="{{ route('team.access', $user) }}" class="link text-sm">{{ __('Manage') }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
