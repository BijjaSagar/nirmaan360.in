@extends('layouts.app')
@section('title', __('Wage runs'))
@section('content')
    <x-page-header :title="__('Wage runs')" :subtitle="__('Wages come from attendance. A run is checked, approved and then locked.')" />

    @can('payroll.manage')
        <div class="mb-6 grid gap-4 lg:grid-cols-3">
            <form method="POST" action="{{ route('payroll.runs.store') }}" class="card grid gap-3 p-4 sm:grid-cols-2 lg:col-span-2">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <p class="card-title sm:col-span-2">{{ __('New wage run') }}</p>
                <x-field :label="__('From')" name="period_from"><input id="period_from" name="period_from" type="date" value="{{ old('period_from', now()->startOfMonth()->format('Y-m-d')) }}" class="input" required></x-field>
                <x-field :label="__('To')" name="period_to"><input id="period_to" name="period_to" type="date" value="{{ old('period_to', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" class="input" required></x-field>
                <x-field :label="__('Project')" name="project_id" :hint="__('Leave empty to pay attendance from every project.')">
                    <select id="project_id" name="project_id" class="input">
                        <option value="">{{ __('All projects') }}</option>
                        @foreach ($projects as $choice)<option value="{{ $choice->id }}" @selected((int) old('project_id') === $choice->id)>{{ $choice->code }} · {{ $choice->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Workers')" name="worker_scope">
                    <select id="worker_scope" name="worker_scope" class="input">
                        @foreach ($scopes as $scope)<option value="{{ $scope }}" @selected(old('worker_scope', 'own') === $scope)>{{ __(['all' => 'All workers', 'own' => 'Own workers', 'contractor' => 'Contractor workers'][$scope]) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-2"><input id="notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="500"></x-field>
                <div class="sm:col-span-2"><button class="btn btn-primary">{{ __('Calculate wages') }}</button></div>
            </form>

            <form method="POST" action="{{ route('payroll.terms.update') }}" class="card space-y-3 p-4">
                @csrf @method('PATCH')
                <p class="card-title">{{ __('Overtime terms') }}</p>
                <x-field :label="__('Hours in a normal day')" name="shift_hours"><input id="shift_hours" name="shift_hours" type="number" step="0.5" min="1" max="24" value="{{ old('shift_hours', $terms['shift_hours']) }}" class="input num" required></x-field>
                <x-field :label="__('Overtime pays this many times the hourly rate')" name="ot_multiplier" :hint="__('The hourly rate is the daily wage divided by the hours in a day.')"><input id="ot_multiplier" name="ot_multiplier" type="number" step="0.25" min="1" max="5" value="{{ old('ot_multiplier', $terms['ot_multiplier']) }}" class="input num" required></x-field>
                <button class="btn btn-ghost">{{ __('Save terms') }}</button>
            </form>
        </div>
    @endcan

    <div class="card overflow-x-auto">
        @if ($runs->isEmpty())
            <x-empty :title="__('No wage runs yet')" :note="__('Mark attendance, then calculate wages for the period.')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Run') }}</th><th>{{ __('Period') }}</th><th>{{ __('Project') }}</th><th class="r">{{ __('Gross') }}</th><th class="r">{{ __('Advances taken back') }}</th><th class="r">{{ __('Net pay') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td><a href="{{ route('payroll.runs.show', $run) }}" class="link num">{{ $run->number }}</a></td>
                        <td class="text-sm">{{ $run->period_from->format('d M') }} – {{ $run->period_to->format('d M Y') }}</td>
                        <td>{{ $run->project?->name ?? __('All projects') }}</td>
                        <td class="r">{{ inr($run->total_gross) }}</td>
                        <td class="r">{{ inr($run->total_recovery) }}</td>
                        <td class="r font-semibold">{{ inr($run->total_net) }}</td>
                        <td><x-stamp :status="$run->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $runs->links() }}</div>
@endsection
