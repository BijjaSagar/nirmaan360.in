@extends('layouts.app')
@section('title', $incident->number)
@php
    $moves = [
        'open' => ['investigating' => __('Start investigation'), 'closed' => __('Close report')],
        'investigating' => ['closed' => __('Close report')],
        'closed' => [],
    ][$incident->status];
@endphp
@section('content')
    <x-page-header :title="$incident->number" :subtitle="$project->name.($incident->location ? ' · '.$incident->location : '').' · '.$incident->occurred_at->format('d M Y H:i')">
        @include('modules.field_ops._chip', ['set' => 'kind', 'value' => $incident->kind])
        @include('modules.field_ops._chip', ['set' => 'severity', 'value' => $incident->severity])
        @include('modules.field_ops._chip', ['set' => 'incident_status', 'value' => $incident->status])
        <a href="{{ route('incidents.index', $project) }}" class="btn btn-ghost">{{ __('All reports') }}</a>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-4">
                <p class="eyebrow text-ink-3">{{ __('What happened') }}</p>
                <p class="mt-0.5 whitespace-pre-line">{{ $incident->description }}</p>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="eyebrow text-ink-3">{{ __('Injury') }}</dt><dd>@include('modules.field_ops._chip', ['set' => 'injury', 'value' => $incident->injury])@if ($incident->lost_days > 0) <span class="num ml-1">{{ __(':days days lost', ['days' => $incident->lost_days]) }}</span>@endif</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Reported by') }}</dt><dd>{{ $incident->reporter?->name ?? '—' }}</dd></div>
                    @if ($incident->site)<div><dt class="eyebrow text-ink-3">{{ __('Site') }}</dt><dd>{{ $incident->site->name }}</dd></div>@endif
                </dl>
                @if ($incident->persons)<p class="eyebrow mt-4 text-ink-3">{{ __('People involved') }}</p><p class="mt-0.5 whitespace-pre-line text-sm">{{ $incident->persons }}</p>@endif
            </section>

            <form method="POST" action="{{ route('incidents.update', $incident) }}" class="card">
                @csrf @method('PATCH')
                <div class="card-head"><h2 class="card-title">{{ __('Investigation') }}</h2></div>
                <fieldset class="grid gap-4 p-4" @disabled(! auth()->user()->can('safety.manage') || $incident->isClosed())>
                    <x-field :label="__('Immediate action taken')" name="immediate_action"><textarea id="immediate_action" name="immediate_action" rows="2" class="input">{{ old('immediate_action', $incident->immediate_action) }}</textarea></x-field>
                    <x-field :label="__('Root cause')" name="root_cause" :hint="__('Needed before the report can be closed.')"><textarea id="root_cause" name="root_cause" rows="3" class="input">{{ old('root_cause', $incident->root_cause) }}</textarea></x-field>
                    <x-field :label="__('Corrective action')" name="corrective_action" :hint="__('What was done so it does not happen again.')"><textarea id="corrective_action" name="corrective_action" rows="3" class="input">{{ old('corrective_action', $incident->corrective_action) }}</textarea></x-field>
                    @can('safety.manage')
                        @unless ($incident->isClosed())
                            <div class="flex flex-wrap gap-2">
                                <button class="btn btn-ghost">{{ __('Save') }}</button>
                                @foreach ($moves as $to => $label)<button name="status" value="{{ $to }}" class="btn {{ $to === 'closed' ? 'btn-primary' : 'btn-ink' }}">{{ $label }}</button>@endforeach
                            </div>
                        @endunless
                    @endcan
                </fieldset>
                @if ($incident->isClosed())
                    <p class="border-t border-rule px-4 py-3 text-sm text-ink-3">{{ __('Closed on :date. A closed report cannot be changed.', ['date' => $incident->closed_at->format('d M Y')]) }}</p>
                @endif
            </form>
        </div>

        <div class="space-y-5">
            @include('modules.field_ops._attachments', ['owner' => $incident, 'type' => 'incidents', 'ability' => 'safety', 'locked' => $incident->isClosed()])
        </div>
    </div>
@endsection
