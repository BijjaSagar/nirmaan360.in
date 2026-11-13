@extends('layouts.app')
@section('title', __('Quotations'))
@section('content')
    <x-page-header :title="__('Quotations')" :subtitle="$project->name">
        @can('owner_pack.manage')
            <a href="{{ route('quotations.create', $project) }}" class="btn btn-primary">{{ __('New quotation') }}</a>
        @endcan
    </x-page-header>

    <section class="card">
        @forelse ($quotations as $quotation)
            <a href="{{ route('quotations.show', $quotation) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $quotation->client_name }}</p>
                        <p class="text-xs text-ink-3"><span class="num">{{ $quotation->number }}</span> · {{ __('Revision :n', ['n' => $quotation->revision]) }} · {{ trans_choice(':count line|:count lines', $quotation->items_count, ['count' => $quotation->items_count]) }}</p>
                    </div>
                    @include('modules.owner_pack.quotations._status', ['status' => $quotation->status])
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 text-xs">
                    <div><dt class="text-ink-3">{{ __('Quotation total') }}</dt><dd class="num">{{ inr($quotation->total) }}</dd></div>
                    @if ($quotation->valid_until)
                        <div><dt class="text-ink-3">{{ __('Valid until') }}</dt><dd class="num">{{ $quotation->valid_until->format('d M Y') }}</dd></div>
                    @endif
                </dl>
            </a>
        @empty
            <x-empty :title="__('No quotations yet')" :note="__('Choose BOQ lines, add the payment stages, check every figure and share the quotation with your client as a link.')">
                @can('owner_pack.manage')
                    <a href="{{ route('quotations.create', $project) }}" class="btn btn-primary">{{ __('New quotation') }}</a>
                @endcan
            </x-empty>
        @endforelse
    </section>

    <div class="mt-3">{{ $quotations->links() }}</div>
@endsection
