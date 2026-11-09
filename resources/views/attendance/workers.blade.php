@extends('layouts.app')
@section('title', __('Workers'))
@section('content')
    <x-page-header :title="__('Workers')" />

    @can('worker.manage')
        <form method="POST" action="{{ route('workers.store') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-6">
            @csrf
            <x-field :label="__('Name')" name="name" class="sm:col-span-2"><input id="name" name="name" value="{{ old('name') }}" class="input" required></x-field>
            <x-field :label="__('Mobile')" name="phone"><input id="phone" name="phone" value="{{ old('phone') }}" class="input" inputmode="tel"></x-field>
            <x-field :label="__('Trade')" name="trade">
                <input id="trade" name="trade" value="{{ old('trade') }}" list="trades" class="input" required>
                <datalist id="trades">@foreach (config('buildos.trades') as $trade)<option value="{{ $trade }}">@endforeach</datalist>
            </x-field>
            <x-field :label="__('Type')" name="type">
                <select id="type" name="type" class="input">
                    <option value="own">{{ __('Own') }}</option>
                    <option value="contractor">{{ __('Contractor') }}</option>
                </select>
            </x-field>
            <x-field :label="__('Daily wage (₹)')" name="daily_wage"><input id="daily_wage" name="daily_wage" type="number" step="0.01" min="0" value="{{ old('daily_wage') }}" class="input num" required></x-field>
            <x-field :label="__('Labour contractor')" name="contractor_id" class="sm:col-span-3">
                <select id="contractor_id" name="contractor_id" class="input">
                    <option value="">—</option>
                    @foreach ($contractors as $contractor)<option value="{{ $contractor->id }}">{{ $contractor->name }}</option>@endforeach
                </select>
            </x-field>
            <div class="flex items-end sm:col-span-3"><button class="btn btn-primary">{{ __('Add worker') }}</button></div>
        </form>
    @endcan

    <div class="card overflow-x-auto">
        @if ($workers->isEmpty())
            <x-empty :title="__('No workers yet')" />
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Trade') }}</th><th>{{ __('Type') }}</th><th>{{ __('Mobile') }}</th><th class="r">{{ __('Daily wage') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($workers as $worker)
                    <tr>
                        <td class="font-semibold">{{ $worker->name }}</td>
                        <td>{{ __($worker->trade) }}</td>
                        <td>{{ $worker->type === 'contractor' ? ($worker->contractor?->name ?? __('Contractor')) : __('Own') }}</td>
                        <td class="num text-xs">{{ $worker->phone ?? '—' }}</td>
                        <td class="r">{{ inr($worker->daily_wage) }}</td>
                        <td><x-stamp :status="$worker->is_active ? 'active' : 'inactive'" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $workers->links() }}</div>
@endsection
