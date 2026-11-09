@extends('layouts.app')
@section('title', __('Offline plan'))
@section('content')
    @php
        $platforms = ['android' => 'Android', 'ios' => 'iPhone', 'other' => 'Other'];
    @endphp

    <x-page-header :title="$offline ? __('Offline plan') : __('Offline devices')" :subtitle="$offline ? __('Your data stays on the phone. The server keeps no copy and nothing syncs.') : __('Your plan syncs to the server, so phones need no licence. Licences matter only on the offline plan.')">
        @if ($canManage)
            <a href="{{ route('billing.index') }}" class="btn btn-ghost">{{ __('Billing') }}</a>
        @endif
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
        <section class="card p-5">
            <p class="eyebrow text-ink-3">{{ __('Current plan') }}</p>
            <p class="mt-1 font-display text-3xl font-semibold">{{ __($plan['label']) }}</p>
            <p class="mt-0.5 text-sm text-ink-3">
                @if ($organization->isOnTrial())
                    {{ __('Trial ends on :date', ['date' => $organization->trial_ends_at->format('d M Y')]) }}
                @elseif ($organization->paid_until && ! $organization->hasLapsed())
                    {{ __('Paid until :date', ['date' => $organization->paid_until->format('d M Y')]) }}
                @endif
            </p>
            @if ($offline)
                <ul class="mt-4 space-y-1.5 text-sm text-ink-2">
                    <li>· {{ __('Everything you enter stays on the licensed phone. There is no server backup.') }}</li>
                    <li>· {{ __('No team and no web dashboard: this page, billing and your account are all the website shows.') }}</li>
                    <li>· {{ __('A lost or broken phone means lost data, unless you exported a backup from the app.') }}</li>
                </ul>
            @endif
        </section>

        <section class="card p-5">
            <h2 class="card-title">{{ $offline ? __('Want a server copy and a team?') : __('Online plans') }}</h2>
            <p class="mt-1 text-sm text-ink-2">{{ __('On an online plan the phone uploads what it holds the next time it has internet, and nothing is entered twice.') }}</p>
            @if ($canManage)
                {{-- Prices are money: only the people who manage billing see them. Site roles never see a rupee figure. --}}
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($onlinePlans as $key => $onlinePlan)
                        <li class="flex items-center justify-between gap-3 border-b border-rule py-1.5 last:border-b-0">
                            <span class="font-semibold">{{ __($onlinePlan['label']) }}</span>
                            <span class="num text-ink-3">{{ inr($onlinePlan['monthly']) }} {{ __('/month') }}</span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('billing.index') }}" class="btn btn-primary mt-4">{{ __('Upgrade in Billing') }}</a>
            @else
                <p class="mt-4 text-sm text-ink-3">{{ __('Ask your company owner to upgrade from Billing.') }}</p>
            @endif
        </section>
    </div>

    @if ($canManage)
        <section class="card mt-5 overflow-x-auto">
            <div class="card-head">
                <h2 class="card-title">{{ __('Licensed phones') }}</h2>
                <span class="text-sm text-ink-3">
                    @if ($maxDevices === null)
                        {{ trans_choice(':count phone in use|:count phones in use', $activeDevices) }}
                    @else
                        {{ __(':used of :max phone slots in use', ['used' => $activeDevices, 'max' => $maxDevices]) }}
                    @endif
                </span>
            </div>
            @if ($licences->isEmpty())
                <x-empty :title="__('No phone is licensed yet')" :note="__('Sign in to the field app on the phone you will use. It takes a licence by itself.')" />
            @else
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>{{ __('Phone') }}</th><th>{{ __('Activated') }}</th><th>{{ __('Last seen') }}</th><th>{{ __('Licence valid till') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($licences as $licence)
                        <tr>
                            <td class="font-semibold">
                                {{ $licence->device_name }}
                                <span class="block text-xs font-normal text-ink-3">
                                    @if ($licence->platform){{ __($platforms[$licence->platform] ?? 'Other') }} · @endif
                                    {{ __('Activated by :name', ['name' => $licence->user?->name ?? '—']) }}
                                </span>
                            </td>
                            <td class="num text-xs">{{ $licence->activated_at?->format('d M Y, H:i') }}</td>
                            <td class="num text-xs">{{ $licence->last_seen_at?->format('d M Y, H:i') ?? '—' }}</td>
                            <td class="num text-xs">{{ $licence->expires_at?->format('d M Y') }}</td>
                            <td>
                                @if ($licence->isActive())
                                    <x-stamp status="active" />
                                @else
                                    <span class="stamp border-ink-3 text-ink-3">{{ __('Freed') }}</span>
                                @endif
                            </td>
                            <td class="r">
                                @if ($licence->isActive())
                                    <form method="POST" action="{{ route('offline.devices.free', $licence) }}" onsubmit="return confirm(@js(__('Free this phone? Its slot opens at once, and the phone is told the next time it is online.')))">
                                        @csrf
                                        <button class="btn btn-danger py-0.5 text-xs">{{ __('Free phone') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif
@endsection
