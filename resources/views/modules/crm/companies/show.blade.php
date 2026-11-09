@extends('layouts.app')
@section('title', $party->name)
@section('content')
    <x-page-header :title="$party->name" :subtitle="\App\Services\Crm\Vocab::partyTypes()[$party->type] ?? $party->type">
        @can('crm.manage')
            <a href="{{ route('crm.companies.edit', $party) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
            <a href="{{ route('crm.tenders.create', ['party' => $party->id]) }}" class="btn btn-primary">{{ __('Add tender') }}</a>
        @endcan
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><p class="eyebrow text-ink-3">{{ __('Contact person') }}</p><p>{{ $party->contact_person ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Mobile') }}</p><p class="num">{{ $party->phone ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Email') }}</p><p class="break-all">{{ $party->email ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('GSTIN') }}</p><p class="num">{{ $party->gstin ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('PAN') }}</p><p class="num">{{ $party->pan ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Owner') }}</p><p>{{ $profile->owner?->name ?? '—' }}</p></div>
                    <div class="sm:col-span-2 lg:col-span-3"><p class="eyebrow text-ink-3">{{ __('Address') }}</p><p>{{ $party->address ?? '—' }}</p></div>
                </div>
                @if ($profile->tags || $fieldDefinitions->isNotEmpty() || $party->notes)
                    <div class="space-y-3 border-t border-rule p-4">
                        @include('modules.crm.partials.tags', ['tags' => $profile->tags, 'route' => 'crm.companies.index'])
                        @include('modules.crm.partials.custom-values', ['values' => $profile->custom_fields ?? []])
                        @if ($party->notes)<p class="whitespace-pre-line text-sm text-ink-2">{{ $party->notes }}</p>@endif
                    </div>
                @endif
            </section>

            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Tenders') }}</h2></div>
                @forelse ($tenders as $tender)
                    <a href="{{ route('crm.tenders.show', $tender) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                        <span class="min-w-0 truncate">{{ $tender->title }}</span>
                        <span class="flex shrink-0 items-center gap-3"><span class="num text-xs">{{ inr($tender->headlineValue()) }}</span>@include('modules.crm.partials.stage', ['stage' => $tender->stage])</span>
                    </a>
                @empty
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No tenders for this company.') }}</p>
                @endforelse
            </section>

            @if ($leads->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Leads') }}</h2></div>
                    @foreach ($leads as $lead)
                        <a href="{{ route('crm.leads.show', $lead) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                            <span>{{ $lead->name }}</span><span class="text-xs text-ink-3">{{ \App\Services\Crm\Vocab::leadStatuses()[$lead->status] ?? $lead->status }}</span>
                        </a>
                    @endforeach
                </section>
            @endif

            @include('modules.crm.partials.timeline', ['timeline' => $timeline, 'subjectType' => 'company', 'subjectId' => $party->id])
        </div>

        <div class="space-y-5">
            @include('modules.crm.partials.tasks-panel', ['tasks' => $tasks, 'subjectType' => 'company', 'subjectId' => $party->id])
            @include('modules.crm.partials.message-picker', ['templates' => $templates, 'subjectType' => 'company', 'subjectId' => $party->id])
        </div>
    </div>
@endsection
