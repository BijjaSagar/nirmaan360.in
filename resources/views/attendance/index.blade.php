@extends('layouts.app')
@section('title', __('Attendance'))
@section('content')
    <x-page-header :title="__('Attendance')" :subtitle="$project->name">
        <form method="GET" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" class="input" onchange="this.form.submit()" aria-label="{{ __('Date') }}">
        </form>
    </x-page-header>

    <form method="POST" action="{{ route('attendance.store', $project) }}">
        @csrf
        <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
        <div class="card overflow-x-auto">
            @if ($workers->isEmpty())
                <x-empty :title="__('No workers yet')" :note="__('Add workers first, then mark daily attendance.')">
                    @can('worker.view')<a href="{{ route('workers.index') }}" class="btn btn-primary">{{ __('Add workers') }}</a>@endcan
                </x-empty>
            @else
                <table class="ledger min-w-[640px]">
                    <thead><tr><th>{{ __('Worker') }}</th><th>{{ __('Trade') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('OT hours') }}</th></tr></thead>
                    <tbody>
                    @foreach ($workers as $worker)
                        @php $current = $marked[$worker->id] ?? null; @endphp
                        <tr>
                            <td class="font-semibold">{{ $worker->name }}</td>
                            <td>{{ __($worker->trade) }}</td>
                            <td>
                                <div class="flex flex-wrap gap-3 text-sm">
                                    @foreach (config('buildos.attendance_statuses') as $option)
                                        <label class="inline-flex items-center gap-1">
                                            <input type="radio" name="attendance[{{ $worker->id }}][status]" value="{{ $option }}" @checked($current?->status === $option)>
                                            {{ __(ucfirst($option)) }}
                                        </label>
                                    @endforeach
                                </div>
                            </td>
                            <td class="r"><input name="attendance[{{ $worker->id }}][ot_hours]" type="number" step="0.5" min="0" max="16" value="{{ $current && $current->ot_hours > 0 ? $current->ot_hours : '' }}" class="input num ml-auto w-20 text-right" aria-label="{{ __('OT hours') }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @can('attendance.manage')
            @if ($workers->isNotEmpty())
                <button class="btn btn-primary mt-4">{{ __('Save attendance') }}</button>
            @endif
        @endcan
    </form>
@endsection
