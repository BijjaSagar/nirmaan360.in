@extends('layouts.app')
@section('title', __('Dashboard'))
@section('content')
    <x-page-header :title="__('Dashboard')" :subtitle="now()->format('l, d F Y')" />

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat :label="__('Active projects')" :value="$activeProjects" :note="inr($contractValue).' '.__('contract value')" />
        <x-stat :label="__('Billed to clients')" :value="inr($billed)" :note="__('Approved RA bills')" />
        <x-stat :label="__('Waiting on you')" :value="$pendingCount" :note="__('Approvals')" :accent="$pendingCount > 0" />
        <x-stat :label="__('Today on site')" :value="$presentToday" :note="trans_choice(':count DPR|:count DPRs', $dprsToday).' · '.$openIssues.' '.__('open issues')" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-5">
        <section class="card lg:col-span-3">
            <div class="card-head">
                <h2 class="card-title">{{ __('Project progress') }}</h2>
                @can('project.view')<a href="{{ route('projects.index') }}" class="link text-sm">{{ __('All projects') }}</a>@endcan
            </div>
            @forelse ($projects as $project_row)
                @php
                    $boq = (float) ($boqValues[$project_row->id] ?? 0);
                    $approved = (float) ($approvedValues[$project_row->id] ?? 0);
                    $percent = $boq > 0 ? min(100, $approved / $boq * 100) : 0;
                @endphp
                <a href="{{ route('projects.show', $project_row) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="truncate font-semibold">{{ $project_row->name }}</p>
                        <p class="num shrink-0 text-xs text-ink-2">{{ number_format($percent, 1) }}%</p>
                    </div>
                    <div class="mt-1.5 h-1.5 bg-paper-2"><div class="h-full bg-laterite" style="width: {{ $percent }}%"></div></div>
                    <p class="num mt-1 text-[11px] text-ink-3">{{ inr($approved) }} / {{ inr($boq) }} {{ __('approved of BOQ') }}</p>
                </a>
            @empty
                <x-empty :title="__('No projects yet')" :note="__('Create your first project, then add its BOQ.')">
                    @can('project.manage')<a href="{{ route('projects.create') }}" class="btn btn-primary">{{ __('New project') }}</a>@endcan
                </x-empty>
            @endforelse
        </section>

        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Waiting on you') }}</h2>
                    <a href="{{ route('approvals.index') }}" class="link text-sm">{{ __('Open inbox') }}</a>
                </div>
                @forelse ($pending as $approval)
                    <a href="{{ $approval->documentUrl() }}" class="block border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                        {{ $approval->approvable?->approvalTitle() }}
                    </a>
                @empty
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing to approve.') }}</p>
                @endforelse
            </section>

            @if ($returned->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Returned to you') }}</h2></div>
                    @foreach ($returned as $entry)
                        <a href="{{ route('measurements.show', $entry) }}" class="block border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                            {{ $entry->approvalTitle() }}
                        </a>
                    @endforeach
                </section>
            @endif

            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Latest daily reports') }}</h2></div>
                @forelse ($recentDprs as $dpr)
                    <a href="{{ route('dprs.show', $dpr) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                        <span class="min-w-0 truncate">{{ $dpr->project->name }} <span class="num text-xs text-ink-3">{{ $dpr->report_date->format('d M') }}</span></span>
                        <x-stamp :status="$dpr->status" />
                    </a>
                @empty
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No daily reports yet.') }}</p>
                @endforelse
            </section>
        </div>
    </div>
@endsection
