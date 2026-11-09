@extends('layouts.app')
@section('title', $party->name)
@section('content')
    <x-page-header :title="$party->name" :subtitle="__('Supplier record')">
        <a href="{{ route('material_control.suppliers.index') }}" class="btn btn-ghost min-h-11">{{ __('All suppliers') }}</a>
    </x-page-header>

    @if ($summary['warn'])
        <p class="mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite" role="alert">{{ $summary['warning'] }}</p>
    @endif

    <div class="mb-5 grid grid-cols-3 gap-3">
        <div class="card p-3"><p class="eyebrow text-[10px] text-ink-3">{{ __('Deliveries') }}</p><p class="num text-xl font-medium">{{ $deliveries }}</p></div>
        <div class="card p-3"><p class="eyebrow text-[10px] text-ink-3">{{ __('Short') }}</p><p class="num text-xl font-medium {{ $summary['short_deliveries'] > 0 ? 'text-laterite' : '' }}">{{ $summary['short_deliveries'] }}</p></div>
        <div class="card p-3"><p class="eyebrow text-[10px] text-ink-3">{{ __('Rating') }}</p><p class="num text-xl font-medium">{{ $summary['rating'] }}/5</p><p class="hint">{{ $summary['rating_by_hand'] ? __('Set by you') : __('From deliveries') }}</p></div>
    </div>

    @can('material_control.suppliers')
        <form method="POST" action="{{ route('material_control.suppliers.update', $party) }}" class="card mb-5 max-w-xl space-y-3 p-4" x-data="{ marked: @js($summary['marked']) }">
            @csrf @method('PATCH')
            <h2 class="card-title">{{ __('Rate this supplier') }}</h2>
            <x-field :label="__('Rating')" name="rating" :hint="__('Leave empty to work it out from short deliveries.')">
                <select id="rating" name="rating" class="input min-h-12 text-base">
                    <option value="">{{ __('Automatic') }}</option>
                    @foreach ([5, 4, 3, 2, 1] as $stars)<option value="{{ $stars }}" @selected($summary['rating_by_hand'] && $summary['rating'] === $stars)>{{ $stars }}/5</option>@endforeach
                </select>
            </x-field>
            <label class="flex min-h-11 items-center gap-2 text-base font-semibold">
                <input type="hidden" name="marked" value="0">
                <input type="checkbox" name="marked" value="1" class="size-5" x-model="marked" @checked($summary['marked'])> {{ __('Mark this supplier with a warning') }}
            </label>
            <div x-show="marked" x-cloak>
                <x-field :label="__('Why?')" name="marked_reason">
                    <textarea id="marked_reason" name="marked_reason" rows="2" maxlength="500" class="input text-base" :disabled="!marked" :required="marked">{{ old('marked_reason', $summary['marked_reason']) }}</textarea>
                </x-field>
            </div>
            <button class="btn btn-primary min-h-12 w-full text-base sm:w-auto">{{ __('Save') }}</button>
        </form>
    @endcan

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Short deliveries') }}</h2></div>
        @forelse ($shorts as $short)
            <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $short->material->name }}</p>
                    <p class="truncate text-sm text-ink-2">{{ $short->project->name }} · <a href="{{ route('material_control.challans.show', $short->challan) }}" class="link">{{ $short->challan->number }}</a></p>
                    @if ($short->note)<p class="mt-0.5 text-sm text-ink-2">{{ $short->note }}</p>@endif
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium text-laterite">{{ fmt_qty($short->short_qty) }} {{ $short->unit->code }}</p>
                    <p class="num text-xs text-ink-3">{{ $short->found_on->format('d M Y') }}</p>
                </div>
            </div>
        @empty
            <x-empty :title="__('No short deliveries recorded')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $shorts->links() }}</div>
@endsection
