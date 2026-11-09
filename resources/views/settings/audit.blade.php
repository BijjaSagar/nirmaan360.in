@extends('layouts.app')
@section('title', __('Audit log'))
@section('content')
    <x-page-header :title="__('Audit log')" :subtitle="__('Every create, change and approval')" />

    <div class="card overflow-x-auto">
        <table class="ledger min-w-[760px]">
            <thead><tr><th>{{ __('Time') }}</th><th>{{ __('User') }}</th><th>{{ __('Action') }}</th><th>{{ __('Record') }}</th><th>{{ __('Changes') }}</th></tr></thead>
            <tbody>
            @foreach ($logs as $log)
                <tr>
                    <td class="num text-xs whitespace-nowrap">{{ $log->created_at->format('d M Y H:i') }}</td>
                    <td>{{ $log->user?->name ?? __('System') }}</td>
                    <td class="num text-xs">{{ $log->action }}</td>
                    <td class="num text-xs">{{ $log->shortType() }} #{{ $log->auditable_id }}</td>
                    <td class="max-w-md text-xs break-words text-ink-3">
                        @if ($log->action === 'updated')
                            @foreach ($log->new_values ?? [] as $field => $value)
                                <span class="block"><span class="font-semibold">{{ $field }}</span>: {{ is_scalar($log->old_values[$field] ?? null) ? $log->old_values[$field] : '—' }} → {{ is_scalar($value) ? $value : json_encode($value) }}</span>
                            @endforeach
                        @elseif (str_starts_with($log->action, 'approval.') && ! empty($log->new_values['remark']))
                            {{ $log->new_values['remark'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
