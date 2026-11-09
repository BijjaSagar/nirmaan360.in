@extends('layouts.app')
@section('title', __('Bar bending schedules'))
@section('content')
    <div x-data="{ add: @js($errors->hasAny(['title', 'drawing_id'])) }">
        <x-page-header :title="__('Bar bending schedules')" :subtitle="$project->name">
            @can('drawings.bbs')<button type="button" class="btn btn-primary" @click="add = !add">{{ __('New schedule') }}</button>@endcan
        </x-page-header>

        <div class="mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="note">{{ __('Results are calculated from IS 2502:1963. An engineer must check every bar before cutting.') }}</div>

        @can('drawings.bbs')
            <form x-show="add" x-cloak method="POST" action="{{ route('drawings.bbs.store', $project) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title') }}" maxlength="160" class="input" placeholder="{{ __('Footing F1') }}" required></x-field>
                <x-field :label="__('Drawing')" name="drawing_id">
                    <select id="drawing_id" name="drawing_id" class="input">
                        <option value="">—</option>
                        @foreach ($drawings as $drawing)<option value="{{ $drawing->id }}" @selected((int) old('drawing_id') === $drawing->id)>{{ $drawing->number }} · {{ $drawing->title }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-3"><textarea id="notes" name="notes" rows="2" maxlength="2000" class="input">{{ old('notes') }}</textarea></x-field>
                <div class="sm:col-span-3"><button class="btn btn-primary">{{ __('Create schedule') }}</button></div>
            </form>
        @endcan

        <div class="card overflow-x-auto">
            @if ($schedules->isEmpty())
                <x-empty :title="__('No schedules yet')" :note="__('Create a schedule, then add each bar with its shape and dimensions.')" />
            @else
                <table class="ledger min-w-[620px]">
                    <thead><tr><th>{{ __('Number') }}</th><th class="w-[44%]">{{ __('Title') }}</th><th>{{ __('Drawing') }}</th><th class="r">{{ __('Bars') }}</th><th>{{ __('Updated') }}</th></tr></thead>
                    <tbody>
                    @foreach ($schedules as $schedule)
                        <tr>
                            <td class="num text-xs"><a class="link" href="{{ route('drawings.bbs.show', $schedule) }}">{{ $schedule->number }}</a></td>
                            <td>{{ $schedule->title }}</td>
                            <td class="num text-xs">{{ $schedule->drawing?->number ?? '—' }}</td>
                            <td class="r">{{ $schedule->bars_count }}</td>
                            <td class="num text-xs">{{ $schedule->updated_at->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="p-3">{{ $schedules->links() }}</div>
            @endif
        </div>
    </div>
@endsection
