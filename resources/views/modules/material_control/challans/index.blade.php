@extends('layouts.app')
@section('title', __('Delivery challans'))
@section('content')
    <x-page-header :title="__('Delivery challans')" :subtitle="$project->name">
        @can('material_control.challan')
            <a href="{{ route('material_control.challans.create', $project) }}" class="btn btn-primary min-h-11">{{ __('New challan') }}</a>
        @endcan
    </x-page-header>

    @include('modules.material_control.partials.tabs')

    <section class="card">
        @forelse ($challans as $challan)
            <a href="{{ route('material_control.challans.show', $challan) }}" class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $challan->material->name }}</p>
                    <p class="truncate text-sm text-ink-2">{{ $challan->supplier->name }}</p>
                    <p class="num mt-0.5 text-xs text-ink-3">{{ $challan->number }} · {{ $challan->challan_date->format('d M Y') }}@if ($challan->vehicle_no) · {{ $challan->vehicle_no }}@endif</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium">{{ fmt_qty($challan->qty) }} {{ $challan->unit->code }}</p>
                    <p class="mt-1">
                        @if ($challan->status === 'REVERSED')
                            <x-stamp :status="$challan->status" />
                        @elseif ($challan->billPending())
                            <span class="stamp border-haldi text-[#8a6208]">{{ __('Bill pending') }}</span>
                        @else
                            <span class="stamp border-moss text-moss">{{ __('Bill attached') }}</span>
                        @endif
                    </p>
                </div>
            </a>
        @empty
            <x-empty :title="__('No deliveries yet')" :note="__('Enter material that arrives without a purchase order here.')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $challans->links() }}</div>
@endsection
