@extends('layouts.app')
@section('title', __('Credits'))
@section('content')
    <x-page-header :title="__('Credits')" :subtitle="__('Each agent step uses credits, based on how much the AI model reads and writes.')" />

    @include('modules.agents._connection')

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <x-stat :label="__('Available now')" :value="\App\Support\Dec::indian($wallet->available(), 2)" />
        <x-stat :label="__('Included this month')" :value="\App\Support\Dec::indian($wallet->included_balance, 2)" :note="__('Unused included credits end with the month.')" />
        <x-stat :label="__('Bought')" :value="\App\Support\Dec::indian($wallet->purchased_balance, 2)" :note="__('Bought credits do not expire.')" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card self-start">
            <div class="card-head"><h2 class="card-title">{{ __('Buy credits') }}</h2></div>
            @unless ($paymentsLive)
                <p class="px-4 pt-3 text-sm text-ink-3">{{ __('Online payment is not switched on yet.') }}</p>
            @endunless
            <div class="divide-y divide-rule">
                @foreach ($packs as $pack)
                    <form method="POST" action="{{ route('agent-credits.checkout') }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        @csrf
                        <input type="hidden" name="pack" value="{{ $pack['key'] }}">
                        <span>
                            <span class="num block font-semibold">{{ \App\Support\Dec::indian($pack['credits'], 0) }} {{ __('credits') }}</span>
                            <span class="text-xs text-ink-3">{{ inr($pack['total']) }} · {{ plan_gst_note() }}</span>
                        </span>
                        <button class="btn btn-primary" @disabled(! $canBuy)>{{ __('Buy') }}</button>
                    </form>
                @endforeach
            </div>
            @error('pack')<p class="error px-4 pb-3">{{ $message }}</p>@enderror
        </section>

        <section class="card self-start overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Use this month') }}</h2></div>
            @if ($usage === [])
                <x-empty :title="__('No use yet this month')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Model') }}</th><th class="r">{{ __('Calls') }}</th><th class="r">{{ __('Tokens in') }}</th><th class="r">{{ __('Tokens out') }}</th><th class="r">{{ __('Credits') }}</th></tr></thead>
                    <tbody>
                    @foreach ($usage as $model => $row)
                        <tr>
                            <td class="num text-xs">{{ $model }}</td>
                            <td class="r">{{ number_format($row['calls']) }}</td>
                            <td class="r">{{ number_format($row['input'] + $row['cache_read'] + $row['cache_write']) }}</td>
                            <td class="r">{{ number_format($row['output']) }}</td>
                            <td class="r">{{ \App\Support\Dec::indian($row['credits'], 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="card overflow-x-auto lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Credit history') }}</h2></div>
            @if ($ledger->isEmpty())
                <x-empty :title="__('Nothing yet')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th class="r">{{ __('Credits') }}</th><th class="r">{{ __('Balance') }}</th></tr></thead>
                    <tbody>
                    @foreach ($ledger as $entry)
                        <tr>
                            <td class="num text-xs">{{ $entry->created_at->format('d M Y H:i') }}</td>
                            <td>
                                {{ ['usage' => __('Agent use'), 'topup' => __('Bought'), 'included_grant' => __('Included with plan'), 'included_lapsed' => __('Unused included credits ended')][$entry->type] ?? $entry->type }}
                                @if ($entry->agent_run_id)<a href="{{ route('agent-runs.show', $entry->agent_run_id) }}" class="link num text-xs">#{{ $entry->agent_run_id }}</a>@endif
                            </td>
                            <td class="r">{{ \App\Support\Dec::indian($entry->credits, 2) }}</td>
                            <td class="r">{{ \App\Support\Dec::indian($entry->balance_after, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection
