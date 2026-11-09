@extends('layouts.app')
@section('title', __('Safety'))
@section('content')
    <div x-data="{ add: {{ $errors->any() && old('description') !== null ? 'true' : 'false' }}, kind: @js(old('kind', 'incident')) }">
        <x-page-header :title="__('Safety')" :subtitle="$project->name">
            @can('safety.manage')<button type="button" class="btn btn-primary" @click="add = !add">{{ __('Report incident or near miss') }}</button>@endcan
        </x-page-header>

        @include('modules.qhs.safety._tabs', ['project' => $project, 'active' => 'incidents'])

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-stat :label="__('Incidents')" :value="$counts['incidents']" />
            <x-stat :label="__('Near misses')" :value="$counts['near_misses']" />
            <x-stat :label="__('Not closed yet')" :value="$counts['open']" :accent="$counts['open'] > 0" />
            <x-stat :label="__('Days lost')" :value="$counts['lost_days']" :accent="$counts['lost_days'] > 0" />
        </div>

        @can('safety.manage')
            <form x-show="add" x-cloak method="POST" action="{{ route('incidents.store', $project) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <x-field :label="__('What is it')" name="kind">
                    <select id="kind" name="kind" class="input" x-model="kind">
                        @foreach (config('modules.qhs.incident_kinds') as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Severity')" name="severity">
                    <select id="severity" name="severity" class="input">
                        @foreach (config('modules.qhs.incident_severities') as $value => $label)<option value="{{ $value }}" @selected(old('severity', 'low') === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('When')" name="occurred_at"><input id="occurred_at" name="occurred_at" type="datetime-local" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" class="input" required></x-field>
                <x-field :label="__('Location')" name="location"><input id="location" name="location" value="{{ old('location') }}" class="input" maxlength="160"></x-field>
                <div x-show="kind === 'incident'" class="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                    <x-field :label="__('Injury')" name="injury">
                        <select id="injury" name="injury" class="input">
                            @foreach (config('modules.qhs.injuries') as $value => $label)<option value="{{ $value }}" @selected(old('injury', 'none') === $value)>{{ __($label) }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Days lost')" name="lost_days"><input id="lost_days" name="lost_days" type="number" min="0" value="{{ old('lost_days') }}" class="input num"></x-field>
                </div>
                <x-field :label="__('Site')" name="site_id">
                    <select id="site_id" name="site_id" class="input"><option value="">—</option>@foreach ($sites as $site)<option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>@endforeach</select>
                </x-field>
                <x-field :label="__('What happened')" name="description" class="sm:col-span-4"><textarea id="description" name="description" rows="3" class="input" required>{{ old('description') }}</textarea></x-field>
                <x-field :label="__('People involved')" name="persons" class="sm:col-span-2"><textarea id="persons" name="persons" rows="2" class="input">{{ old('persons') }}</textarea></x-field>
                <x-field :label="__('Immediate action taken')" name="immediate_action" class="sm:col-span-2"><textarea id="immediate_action" name="immediate_action" rows="2" class="input">{{ old('immediate_action') }}</textarea></x-field>
                <div class="sm:col-span-4"><button class="btn btn-primary">{{ __('Save report') }}</button></div>
            </form>
        @endcan

        <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
            <a href="{{ route('incidents.index', $project) }}" class="btn {{ $kind ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
            @foreach (config('modules.qhs.incident_kinds') as $value => $label)
                <a href="{{ route('incidents.index', [$project, 'kind' => $value]) }}" class="btn {{ $kind === $value ? 'btn-ink' : 'btn-ghost' }}">{{ __($label) }}</a>
            @endforeach
        </div>

        <div class="card overflow-x-auto">
            @if ($incidents->isEmpty())
                <x-empty :title="__('Nothing reported')" :note="__('Report every incident and near miss. Near misses are how the next injury is avoided.')" />
            @else
                <table class="ledger min-w-[780px]">
                    <thead><tr><th>{{ __('Report') }}</th><th>{{ __('Type') }}</th><th>{{ __('Severity') }}</th><th>{{ __('Injury') }}</th><th>{{ __('When') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($incidents as $incident)
                        <tr>
                            <td>
                                <a href="{{ route('incidents.show', $incident) }}" class="link num">{{ $incident->number }}</a>
                                <p class="text-sm">{{ \Illuminate\Support\Str::limit($incident->description, 90) }}</p>
                            </td>
                            <td>@include('modules.field_ops._chip', ['set' => 'kind', 'value' => $incident->kind])</td>
                            <td>@include('modules.field_ops._chip', ['set' => 'severity', 'value' => $incident->severity])</td>
                            <td>@include('modules.field_ops._chip', ['set' => 'injury', 'value' => $incident->injury])</td>
                            <td class="num text-xs">{{ $incident->occurred_at->format('d M Y H:i') }}</td>
                            <td>@include('modules.field_ops._chip', ['set' => 'incident_status', 'value' => $incident->status])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $incidents->links() }}</div>
    </div>
@endsection
