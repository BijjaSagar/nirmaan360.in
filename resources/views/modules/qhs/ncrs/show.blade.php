@extends('layouts.app')
@section('title', $ncr->number)
@php
    $moves = [
        'open' => ['in_progress' => __('Start work'), 'resolved' => __('Mark resolved')],
        'in_progress' => ['resolved' => __('Mark resolved')],
        'resolved' => ['in_progress' => __('Reopen work'), 'closed' => __('Close NCR')],
        'closed' => [],
    ][$ncr->status];
@endphp
@section('content')
    <x-page-header :title="$ncr->number.' · '.$ncr->title" :subtitle="$project->name.($ncr->site ? ' · '.$ncr->site->name : '').($ncr->location ? ' · '.$ncr->location : '')">
        @include('modules.field_ops._chip', ['set' => 'severity', 'value' => $ncr->severity])
        @include('modules.field_ops._chip', ['set' => 'ncr_status', 'value' => $ncr->status])
        <a href="{{ route('ncrs.index', $project) }}" class="btn btn-ghost">{{ __('All NCRs') }}</a>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-4">
                <p class="eyebrow text-ink-3">{{ __('What was found') }}</p>
                <p class="mt-0.5 whitespace-pre-line">{{ $ncr->description ?? '—' }}</p>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="eyebrow text-ink-3">{{ __('Category') }}</dt><dd>{{ __(config('modules.qhs.ncr_categories')[$ncr->category] ?? $ncr->category) }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Raised') }}</dt><dd class="num">{{ $ncr->raised_on->format('d M Y') }} · {{ $ncr->raiser?->name ?? '—' }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Due date') }}</dt><dd class="num {{ $ncr->isOverdue() ? 'font-medium text-laterite' : '' }}">{{ $ncr->due_date?->format('d M Y') ?? '—' }}</dd></div>
                </dl>
            </section>

            <form method="POST" action="{{ route('ncrs.update', $ncr) }}" class="card">
                @csrf @method('PATCH')
                <div class="card-head"><h2 class="card-title">{{ __('Investigation and rework') }}</h2></div>
                <fieldset class="grid gap-4 p-4 sm:grid-cols-2" @disabled(! auth()->user()->can('qhs.manage') || $ncr->isClosed())>
                    <x-field :label="__('Root cause group')" name="root_cause_category">
                        <select id="root_cause_category" name="root_cause_category" class="input">
                            <option value="">—</option>
                            @foreach (config('modules.qhs.root_causes') as $value => $label)<option value="{{ $value }}" @selected(old('root_cause_category', $ncr->root_cause_category) === $value)>{{ __($label) }}</option>@endforeach
                        </select>
                    </x-field>
                    @can('money.view')
                        <x-field :label="__('Rework cost (₹)')" name="rework_cost"><input id="rework_cost" name="rework_cost" type="number" step="0.01" min="0" value="{{ old('rework_cost', $ncr->rework_cost) }}" class="input num"></x-field>
                    @endcan
                    <x-field :label="__('Root cause')" name="root_cause" class="sm:col-span-2" :hint="__('Why did it happen? Needed before the NCR can be resolved.')"><textarea id="root_cause" name="root_cause" rows="3" class="input">{{ old('root_cause', $ncr->root_cause) }}</textarea></x-field>
                    <x-field :label="__('Corrective action')" name="corrective_action" :hint="__('What was done to put it right.')"><textarea id="corrective_action" name="corrective_action" rows="3" class="input">{{ old('corrective_action', $ncr->corrective_action) }}</textarea></x-field>
                    <x-field :label="__('Preventive action')" name="preventive_action" :hint="__('What stops it happening again.')"><textarea id="preventive_action" name="preventive_action" rows="3" class="input">{{ old('preventive_action', $ncr->preventive_action) }}</textarea></x-field>
                    <x-field :label="__('Assigned to')" name="assigned_to">
                        <select id="assigned_to" name="assigned_to" class="input">
                            <option value="">—</option>
                            @foreach ($users as $user)<option value="{{ $user->id }}" @selected((string) old('assigned_to', $ncr->assigned_to) === (string) $user->id)>{{ $user->name }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Due date')" name="due_date"><input id="due_date" name="due_date" type="date" value="{{ old('due_date', $ncr->due_date?->format('Y-m-d')) }}" class="input"></x-field>
                    @can('qhs.manage')
                        @unless ($ncr->isClosed())
                            <div class="flex flex-wrap gap-2 sm:col-span-2">
                                <button class="btn btn-ghost">{{ __('Save') }}</button>
                                @foreach ($moves as $to => $label)
                                    <button name="status" value="{{ $to }}" class="btn {{ $to === 'closed' ? 'btn-primary' : 'btn-ink' }}">{{ $label }}</button>
                                @endforeach
                            </div>
                        @endunless
                    @endcan
                </fieldset>
                @if ($ncr->isClosed())
                    <p class="border-t border-rule px-4 py-3 text-sm text-ink-3">{{ __('Closed on :date. A closed NCR is a QA record and cannot be changed.', ['date' => $ncr->closed_at->format('d M Y')]) }}</p>
                @endif
            </form>
        </div>

        <div class="space-y-5">
            @include('modules.field_ops._attachments', ['owner' => $ncr, 'type' => 'ncrs', 'ability' => 'qhs', 'locked' => $ncr->isClosed()])
        </div>
    </div>
@endsection
