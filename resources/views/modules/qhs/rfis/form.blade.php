@extends('layouts.app')
@php $editing = $rfi->exists; @endphp
@section('title', $editing ? __('Edit RFI') : __('New RFI'))
@section('content')
    <x-page-header :title="$editing ? $rfi->number : __('New RFI')" :subtitle="$project->name" />

    <form method="POST" action="{{ $editing ? route('rfis.update', $rfi) : route('rfis.store', $project) }}" class="card max-w-3xl grid gap-4 p-4 sm:grid-cols-2">
        @csrf
        @if ($editing) @method('PATCH') @else <input type="hidden" name="client_uuid" value="{{ $clientUuid }}"> @endif
        <x-field :label="__('Subject')" name="subject" class="sm:col-span-2"><input id="subject" name="subject" value="{{ old('subject', $rfi->subject) }}" class="input" maxlength="160" required></x-field>
        <x-field :label="__('Question')" name="question" class="sm:col-span-2"><textarea id="question" name="question" rows="5" class="input" required>{{ old('question', $rfi->question) }}</textarea></x-field>
        <x-field :label="__('Drawing or specification reference')" name="drawing_ref"><input id="drawing_ref" name="drawing_ref" value="{{ old('drawing_ref', $rfi->drawing_ref) }}" class="input" maxlength="120"></x-field>
        <x-field :label="__('Location')" name="location"><input id="location" name="location" value="{{ old('location', $rfi->location) }}" class="input" maxlength="160"></x-field>
        <x-field :label="__('Addressed to')" name="addressed_to"><input id="addressed_to" name="addressed_to" value="{{ old('addressed_to', $rfi->addressed_to) }}" class="input" maxlength="120" placeholder="{{ __('Consultant, client engineer') }}"></x-field>
        <x-field :label="__('Priority')" name="priority">
            <select id="priority" name="priority" class="input">
                @foreach (config('buildos.issue_priorities') as $priority)<option value="{{ $priority }}" @selected(old('priority', $rfi->priority) === $priority)>{{ __(ucfirst($priority)) }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('Needed by')" name="required_by"><input id="required_by" name="required_by" type="date" value="{{ old('required_by', $rfi->required_by?->format('Y-m-d')) }}" class="input"></x-field>
        <div class="flex gap-2 sm:col-span-2">
            <button class="btn btn-primary">{{ __('Save draft') }}</button>
            <a href="{{ $editing ? route('rfis.show', $rfi) : route('rfis.index', $project) }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
