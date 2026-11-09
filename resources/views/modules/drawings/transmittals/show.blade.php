@extends('layouts.app')
@section('title', $transmittal->number)
@section('content')
    <div x-data="{ ack: false, cancel: false }">
        <x-page-header :title="$transmittal->number" :subtitle="$transmittal->subject">
            <x-stamp :status="$transmittal->status" />
            <a href="{{ route('drawings.transmittals.pdf', $transmittal) }}" class="btn btn-ghost">{{ __('Download PDF') }}</a>
            @can('drawings.manage')
                @if ($transmittal->status === 'issued')
                    <button type="button" class="btn btn-ghost" @click="ack = !ack">{{ __('Record acknowledgement') }}</button>
                @endif
                @if ($transmittal->status !== 'cancelled')
                    <button type="button" class="btn btn-danger" @click="cancel = !cancel">{{ __('Cancel transmittal') }}</button>
                @endif
            @endcan
        </x-page-header>

        @can('drawings.manage')
            <form x-show="ack" x-cloak method="POST" action="{{ route('drawings.transmittals.acknowledge', $transmittal) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-3">
                @csrf
                <x-field :label="__('Received by')" name="acknowledged_by"><input id="acknowledged_by" name="acknowledged_by" value="{{ old('acknowledged_by') }}" maxlength="160" class="input" required></x-field>
                <x-field :label="__('Received on')" name="acknowledged_on"><input id="acknowledged_on" name="acknowledged_on" type="date" value="{{ old('acknowledged_on', now()->toDateString()) }}" class="input" required></x-field>
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Save') }}</button></div>
            </form>
            <form x-show="cancel" x-cloak method="POST" action="{{ route('drawings.transmittals.cancel', $transmittal) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-3">
                @csrf
                <x-field :label="__('Reason')" name="cancel_reason" class="sm:col-span-2"><input id="cancel_reason" name="cancel_reason" maxlength="500" class="input" required></x-field>
                <div class="flex items-end"><button class="btn btn-danger w-full">{{ __('Cancel transmittal') }}</button></div>
            </form>
        @endcan

        <div class="card mb-5 grid gap-3 p-4 text-sm sm:grid-cols-3">
            <div><p class="label">{{ __('To') }}</p>{{ $transmittal->recipient_name }}@if ($transmittal->recipient_company)<br><span class="text-ink-3">{{ $transmittal->recipient_company }}</span>@endif</div>
            <div><p class="label">{{ __('Purpose') }}</p>{{ __(\Illuminate\Support\Str::headline($transmittal->purpose)) }}</div>
            <div><p class="label">{{ __('Issued') }}</p><span class="num">{{ $transmittal->issued_at->format('d M Y') }}</span> · {{ $transmittal->issuer?->name }}</div>
            @if ($transmittal->notes)<div class="sm:col-span-3"><p class="label">{{ __('Notes') }}</p>{{ $transmittal->notes }}</div>@endif
            @if ($transmittal->acknowledged_on)<div class="sm:col-span-3"><p class="label">{{ __('Acknowledged') }}</p>{{ $transmittal->acknowledged_by }} · <span class="num">{{ $transmittal->acknowledged_on->format('d M Y') }}</span></div>@endif
            @if ($transmittal->status === 'cancelled')<div class="sm:col-span-3"><p class="label">{{ __('Cancelled') }}</p><span class="num">{{ $transmittal->cancelled_at->format('d M Y') }}</span> · {{ $transmittal->cancel_reason }}</div>@endif
        </div>

        <div class="card overflow-x-auto">
            <table class="ledger min-w-[560px]">
                <thead><tr><th>{{ __('Number') }}</th><th class="w-[50%]">{{ __('Title') }}</th><th>{{ __('Rev') }}</th><th class="r">{{ __('Copies') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($transmittal->items as $item)
                    <tr>
                        <td class="num text-xs">{{ $item->version->drawing?->number ?? '—' }}</td>
                        <td>{{ $item->version->drawing?->title ?? __('Removed') }}</td>
                        <td class="num text-xs">{{ $item->version->revision }}</td>
                        <td class="r">{{ $item->copies }}</td>
                        <td class="text-right">@if ($item->version->drawing)<a class="link text-xs" href="{{ route('drawings.register.file', [$item->version, 'download' => 1]) }}">{{ __('Download') }}</a>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
