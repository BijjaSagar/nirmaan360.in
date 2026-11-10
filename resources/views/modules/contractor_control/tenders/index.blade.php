@extends('layouts.app')
@section('title', __('Contractor tenders'))
@section('content')
    <x-page-header :title="__('Contractor tenders')" :subtitle="$project->name">
        @can('contractor.tender')
            <a href="{{ route('contractor-control.tenders.create', $project) }}" class="btn btn-primary">{{ __('New tender') }}</a>
        @endcan
    </x-page-header>

    <section class="card">
        @forelse ($tenders as $tender)
            <a href="{{ route('contractor-control.tenders.show', $tender) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $tender->title }}</p>
                        <p class="text-xs text-ink-3"><span class="num">{{ $tender->number }}</span> · {{ trans_choice(':count item|:count items', $tender->items->count(), ['count' => $tender->items->count()]) }} · {{ trans_choice(':count quote|:count quotes', $tender->bids->count(), ['count' => $tender->bids->count()]) }}</p>
                    </div>
                    @include('modules.contractor_control.tenders._status', ['status' => $tender->status])
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 text-xs">
                    <div><dt class="text-ink-3">{{ __('BOQ amount') }}</dt><dd class="num">{{ inr($tender->boqAmount()) }}</dd></div>
                    @if ($tender->workOrder)
                        <div><dt class="text-ink-3">{{ __('Contract') }}</dt><dd class="num">{{ $tender->workOrder->number }}</dd></div>
                    @endif
                </dl>
            </a>
        @empty
            <x-empty :title="__('No tenders yet')" :note="__('Pick BOQ items, collect quotes from contractors and compare them against the BOQ.')">
                @can('contractor.tender')
                    <a href="{{ route('contractor-control.tenders.create', $project) }}" class="btn btn-primary">{{ __('New tender') }}</a>
                @endcan
            </x-empty>
        @endforelse
    </section>

    <div class="mt-3">{{ $tenders->links() }}</div>
@endsection
