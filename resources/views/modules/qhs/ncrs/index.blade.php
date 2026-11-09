@extends('layouts.app')
@section('title', __('NCRs'))
@section('content')
    <div x-data="{ add: {{ $errors->any() && old('title') !== null ? 'true' : 'false' }} }">
        <x-page-header :title="__('Non-conformance reports')" :subtitle="$project->name">
            @can('qhs.manage')<button type="button" class="btn btn-primary" @click="add = !add">{{ __('Raise NCR') }}</button>@endcan
        </x-page-header>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-stat :label="__('Open NCRs')" :value="$openCount" :accent="$openCount > 0" />
            <x-stat :label="__('Rework cost')" :value="inr($reworkTotal)" :note="__('Across all NCRs on this project')" />
        </div>

        @can('qhs.manage')
            <form x-show="add" x-cloak method="POST" action="{{ route('ncrs.store', $project) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <x-field :label="__('Title')" name="title" class="sm:col-span-4"><input id="title" name="title" value="{{ old('title') }}" class="input" maxlength="160" required></x-field>
                <x-field :label="__('Details')" name="description" class="sm:col-span-4"><textarea id="description" name="description" rows="2" class="input">{{ old('description') }}</textarea></x-field>
                <x-field :label="__('Category')" name="category">
                    <select id="category" name="category" class="input">
                        @foreach (config('modules.qhs.ncr_categories') as $value => $label)<option value="{{ $value }}" @selected(old('category', 'workmanship') === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Severity')" name="severity">
                    <select id="severity" name="severity" class="input">
                        @foreach (config('modules.qhs.ncr_severities') as $value => $label)<option value="{{ $value }}" @selected(old('severity', 'minor') === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Location')" name="location"><input id="location" name="location" value="{{ old('location') }}" class="input" maxlength="160"></x-field>
                <x-field :label="__('Site')" name="site_id">
                    <select id="site_id" name="site_id" class="input"><option value="">—</option>@foreach ($sites as $site)<option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>@endforeach</select>
                </x-field>
                <x-field :label="__('Assign to')" name="assigned_to">
                    <select id="assigned_to" name="assigned_to" class="input"><option value="">—</option>@foreach ($users as $user)<option value="{{ $user->id }}" @selected((string) old('assigned_to') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select>
                </x-field>
                <x-field :label="__('Due date')" name="due_date"><input id="due_date" name="due_date" type="date" value="{{ old('due_date') }}" class="input"></x-field>
                <div class="flex items-end sm:col-span-2"><button class="btn btn-primary">{{ __('Save NCR') }}</button></div>
            </form>
        @endcan

        <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
            <a href="{{ route('ncrs.index', $project) }}" class="btn {{ $status ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
            @foreach (config('modules.qhs.ncr_statuses') as $value => $label)
                <a href="{{ route('ncrs.index', [$project, 'status' => $value]) }}" class="btn {{ $status === $value ? 'btn-ink' : 'btn-ghost' }}">{{ __($label) }}</a>
            @endforeach
        </div>

        <div class="card overflow-x-auto">
            @if ($ncrs->isEmpty())
                <x-empty :title="__('No NCRs')" :note="__('Raise one when work does not meet the drawing, specification or a test.')" />
            @else
                <table class="ledger min-w-[820px]">
                    <thead><tr><th>{{ __('NCR') }}</th><th>{{ __('Category') }}</th><th>{{ __('Severity') }}</th><th>{{ __('Assigned to') }}</th><th>{{ __('Due') }}</th><th class="r">{{ __('Rework cost') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($ncrs as $ncr)
                        <tr>
                            <td>
                                <a href="{{ route('ncrs.show', $ncr) }}" class="link num">{{ $ncr->number }}</a>
                                <p class="font-semibold">{{ $ncr->title }}</p>
                                @if ($ncr->location)<p class="text-xs text-ink-3">{{ $ncr->location }}</p>@endif
                            </td>
                            <td>{{ __(config('modules.qhs.ncr_categories')[$ncr->category] ?? $ncr->category) }}</td>
                            <td>@include('modules.field_ops._chip', ['set' => 'severity', 'value' => $ncr->severity])</td>
                            <td>{{ $ncr->assignee?->name ?? '—' }}</td>
                            <td class="num text-xs {{ $ncr->isOverdue() ? 'font-medium text-laterite' : '' }}">{{ $ncr->due_date?->format('d M Y') ?? '—' }}</td>
                            <td class="r">{{ inr($ncr->rework_cost) }}</td>
                            <td>@include('modules.field_ops._chip', ['set' => 'ncr_status', 'value' => $ncr->status])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $ncrs->links() }}</div>
    </div>
@endsection
