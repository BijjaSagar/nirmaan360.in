@extends('layouts.app')
@section('title', __('Issues'))
@section('content')
    <div x-data="{ add: false }">
        <x-page-header :title="__('Issues')" :subtitle="$project->name">
            @can('issue.manage')<button type="button" class="btn btn-primary" @click="add = !add">{{ __('Raise issue') }}</button>@endcan
        </x-page-header>

        @can('issue.manage')
            <form x-show="add" x-cloak method="POST" action="{{ route('issues.store', $project) }}" enctype="multipart/form-data" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-field :label="__('Title')" name="title" class="sm:col-span-4"><input id="title" name="title" value="{{ old('title') }}" class="input" required></x-field>
                <x-field :label="__('Details')" name="description" class="sm:col-span-4"><textarea id="description" name="description" rows="2" class="input">{{ old('description') }}</textarea></x-field>
                <x-field :label="__('Priority')" name="priority">
                    <select id="priority" name="priority" class="input">
                        @foreach (config('buildos.issue_priorities') as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', 'medium') === $priority)>{{ __(ucfirst($priority)) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Assign to')" name="assigned_to">
                    <select id="assigned_to" name="assigned_to" class="input">
                        <option value="">—</option>
                        @foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Location')" name="location_note"><input id="location_note" name="location_note" value="{{ old('location_note') }}" class="input"></x-field>
                <x-field :label="__('Due date')" name="due_date"><input id="due_date" name="due_date" type="date" value="{{ old('due_date') }}" class="input"></x-field>
                <x-field :label="__('Before photos')" name="photos" class="sm:col-span-4" :hint="__('Up to 10 photos, JPG, PNG or WebP.')">
                    <input id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple class="input">
                </x-field>
                <div class="sm:col-span-4"><button class="btn btn-primary">{{ __('Save issue') }}</button></div>
            </form>
        @endcan

        <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
            <a href="{{ route('issues.index', $project) }}" class="btn {{ $status ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
            @foreach (config('buildos.issue_statuses') as $option)
                <a href="{{ route('issues.index', [$project, 'status' => $option]) }}" class="btn {{ $status === $option ? 'btn-ink' : 'btn-ghost' }}">{{ __(\Illuminate\Support\Str::headline($option)) }}</a>
            @endforeach
        </div>

        <div class="card overflow-x-auto">
            @if ($issues->isEmpty())
                <x-empty :title="__('No issues here')" />
            @else
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>{{ __('Issue') }}</th><th>{{ __('Priority') }}</th><th>{{ __('Assignee') }}</th><th>{{ __('Due') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($issues as $issue)
                        <tr>
                            <td>
                                <p class="font-semibold">{{ $issue->title }}</p>
                                @if ($issue->description)<p class="text-xs text-ink-3">{{ \Illuminate\Support\Str::limit($issue->description, 120) }}</p>@endif
                                @if ($issue->location_note)<p class="text-xs text-ink-3">{{ $issue->location_note }}</p>@endif
                                @include('modules.billing_extras._issue-photos', ['issue' => $issue])
                            </td>
                            <td><x-stamp :status="$issue->priority" /></td>
                            <td>{{ $issue->assignee?->name ?? '—' }}</td>
                            <td class="num text-xs {{ $issue->due_date?->isPast() && ! in_array($issue->status, ['resolved', 'closed']) ? 'font-medium text-laterite' : '' }}">{{ $issue->due_date?->format('d M Y') ?? '—' }}</td>
                            <td>
                                @can('issue.manage')
                                    <form method="POST" action="{{ route('issues.update', $issue) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="assigned_to" value="{{ $issue->assigned_to }}">
                                        <select name="status" class="input py-1 text-xs" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
                                            @foreach (config('buildos.issue_statuses') as $option)
                                                <option value="{{ $option }}" @selected($issue->status === $option)>{{ __(\Illuminate\Support\Str::headline($option)) }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    <x-stamp :status="$issue->status" />
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $issues->links() }}</div>
    </div>
@endsection
