@extends('layouts.app')
@section('title', $rfi->number)
@php($stage = $rfi->stage())
@section('content')
    <x-page-header :title="$rfi->number.' · '.$rfi->subject" :subtitle="$project->name.($rfi->location ? ' · '.$rfi->location : '')">
        @include('modules.field_ops._chip', ['set' => 'rfi', 'value' => $stage])
        @if ($rfi->isEditable())
            @can('rfi.manage')
                <a href="{{ route('rfis.edit', $rfi) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('rfis.submit', $rfi) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>
            @endcan
        @endif
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            @if ($returnRemark && $rfi->status === 'RETURNED')
                <div class="rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm"><span class="font-semibold">{{ __('Returned with remarks') }}:</span> {{ $returnRemark }}</div>
            @endif

            <section class="card p-4">
                <p class="eyebrow text-ink-3">{{ __('Question') }}</p>
                <p class="mt-0.5 whitespace-pre-line">{{ $rfi->question }}</p>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="eyebrow text-ink-3">{{ __('Addressed to') }}</dt><dd>{{ $rfi->addressed_to ?? '—' }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Drawing or specification reference') }}</dt><dd>{{ $rfi->drawing_ref ?? '—' }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Needed by') }}</dt><dd class="num {{ $rfi->isOverdue() ? 'font-medium text-laterite' : '' }}">{{ $rfi->required_by?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Priority') }}</dt><dd>@include('modules.field_ops._chip', ['set' => 'severity', 'value' => $rfi->priority])</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Raised by') }}</dt><dd>{{ $rfi->raiser?->name ?? '—' }} · <span class="num">{{ $rfi->created_at->format('d M Y') }}</span></dd></div>
                </dl>
            </section>

            @if ($rfi->response !== null)
                <section class="card p-4">
                    <p class="eyebrow text-ink-3">{{ __('Answer') }}</p>
                    <p class="mt-0.5 whitespace-pre-line">{{ $rfi->response }}</p>
                    <p class="mt-3 text-xs text-ink-3">
                        <span class="num">{{ $rfi->responded_on?->format('d M Y') }}</span>@if ($rfi->responded_by) · {{ $rfi->responded_by }}@endif
                        @if ($rfi->cost_impact) · <span class="font-semibold text-laterite">{{ __('Has a cost impact') }}</span>@endif
                        @if ($rfi->time_impact_days) · {{ __(':days days added to the programme', ['days' => $rfi->time_impact_days]) }}@endif
                    </p>
                </section>
            @endif

            @if ($rfi->status === 'APPROVED' && ! $rfi->isClosed())
                @can('rfi.manage')
                    <section class="card">
                        <div class="card-head"><h2 class="card-title">{{ $rfi->response === null ? __('Record the answer') : __('Correct the answer') }}</h2></div>
                        <form method="POST" action="{{ route('rfis.respond', $rfi) }}" class="grid gap-3 p-4 sm:grid-cols-3">
                            @csrf
                            <x-field :label="__('Answer')" name="response" class="sm:col-span-3"><textarea id="response" name="response" rows="4" class="input" required>{{ old('response', $rfi->response) }}</textarea></x-field>
                            <x-field :label="__('Answered on')" name="responded_on"><input id="responded_on" name="responded_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('responded_on', $rfi->responded_on?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="input" required></x-field>
                            <x-field :label="__('Answered by')" name="responded_by"><input id="responded_by" name="responded_by" value="{{ old('responded_by', $rfi->responded_by ?? $rfi->addressed_to) }}" class="input" maxlength="120"></x-field>
                            <x-field :label="__('Days added to the programme')" name="time_impact_days"><input id="time_impact_days" name="time_impact_days" type="number" min="0" value="{{ old('time_impact_days', $rfi->time_impact_days) }}" class="input num"></x-field>
                            <label class="flex items-center gap-2 text-sm sm:col-span-3"><input type="checkbox" name="cost_impact" value="1" @checked(old('cost_impact', $rfi->cost_impact))> {{ __('The answer has a cost impact') }}</label>
                            <div class="sm:col-span-3"><button class="btn btn-primary">{{ __('Save answer') }}</button></div>
                        </form>
                        @if ($rfi->response !== null)
                            <form method="POST" action="{{ route('rfis.close', $rfi) }}" class="border-t border-rule p-4">@csrf<button class="btn btn-ink">{{ __('Close RFI') }}</button></form>
                        @endif
                    </section>
                @endcan
            @endif
        </div>

        <div class="space-y-5">
            <x-approval-trail :approvals="$rfi->approvals" />
            @include('modules.field_ops._attachments', ['owner' => $rfi, 'type' => 'rfis', 'ability' => 'rfi', 'locked' => $rfi->isClosed()])
        </div>
    </div>
@endsection
