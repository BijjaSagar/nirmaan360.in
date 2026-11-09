@extends('layouts.app')
@section('title', $project->name)
@section('content')
    <x-page-header :title="$project->name" :subtitle="$project->code.($project->client ? ' · '.$project->client->name : '').($project->location ? ' · '.$project->location : '')">
        <x-stamp :status="$project->status" />
        @can('project.manage')<a href="{{ route('projects.edit', $project) }}" class="btn btn-ghost">{{ __('Edit') }}</a>@endcan
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat :label="__('Contract value')" :value="inr($project->contract_value)" />
        <x-stat :label="__('BOQ value')" :value="inr($boqValue)" :note="$itemCount.' '.__('items')" />
        <x-stat :label="__('Approved work')" :value="inr($approvedValue)" />
        <x-stat :label="__('Open issues')" :value="$openIssues" :note="$dprCount.' '.__('daily reports')" :accent="$openIssues > 0" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Latest daily reports') }}</h2></div>
            @forelse ($recentDprs as $dpr)
                <a href="{{ route('dprs.show', $dpr) }}" class="flex items-center justify-between border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span><span class="num">{{ $dpr->report_date->format('d M Y') }}</span> <span class="text-ink-3">· {{ $dpr->creator?->name }}</span></span>
                    <x-stamp :status="$dpr->status" />
                </a>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No daily reports yet.') }}</p>
            @endforelse
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('RA bills') }}</h2></div>
            @forelse ($bills as $bill)
                <a href="{{ route('ra-bills.show', $bill) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="num text-xs">{{ $bill->number }}</span>
                    <span class="num ml-auto">{{ inr($bill->net_payable) }}</span>
                    <x-stamp :status="$bill->status" />
                </a>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No bills yet.') }}</p>
            @endforelse
        </section>

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Sites') }}</h2></div>
            @if ($project->sites->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="ledger">
                        <thead><tr><th>{{ __('Site') }}</th><th>{{ __('Address') }}</th><th class="r">{{ __('Geofence (m)') }}</th></tr></thead>
                        <tbody>
                        @foreach ($project->sites as $site)
                            <tr><td>{{ $site->name }}</td><td>{{ $site->address ?? '—' }}</td><td class="r">{{ $site->geofence_radius_m }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @can('project.manage')
                <form method="POST" action="{{ route('projects.sites.store', $project) }}" class="flex flex-wrap items-end gap-3 p-4">
                    @csrf
                    <x-field :label="__('Site name')" name="name" class="min-w-48 flex-1"><input id="name" name="name" class="input" required></x-field>
                    <x-field :label="__('Address')" name="address" class="min-w-48 flex-1"><input id="address" name="address" class="input"></x-field>
                    <button class="btn btn-ghost">{{ __('Add site') }}</button>
                </form>
            @endcan
        </section>
    </div>
@endsection
