{{-- Follow-ups on one lead, tender or company. Needs $tasks, $subjectType and $subjectId. --}}
<section class="card">
    <div class="card-head">
        <h2 class="card-title">{{ __('Follow-ups') }}</h2>
        <a href="{{ route('crm.tasks.index') }}" class="link text-xs">{{ __('All follow-ups') }}</a>
    </div>
    @can('crm.manage')
        <form method="POST" action="{{ route('crm.tasks.store') }}" class="grid grid-cols-2 gap-2 border-b border-rule p-4">
            @csrf
            <input type="hidden" name="subject_type" value="{{ $subjectType }}">
            <input type="hidden" name="subject_id" value="{{ $subjectId }}">
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <input name="title" class="input col-span-2" maxlength="200" required placeholder="{{ __('What needs to be done?') }}" aria-label="{{ __('Follow-up') }}">
            <input name="due_on" type="date" class="input" value="{{ now()->addDay()->toDateString() }}" required aria-label="{{ __('Due date') }}">
            <input name="due_time" type="time" class="input" aria-label="{{ __('Time (optional)') }}">
            <button class="btn btn-ghost col-span-2">{{ __('Add follow-up') }}</button>
        </form>
    @endcan
    @forelse ($tasks as $task)
        <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
            <div class="min-w-0">
                <p class="{{ $task->isOpen() ? '' : 'text-ink-3 line-through' }} break-words font-semibold">{{ $task->title }}</p>
                <p class="text-xs {{ $task->isOverdue() ? 'font-semibold text-laterite' : 'text-ink-3' }}">
                    {{ $task->due_on->format('d M Y') }}{{ $task->due_time ? ' · '.$task->due_time : '' }}
                    @if ($task->assignee) · {{ $task->assignee->name }} @endif
                    @if ($task->isOverdue()) · {{ __('Overdue') }} @endif
                </p>
            </div>
            @can('crm.manage')
                <form method="POST" action="{{ $task->isOpen() ? route('crm.tasks.done', $task) : route('crm.tasks.reopen', $task) }}">
                    @csrf
                    <button class="btn btn-ghost min-h-9">{{ $task->isOpen() ? __('Done') : __('Reopen') }}</button>
                </form>
            @endcan
        </div>
    @empty
        <p class="px-4 py-5 text-sm text-ink-3">{{ __('No follow-ups yet.') }}</p>
    @endforelse
</section>
