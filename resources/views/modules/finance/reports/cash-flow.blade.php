@extends('layouts.app')
@section('title', __('Cash-flow forecast'))
@section('content')
    <x-page-header :title="__('Cash-flow forecast')" :subtitle="__('From approved bills that are still unsettled')" />
    @include('modules.finance.reports._ca-note')

    <form method="GET" class="card mb-5 grid items-end gap-3 p-4 sm:grid-cols-4">
        <x-field :label="__('Project')" name="project_id">
            <select id="project_id" name="project_id" class="input">
                <option value="">{{ __('All projects') }}</option>
                @foreach ($projects as $project)<option value="{{ $project->id }}" @selected($projectId === $project->id)>{{ $project->code }} · {{ $project->name }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('Weeks ahead')" name="weeks"><input id="weeks" name="weeks" type="number" min="4" max="26" value="{{ $forecast['weeks'] }}" class="input num"></x-field>
        <x-field :label="__('Assumed credit days')" name="credit_days" :hint="__('Used for bills with no due date.')"><input id="credit_days" name="credit_days" type="number" min="0" max="180" value="{{ $forecast['credit_days'] }}" class="input num"></x-field>
        <div><button class="btn btn-ghost">{{ __('Show') }}</button></div>
    </form>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="__('Net cash to date')" :value="inr($forecast['opening'])" :note="__('Recorded here. Not a bank balance.')" />
        <x-stat :label="__('Expected in')" :value="inr($forecast['total_in'])" />
        <x-stat :label="__('Due out')" :value="inr($forecast['total_out'])" />
        <x-stat :label="__('Closing')" :value="inr($forecast['closing'])" :accent="\App\Support\Dec::of($forecast['closing'])->isNegative()" />
    </div>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('By week') }}</h2></div>
        <table class="ledger min-w-[640px]">
            <thead><tr><th>{{ __('Period') }}</th><th class="r">{{ __('Expected in') }}</th><th class="r">{{ __('Due out') }}</th><th class="r">{{ __('Net') }}</th><th class="r">{{ __('Closing') }}</th></tr></thead>
            <tbody>
            @foreach ($forecast['rows'] as $row)
                <tr>
                    <td class="num text-xs whitespace-nowrap">
                        @if ($row['key'] === 'overdue'){{ __('Overdue') }}@elseif ($row['key'] === 'later'){{ __('Later') }}@else{{ \Illuminate\Support\Carbon::parse($row['from'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($row['to'])->format('d M') }}@endif
                    </td>
                    <td class="r">{{ inr($row['in']) }}</td>
                    <td class="r">{{ inr($row['out']) }}</td>
                    <td class="r">{{ inr($row['net']) }}</td>
                    <td class="r font-medium {{ \App\Support\Dec::of($row['closing'])->isNegative() ? 'text-laterite' : '' }}">{{ inr($row['closing']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    @if ($forecast['items'] !== [])
        <section class="card mt-5 overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Bills in this forecast') }}</h2></div>
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('Due') }}</th><th>{{ __('Bill') }}</th><th>{{ __('Party') }}</th><th>{{ __('Project') }}</th><th class="r">{{ __('Expected in') }}</th><th class="r">{{ __('Due out') }}</th></tr></thead>
                <tbody>
                @foreach ($forecast['items'] as $item)
                    <tr>
                        <td class="num text-xs whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($item['due'])->format('d M Y') }}</td>
                        <td class="num text-xs">{{ $item['reference'] }}</td>
                        <td>{{ $item['party'] ?? '—' }}</td>
                        <td class="num text-xs">{{ $item['project'] }}</td>
                        <td class="r">{{ $item['direction'] === 'in' ? inr($item['amount']) : '' }}</td>
                        <td class="r">{{ $item['direction'] === 'out' ? inr($item['amount']) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
@endsection
