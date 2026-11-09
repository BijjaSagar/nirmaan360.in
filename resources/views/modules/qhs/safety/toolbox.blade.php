@extends('layouts.app')
@section('title', __('Toolbox talks'))
@section('content')
    <x-page-header :title="__('Toolbox talks')" :subtitle="$project->name" />

    @include('modules.qhs.safety._tabs', ['project' => $project, 'active' => 'toolbox'])

    @can('safety.manage')
        <form method="POST" action="{{ route('incidents.toolbox.store', $project) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
            <x-field :label="__('Date')" name="talk_date"><input id="talk_date" name="talk_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('talk_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Topic')" name="topic" class="sm:col-span-2"><input id="topic" name="topic" value="{{ old('topic') }}" class="input" maxlength="160" required placeholder="{{ __('Working at height, lifting, fire safety') }}"></x-field>
            <x-field :label="__('Conducted by')" name="conducted_by"><input id="conducted_by" name="conducted_by" value="{{ old('conducted_by', auth()->user()->name) }}" class="input" maxlength="80" required></x-field>
            <x-field :label="__('Minutes')" name="duration_minutes"><input id="duration_minutes" name="duration_minutes" type="number" min="1" max="480" value="{{ old('duration_minutes') }}" class="input num"></x-field>
            <x-field :label="__('Site')" name="site_id">
                <select id="site_id" name="site_id" class="input"><option value="">—</option>@foreach ($sites as $site)<option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>@endforeach</select>
            </x-field>
            <x-field :label="__('Attendees')" name="attendees" class="sm:col-span-2" :hint="__('One name per line. The headcount is counted from the lines.')"><textarea id="attendees" name="attendees" rows="4" class="input">{{ old('attendees') }}</textarea></x-field>
            <x-field :label="__('Headcount, if names are not listed')" name="attendee_count"><input id="attendee_count" name="attendee_count" type="number" min="0" value="{{ old('attendee_count') }}" class="input num"></x-field>
            <x-field :label="__('Notes')" name="notes" class="sm:col-span-3"><input id="notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="3000"></x-field>
            <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Save talk') }}</button></div>
        </form>
    @endcan

    <div class="card">
        @forelse ($talks as $talk)
            <details class="border-b border-rule px-4 py-3 last:border-b-0">
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                    <span><span class="num text-sm">{{ $talk->talk_date->format('d M Y') }}</span> <span class="font-semibold">· {{ $talk->topic }}</span></span>
                    <span class="text-xs text-ink-3">{{ $talk->conducted_by }} · {{ __(':count attended', ['count' => $talk->attendee_count]) }}@if ($talk->duration_minutes) · {{ $talk->duration_minutes }} {{ __('min') }}@endif</span>
                </summary>
                @if ($talk->attendeeNames())<p class="mt-2 text-sm text-ink-2">{{ implode(', ', $talk->attendeeNames()) }}</p>@endif
                @if ($talk->notes)<p class="mt-1 whitespace-pre-line text-sm text-ink-3">{{ $talk->notes }}</p>@endif
            </details>
        @empty
            <x-empty :title="__('No toolbox talks yet')" :note="__('Record the short safety talk held before work starts, with who attended.')" />
        @endforelse
    </div>
    <div class="mt-4">{{ $talks->links() }}</div>
@endsection
