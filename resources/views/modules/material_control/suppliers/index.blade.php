@extends('layouts.app')
@section('title', __('Supplier records'))
@section('content')
    <x-page-header :title="__('Supplier records')" :subtitle="__('Short deliveries and ratings across all projects')" />

    <section class="card">
        @forelse ($vendors as $vendor)
            @php $summary = $summaries[$vendor->id]; @endphp
            <a href="{{ route('material_control.suppliers.show', $vendor) }}" class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $vendor->name }}</p>
                    <p class="text-sm text-ink-2">{{ __('Short deliveries: :count', ['count' => $summary['short_deliveries']]) }}</p>
                    @if ($summary['warn'])<p class="mt-0.5 text-sm font-medium text-laterite">{{ $summary['warning'] }}</p>@endif
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium">{{ $summary['rating'] }}/5</p>
                    @if ($summary['marked'])<span class="stamp border-laterite text-laterite">{{ __('Marked') }}</span>@endif
                </div>
            </a>
        @empty
            <x-empty :title="__('No suppliers yet')" :note="__('Suppliers are added when a delivery is entered, or under Contacts.')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $vendors->links() }}</div>
@endsection
