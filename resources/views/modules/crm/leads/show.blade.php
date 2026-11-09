@extends('layouts.app')
@section('title', $lead->name)
@section('content')
    @php $statuses = \App\Services\Crm\Vocab::leadStatuses(); @endphp
    <x-page-header :title="$lead->company_name ?: $lead->name" :subtitle="$lead->company_name ? $lead->name : null">
        <span class="stamp {{ $lead->status === 'converted' ? 'border-moss text-moss' : ($lead->status === 'lost' ? 'border-laterite text-laterite' : ($lead->status === 'qualified' ? 'border-neel text-neel' : 'border-ink-3 text-ink-3')) }}">{{ $statuses[$lead->status] ?? $lead->status }}</span>
        @can('crm.manage')
            <a href="{{ route('crm.leads.edit', $lead) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
            <a href="{{ route('crm.tenders.create', ['lead' => $lead->id]) }}" class="btn btn-primary">{{ __('Add tender') }}</a>
        @endcan
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><p class="eyebrow text-ink-3">{{ __('Mobile') }}</p><p class="num">{{ $lead->phone ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Email') }}</p><p class="break-all">{{ $lead->email ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Source') }}</p><p>{{ \App\Services\Crm\Vocab::sources()[$lead->source] ?? $lead->source }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Expected value') }}</p><p class="num">{{ \App\Support\Dec::of($lead->expected_value)->isPositive() ? inr($lead->expected_value) : '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Location') }}</p><p>{{ $lead->location ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Owner') }}</p><p>{{ $lead->owner?->name ?? '—' }}</p></div>
                    @if ($lead->party)<div><p class="eyebrow text-ink-3">{{ __('Company') }}</p><p><a href="{{ route('crm.companies.show', $lead->party) }}" class="link">{{ $lead->party->name }}</a></p></div>@endif
                </div>
                @if ($lead->tags || $fieldDefinitions->isNotEmpty() || $lead->notes || $lead->lost_note)
                    <div class="space-y-3 border-t border-rule p-4">
                        @include('modules.crm.partials.tags', ['tags' => $lead->tags, 'route' => 'crm.leads.index'])
                        @include('modules.crm.partials.custom-values', ['values' => $lead->custom_fields ?? []])
                        @if ($lead->notes)<p class="whitespace-pre-line text-sm text-ink-2">{{ $lead->notes }}</p>@endif
                        @if ($lead->lost_note)<p class="text-sm text-laterite">{{ __('Lost:') }} {{ $lead->lost_note }}</p>@endif
                    </div>
                @endif
            </section>

            @include('modules.crm.partials.timeline', ['timeline' => $timeline, 'subjectType' => 'lead', 'subjectId' => $lead->id])
        </div>

        <div class="space-y-5">
            @can('crm.manage')
                @if ($lead->status !== 'converted')
                    <section class="card" x-data="{ lost: {{ $lead->status === 'lost' ? 'true' : 'false' }} }">
                        <div class="card-head"><h2 class="card-title">{{ __('Status') }}</h2></div>
                        <form method="POST" action="{{ route('crm.leads.status', $lead) }}" class="space-y-2 p-4">
                            @csrf
                            <select name="status" class="input min-h-10" @change="lost = $event.target.value === 'lost'" aria-label="{{ __('Status') }}">
                                @foreach (array_diff_key($statuses, ['converted' => 1]) as $key => $label)<option value="{{ $key }}" @selected($lead->status === $key)>{{ $label }}</option>@endforeach
                            </select>
                            <input x-show="lost" x-cloak name="lost_note" value="{{ $lead->lost_note }}" class="input" maxlength="500" placeholder="{{ __('Why was it lost?') }}" aria-label="{{ __('Why was it lost?') }}">
                            <button class="btn btn-ghost w-full">{{ __('Update status') }}</button>
                        </form>
                    </section>

                    <section class="card">
                        <div class="card-head"><h2 class="card-title">{{ __('Make it a company') }}</h2></div>
                        <form method="POST" action="{{ route('crm.leads.convert', $lead) }}" class="space-y-2 p-4">
                            @csrf
                            <p class="text-sm text-ink-3">{{ __('Saves this lead in your contacts so it can be used on tenders and projects.') }}</p>
                            <select name="type" class="input min-h-10" aria-label="{{ __('Type') }}">
                                @foreach (\App\Services\Crm\Vocab::partyTypes() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                            <select name="party_id" class="input min-h-10" aria-label="{{ __('Or use an existing company') }}">
                                <option value="">{{ __('Create a new company') }}</option>
                                @foreach ($parties as $party)<option value="{{ $party->id }}">{{ $party->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-primary w-full">{{ __('Convert') }}</button>
                        </form>
                    </section>
                @endif
            @endcan

            @include('modules.crm.partials.tasks-panel', ['tasks' => $tasks, 'subjectType' => 'lead', 'subjectId' => $lead->id])
            @include('modules.crm.partials.message-picker', ['templates' => $templates, 'subjectType' => 'lead', 'subjectId' => $lead->id])

            @can('crm.manage')
                <form method="POST" action="{{ route('crm.leads.destroy', $lead) }}" class="text-right" onsubmit="return confirm('{{ __('Delete this lead?') }}')">
                    @csrf @method('DELETE')
                    <button class="link text-xs text-laterite">{{ __('Delete lead') }}</button>
                </form>
            @endcan
        </div>
    </div>
@endsection
