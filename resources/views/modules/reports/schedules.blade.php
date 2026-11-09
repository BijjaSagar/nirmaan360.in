@extends('layouts.app')
@section('title', __('Scheduled emails'))
@section('content')
    @php
        $defs = collect($available)->map(fn ($def) => ['project' => $def['project'], 'period' => $def['period']])->all();
        $statusText = fn ($schedule) => match ($schedule->last_status) {
            'sent' => __('Sent :date', ['date' => $schedule->last_run_at?->format('d M Y H:i')]),
            'failed' => __('Failed on :date. It will try again at the next time.', ['date' => $schedule->last_run_at?->format('d M Y H:i')]),
            'no_mail' => __('Waiting: email is not set up.'),
            'no_owner' => __('Skipped: the person who saved it is no longer active.'),
            'no_access' => __('Skipped: that person can no longer open this report.'),
            'no_project' => __('Skipped: the project no longer exists.'),
            'sending' => __('Sending'),
            default => __('Not sent yet'),
        };
    @endphp

    <x-page-header :title="__('Scheduled emails')" :subtitle="__('Email a report on a regular schedule, as an Excel file or a PDF.')">
        <a href="{{ route('reports.index') }}" class="btn btn-ghost">{{ __('Reports') }}</a>
    </x-page-header>

    @unless ($mailConfigured)
        <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm text-ink-2" role="status">
            <p class="font-semibold">{{ __('Email is not set up yet.') }}</p>
            <p>{{ __('You can save schedules now, but nothing will be sent until the platform admin sets up email. Schedules then send at their next time.') }}</p>
        </div>
    @endunless

    @can('report.manage')
        @if ($available === [])
            <div class="card mb-5"><x-empty :title="__('No reports for your role')" :note="__('Ask an owner or admin to give you access to the data these reports are built from.')" /></div>
        @else
            <form id="new" method="POST" action="{{ route('reports.schedules.store') }}" class="card mb-5"
                  x-data="{ report: @js(old('report', $defaults['report'] ?? array_key_first($available))), frequency: @js(old('frequency', 'weekly')), defs: @js($defs) }">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <div class="card-head"><h2 class="card-title">{{ __('New schedule') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-field :label="__('Name (optional)')" name="name" class="lg:col-span-2"><input id="name" name="name" value="{{ old('name') }}" maxlength="120" class="input" placeholder="{{ __('Monday site review') }}"></x-field>
                    <x-field :label="__('Report')" name="report">
                        <select id="report" name="report" x-model="report" class="input" required>
                            @foreach ($available as $key => $def)<option value="{{ $key }}">{{ __($def['label']) }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Project')" name="project_id">
                        <select id="project_id" name="project_id" class="input" :required="defs[report].project">
                            <option value="">{{ __('All projects') }}</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}" @selected((int) old('project_id', $defaults['project_id'] ?? 0) === $project->id)>{{ $project->code }} · {{ $project->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Period covered')" name="period" x-show="defs[report].period" :hint="__('Worked out again each time it is sent.')">
                        <select id="period" name="period" class="input" :disabled="! defs[report].period">
                            @foreach ($periods as $key => $label)<option value="{{ $key }}" @selected(old('period', $defaults['period'] ?? 'last_30_days') === $key)>{{ __($label) }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('File')" name="format">
                        <select id="format" name="format" class="input">
                            <option value="xlsx" @selected(old('format') === 'xlsx')>{{ __('Excel (.xlsx)') }}</option>
                            <option value="pdf" @selected(old('format') === 'pdf')>PDF</option>
                        </select>
                    </x-field>
                    <x-field :label="__('Repeat')" name="frequency">
                        <select id="frequency" name="frequency" x-model="frequency" class="input">
                            <option value="daily">{{ __('Every day') }}</option>
                            <option value="weekly">{{ __('Every week') }}</option>
                            <option value="monthly">{{ __('Every month') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('Day')" name="day_of_week" x-show="frequency === 'weekly'">
                        <select id="day_of_week" name="day_of_week" class="input" :disabled="frequency !== 'weekly'">
                            @foreach ($days as $number => $day)<option value="{{ $number }}" @selected((int) old('day_of_week', 1) === $number)>{{ __($day) }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Day of month')" name="day_of_month" x-show="frequency === 'monthly'" x-cloak :hint="__('Up to 28, so every month has it.')">
                        <input id="day_of_month" name="day_of_month" type="number" min="1" max="28" value="{{ old('day_of_month', 1) }}" class="input num" :disabled="frequency !== 'monthly'">
                    </x-field>
                    <x-field :label="__('Time (India)')" name="hour">
                        <select id="hour" name="hour" class="input">
                            @for ($hour = 0; $hour < 24; $hour++)<option value="{{ $hour }}" @selected((int) old('hour', 7) === $hour)>{{ sprintf('%02d:00', $hour) }}</option>@endfor
                        </select>
                    </x-field>
                    <x-field :label="__('Send to')" name="recipients" class="sm:col-span-2" :hint="__('Email addresses separated by commas. Up to :max.', ['max' => $maxRecipients])">
                        <input id="recipients" name="recipients" value="{{ is_array(old('recipients')) ? implode(', ', old('recipients')) : old('recipients', auth()->user()->email) }}" class="input" required>
                        @error('recipients.*')<p class="error">{{ $message }}</p>@enderror
                    </x-field>
                    <div class="flex items-end"><button class="btn btn-primary w-full sm:w-auto">{{ __('Save schedule') }}</button></div>
                </div>
            </form>
        @endif
    @endcan

    <div class="card overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Saved schedules') }}</h2><span class="num text-sm text-ink-3">{{ $schedules->count() }} / {{ $limit }}</span></div>
        @if ($schedules->isEmpty())
            <x-empty :title="__('No schedules yet')" :note="__('Pick a report above and choose when it should arrive.')" />
        @else
            <table class="ledger min-w-[860px]">
                <thead><tr><th>{{ __('Report') }}</th><th>{{ __('When') }}</th><th>{{ __('Send to') }}</th><th>{{ __('Last result') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($schedules as $schedule)
                    @php $definition = \App\Services\Reports\ReportBuilder::DEFINITIONS[$schedule->report] ?? null; @endphp
                    <tr class="{{ $schedule->active ? '' : 'opacity-60' }}">
                        <td>
                            <p class="font-semibold">{{ $schedule->name ?: __($definition['label'] ?? $schedule->report) }}</p>
                            <p class="text-xs text-ink-3">
                                {{ __($definition['label'] ?? $schedule->report) }} · {{ $schedule->project ? $schedule->project->name : __('All projects') }}
                                @if ($definition && $definition['period']) · {{ __(\App\Services\Reports\ReportPeriod::LABELS[$schedule->period] ?? $schedule->period) }} @endif
                                · {{ $schedule->format === 'pdf' ? 'PDF' : 'Excel' }}
                            </p>
                        </td>
                        <td>{{ $service->describe($schedule) }}@unless ($schedule->active)<span class="stamp border-ink-3 text-ink-3 ml-1">{{ __('Paused') }}</span>@endunless</td>
                        <td class="text-sm">{{ implode(', ', $schedule->recipients) }}</td>
                        <td class="text-sm text-ink-2">{{ $statusText($schedule) }}</td>
                        <td class="whitespace-nowrap text-right">
                            @can('report.manage')
                                <form method="POST" action="{{ route('reports.schedules.toggle', $schedule) }}" class="inline">@csrf @method('PATCH')<button class="link text-xs">{{ $schedule->active ? __('Pause') : __('Resume') }}</button></form>
                                <form method="POST" action="{{ route('reports.schedules.destroy', $schedule) }}" class="ml-2 inline" onsubmit="return confirm(@js(__('Delete this schedule?')))">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Delete') }}</button></form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
