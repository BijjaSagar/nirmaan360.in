@extends('layouts.app')
@section('title', __('Leads'))
@section('content')
    @php
        $statuses = \App\Services\Crm\Vocab::leadStatuses();
        $current = $filters['status'] ?? '';
        $query = array_filter($filters);
    @endphp
    <x-page-header :title="__('Leads')" :subtitle="__('People and companies you may do work for')">
        @can('crm.manage')<a href="{{ route('crm.leads.create') }}" class="btn btn-primary">{{ __('Add lead') }}</a>@endcan
    </x-page-header>

    <div class="-mx-4 mb-3 flex gap-1.5 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
        <a href="{{ route('crm.leads.index', array_diff_key($query, ['status' => 1])) }}" class="btn min-h-9 shrink-0 {{ $current === '' ? 'btn-ink' : 'btn-ghost' }}">{{ __('All') }} <span class="num text-xs opacity-80">{{ array_sum($counts) }}</span></a>
        @foreach ($statuses as $key => $label)
            <a href="{{ route('crm.leads.index', [...$query, 'status' => $key]) }}" class="btn min-h-9 shrink-0 {{ $current === $key ? 'btn-ink' : 'btn-ghost' }}">{{ $label }} <span class="num text-xs opacity-80">{{ $counts[$key] ?? 0 }}</span></a>
        @endforeach
    </div>

    <div x-data="{ show: {{ collect($filters)->except('status')->filter()->isNotEmpty() ? 'true' : 'false' }} }" class="mb-4">
    <button type="button" class="btn btn-ghost min-h-10 w-full sm:hidden" @click="show = !show" :aria-expanded="show">{{ __('Search and filter') }}</button>
    <form method="GET" action="{{ route('crm.leads.index') }}" :class="show ? 'grid' : 'hidden sm:grid'" class="mt-2 gap-2 sm:mt-0 sm:grid-cols-2 lg:grid-cols-5">
        @if ($current !== '')<input type="hidden" name="status" value="{{ $current }}">@endif
        <input name="q" value="{{ $filters['q'] ?? '' }}" class="input lg:col-span-2" placeholder="{{ __('Search name, company, phone or email') }}" aria-label="{{ __('Search') }}">
        <select name="source" class="input" aria-label="{{ __('Source') }}">
            <option value="">{{ __('Any source') }}</option>
            @foreach (\App\Services\Crm\Vocab::sources() as $key => $label)<option value="{{ $key }}" @selected(($filters['source'] ?? '') === $key)>{{ $label }}</option>@endforeach
        </select>
        <select name="tag" class="input" aria-label="{{ __('Tags') }}">
            <option value="">{{ __('Any tag') }}</option>
            @foreach ($tagOptions as $option)<option value="{{ $option }}" @selected(($filters['tag'] ?? '') === $option)>{{ $option }}</option>@endforeach
        </select>
        <button class="btn btn-ghost">{{ __('Filter') }}</button>
    </form>
    </div>

    <div class="card overflow-x-auto">
        @if ($leads->isEmpty())
            <x-empty :title="__('No leads found')" :note="__('Add a lead when someone asks about your work, then follow up until it becomes a company or a tender.')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Lead') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Source') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('Expected value') }}</th><th>{{ __('Owner') }}</th></tr></thead>
                <tbody>
                @foreach ($leads as $lead)
                    <tr>
                        <td>
                            <a href="{{ route('crm.leads.show', $lead) }}" class="link">{{ $lead->company_name ?: $lead->name }}</a>
                            @if ($lead->company_name)<p class="text-xs text-ink-3">{{ $lead->name }}</p>@endif
                            @include('modules.crm.partials.tags', ['tags' => $lead->tags, 'route' => 'crm.leads.index'])
                        </td>
                        <td class="num text-xs">{{ $lead->phone ?? '—' }}</td>
                        <td>{{ \App\Services\Crm\Vocab::sources()[$lead->source] ?? $lead->source }}</td>
                        <td><span class="stamp {{ $lead->status === 'converted' ? 'border-moss text-moss' : ($lead->status === 'lost' ? 'border-laterite text-laterite' : ($lead->status === 'qualified' ? 'border-neel text-neel' : 'border-ink-3 text-ink-3')) }}">{{ $statuses[$lead->status] ?? $lead->status }}</span></td>
                        <td class="r">{{ \App\Support\Dec::of($lead->expected_value)->isPositive() ? inr($lead->expected_value) : '—' }}</td>
                        <td>{{ $lead->owner?->name ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $leads->links() }}</div>
@endsection
