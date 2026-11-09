@extends('layouts.app')
@section('title', __('Approvals'))
@section('content')
    <x-page-header :title="__('Approvals')" />

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Waiting on you') }}</h2><span class="num text-sm">{{ $mine->count() }}</span></div>
        @forelse ($mine as $approval)
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <a href="{{ $approval->documentUrl() }}" class="link">{{ $approval->approvable?->approvalTitleFor(auth()->user()) }}</a>
                    <p class="text-xs text-ink-3">{{ $approval->submitter?->name }} · <span class="num">{{ $approval->submitted_at->format('d M H:i') }}</span> · {{ __('Level :current of :total', ['current' => $approval->current_level, 'total' => count($approval->levels)]) }}</p>
                </div>
                @if ($approval->approvable?->deviation)
                    <x-stamp status="deviation" class="mt-1" />
                @endif
                @includeWhen($approval->doc_type === 'contractor' && $approval->approvable, 'modules.contractor_control._approval-ratio', ['payment' => $approval->approvable])
                @include('approvals._act', ['approval' => $approval])
            </div>
        @empty
            <x-empty :title="__('Nothing to approve')" :note="__('Items waiting for your role will show up here.')" />
        @endforelse
    </section>

    @if ($waiting->isNotEmpty())
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('In review with others') }}</h2></div>
            @foreach ($waiting as $approval)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                    <a href="{{ $approval->documentUrl() }}" class="link">{{ $approval->approvable?->approvalTitleFor(auth()->user()) }}</a>
                    <span class="text-xs text-ink-3">{{ __('Waiting for: :role', ['role' => __($approval->currentRoleLabel())]) }}</span>
                </div>
            @endforeach
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Recently closed') }}</h2></div>
            @foreach ($recent as $approval)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                    <a href="{{ $approval->documentUrl() }}" class="link">{{ $approval->approvable?->approvalTitleFor(auth()->user()) }}</a>
                    <x-stamp :status="$approval->status" />
                </div>
            @endforeach
        </section>
    @endif
@endsection
