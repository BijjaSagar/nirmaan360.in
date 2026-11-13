{{-- The phones holding a licence, with the button that frees one. Only for people who manage billing. --}}
@php
    $platforms = ['android' => 'Android', 'ios' => 'iPhone', 'other' => 'Other'];
@endphp
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
