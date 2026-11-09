@extends('layouts.app')
@section('title', __('Follow-ups'))
@section('content')
    @php
        $scope = $filters['scope'] ?? 'mine';
        $status = $filters['status'] ?? 'open';
        $when = $filters['when'] ?? '';
        $whens = ['' => __('All'), 'overdue' => __('Overdue'), 'today' => __('Today'), 'week' => __('This week')];
    @endphp
    <x-page-header :title="__('Follow-ups')" :subtitle="__('Calls to make and things to chase')" />

    <div class="grid grid-cols-3 gap-3">
        <x-stat :label="__('Overdue')" :value="$counts['overdue']" :accent="$counts['overdue'] > 0" :note="__('Yours')" />
        <x-stat :label="__('Today')" :value="$counts['today']" :note="__('Yours')" />
        <x-stat :label="__('Open')" :value="$counts['open']" :note="__('Yours')" />
    </div>

    @can('crm.manage')
        <form method="POST" action="{{ route('crm.tasks.store') }}" class="card mt-5 grid gap-2 p-4 sm:grid-cols-6">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <input name="title" class="input sm:col-span-3" maxlength="200" required placeholder="{{ __('What needs to be done?') }}" aria-label="{{ __('Follow-up') }}">
            <input name="due_on" type="date" class="input" value="{{ now()->addDay()->toDateString() }}" required aria-label="{{ __('Due date') }}">
            <input name="due_time" type="time" class="input" aria-label="{{ __('Time (optional)') }}">
            <select name="assigned_to" class="input" aria-label="{{ __('Assigned to') }}">
                @foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected($owner->id === auth()->id())>{{ $owner->name }}</option>@endforeach
            </select>
            <div class="sm:col-span-6 flex flex-wrap items-center gap-3">
                <label class="text-xs font-semibold text-ink-2" for="remind_at">{{ __('Remind me at') }}</label>
                <input id="remind_at" name="remind_at" type="datetime-local" class="input w-auto" aria-label="{{ __('Remind me at') }}">
                <span class="hint !mt-0">{{ __('Leave blank for 9 AM on the due date.') }}</span>
                <button class="btn btn-primary ml-auto">{{ __('Add follow-up') }}</button>
            </div>
        </form>
    @endcan

    <div class="mt-5 mb-3 flex flex-wrap items-center gap-1.5">
        <a href="{{ route('crm.tasks.index', array_filter([...$filters, 'scope' => 'mine'])) }}" class="btn min-h-9 {{ $scope === 'mine' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Mine') }}</a>
        <a href="{{ route('crm.tasks.index', array_filter([...$filters, 'scope' => 'all'])) }}" class="btn min-h-9 {{ $scope === 'all' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Everyone') }}</a>
        <span class="mx-1 hidden h-5 w-px bg-rule sm:block"></span>
        @foreach ($whens as $key => $label)
            <a href="{{ route('crm.tasks.index', array_filter([...$filters, 'when' => $key])) }}" class="btn min-h-9 {{ $when === $key ? 'btn-ink' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
        <span class="mx-1 hidden h-5 w-px bg-rule sm:block"></span>
        <a href="{{ route('crm.tasks.index', array_filter([...$filters, 'status' => 'open'])) }}" class="btn min-h-9 {{ $status === 'open' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Open') }}</a>
        <a href="{{ route('crm.tasks.index', array_filter([...$filters, 'status' => 'done'])) }}" class="btn min-h-9 {{ $status === 'done' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Done') }}</a>
    </div>

    <div class="card">
        @forelse ($tasks as $task)
            @php $subject = $subjects[$task->id] ?? null; @endphp
            <div class="border-b border-rule px-4 py-3 text-sm last:border-b-0" x-data="{ edit: false }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="{{ $task->isOpen() ? '' : 'text-ink-3 line-through' }} break-words font-semibold">{{ $task->title }}</p>
                        <p class="text-xs {{ $task->isOverdue() ? 'font-semibold text-laterite' : 'text-ink-3' }}">
                            {{ $task->due_on->format('d M Y') }}{{ $task->due_time ? ' · '.$task->due_time : '' }}
                            @if ($task->isOverdue()) · {{ __('Overdue') }}@endif
                            @if ($task->assignee) · {{ $task->assignee->name }}@endif
                            @if ($subject) · <a href="{{ $subject['url'] }}" class="link">{{ $subject['title'] }}</a>@endif
                        </p>
                        @if ($task->notes)<p class="mt-0.5 whitespace-pre-line text-xs text-ink-2">{{ $task->notes }}</p>@endif
                    </div>
                    @can('crm.manage')
                        <div class="flex shrink-0 items-center gap-2">
                            <form method="POST" action="{{ $task->isOpen() ? route('crm.tasks.done', $task) : route('crm.tasks.reopen', $task) }}">@csrf<button class="btn btn-ghost min-h-9">{{ $task->isOpen() ? __('Done') : __('Reopen') }}</button></form>
                            @if ($task->isOpen())<button type="button" class="link text-xs" @click="edit = !edit">{{ __('Edit') }}</button>@endif
                        </div>
                    @endcan
                </div>
                @can('crm.manage')
                    @if ($task->isOpen())
                        <form x-show="edit" x-cloak method="POST" action="{{ route('crm.tasks.update', $task) }}" class="mt-2 grid gap-2 rounded-[3px] bg-paper-2/40 p-3 sm:grid-cols-4">
                            @csrf @method('PATCH')
                            <input type="hidden" name="subject_type" value="{{ $task->subject_type }}">
                            <input type="hidden" name="subject_id" value="{{ $task->subject_id }}">
                            <input name="title" value="{{ $task->title }}" class="input sm:col-span-4" maxlength="200" required aria-label="{{ __('Follow-up') }}">
                            <input name="due_on" type="date" value="{{ $task->due_on->toDateString() }}" class="input" required aria-label="{{ __('Due date') }}">
                            <input name="due_time" type="time" value="{{ $task->due_time }}" class="input" aria-label="{{ __('Time (optional)') }}">
                            <select name="assigned_to" class="input sm:col-span-2" aria-label="{{ __('Assigned to') }}">
                                @foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected($owner->id === $task->assigned_to)>{{ $owner->name }}</option>@endforeach
                            </select>
                            <input name="notes" value="{{ $task->notes }}" class="input sm:col-span-4" maxlength="2000" placeholder="{{ __('Notes') }}" aria-label="{{ __('Notes') }}">
                            <div class="flex gap-2 sm:col-span-4">
                                <button class="btn btn-primary min-h-10">{{ __('Save') }}</button>
                                <button type="button" class="btn btn-ghost min-h-10" @click="edit = false">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                        <form x-show="edit" x-cloak method="POST" action="{{ route('crm.tasks.destroy', $task) }}" class="mt-1 text-right" onsubmit="return confirm('{{ __('Delete this follow-up?') }}')">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Delete') }}</button></form>
                    @endif
                @endcan
            </div>
        @empty
            <x-empty :title="__('No follow-ups here')" :note="__('Add one above, or from a lead, tender or company.')" />
        @endforelse
    </div>
    <div class="mt-4">{{ $tasks->links() }}</div>
@endsection
