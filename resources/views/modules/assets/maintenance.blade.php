@extends('layouts.app')
@section('title', __('Maintenance'))
@section('content')
    <x-page-header :title="__('Maintenance')" :subtitle="__('Every scheduled service, the most urgent first')" />

    <div class="card overflow-x-auto">
        @if ($schedule->isEmpty())
            <x-empty :title="__('No services scheduled')" :note="__('Open an asset and add its oil changes and inspections on the Maintenance tab.')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Asset') }}</th><th>{{ __('Service') }}</th><th>{{ __('Last done') }}</th><th>{{ __('Next due') }}</th><th class="r">{{ __('Since last service') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($schedule as $row)
                    @php($item = $row['item'])
                    @php($status = $row['status'])
                    <tr>
                        <td><a href="{{ route('assets.show', [$row['asset'], 'tab' => 'maintenance']) }}" class="link">{{ $row['asset']->code }}</a> <span class="text-ink-3">{{ $row['asset']->name }}</span></td>
                        <td>{{ $item->title }}</td>
                        <td class="num">{{ $item->last_done_on?->format('d M Y') ?? '—' }}</td>
                        <td class="num">
                            {{ $item->next_due_on?->format('d M Y') ?? '—' }}
                            @if ($status['days_left'] !== null)<span class="block text-xs text-ink-3">{{ $status['days_left'] < 0 ? __(':days days overdue', ['days' => abs($status['days_left'])]) : __('in :days days', ['days' => $status['days_left']]) }}</span>@endif
                        </td>
                        <td class="r">@if ($item->interval_usage !== null){{ fmt_qty($status['usage_since']) }} / {{ fmt_qty($item->interval_usage) }} {{ $status['unit'] === 'km' ? 'km' : 'h' }}@else — @endif</td>
                        <td>@include('modules.field_ops._chip', ['set' => 'maintenance', 'value' => $status['state']])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
