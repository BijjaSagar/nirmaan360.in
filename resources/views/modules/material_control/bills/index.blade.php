@extends('layouts.app')
@section('title', __('Bills'))
@section('content')
    <x-page-header :title="__('Bills against challans')" :subtitle="$project->name" />

    @include('modules.material_control.partials.tabs')

    <div class="mb-3 flex gap-2">
        <a href="{{ route('material_control.bills.index', $project) }}" class="btn min-h-11 {{ $pending ? 'btn-ink' : 'btn-ghost' }}">{{ __('Bill pending') }}</a>
        <a href="{{ route('material_control.bills.index', [$project, 'show' => 'attached']) }}" class="btn min-h-11 {{ $pending ? 'btn-ghost' : 'btn-ink' }}">{{ __('Bill attached') }}</a>
    </div>

    <section class="card">
        @forelse ($challans as $challan)
            <a href="{{ route('material_control.challans.show', $challan) }}" class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $challan->supplier->name }}</p>
                    <p class="truncate text-sm text-ink-2">{{ $challan->material->name }}</p>
                    <p class="num mt-0.5 text-xs text-ink-3">{{ $challan->number }} · {{ $challan->challan_date->format('d M Y') }}@if ($challan->challan_no) · {{ $challan->challan_no }}@endif</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium">{{ fmt_qty($challan->qty) }} {{ $challan->unit->code }}</p>
                    @can('money.view')
                        @if ($challan->bill_amount)<p class="num text-xs">{{ inr($challan->bill_amount) }}</p>@endif
                    @endcan
                    <p class="mt-1">
                        @if ($challan->billPending())
                            <span class="stamp border-haldi text-[#8a6208]">{{ __('Bill pending') }}</span>
                        @else
                            <span class="stamp border-moss text-moss">{{ __('Bill attached') }}</span>
                        @endif
                    </p>
                </div>
            </a>
        @empty
            <x-empty :title="$pending ? __('No bills pending') : __('No bills attached yet')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $challans->links() }}</div>
@endsection
