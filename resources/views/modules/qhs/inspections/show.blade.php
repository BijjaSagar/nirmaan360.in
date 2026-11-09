@extends('layouts.app')
@section('title', $inspection->title)
@php
    $open = $inspection->isOpen() && auth()->user()->can('checklist.fill');
    $results = config('modules.qhs.inspection_results');
@endphp
@section('content')
    <x-page-header :title="$inspection->title" :subtitle="$project->name.($inspection->site ? ' · '.$inspection->site->name : '').($inspection->location ? ' · '.$inspection->location : '').' · '.$inspection->inspected_on->format('d M Y')">
        @include('modules.field_ops._chip', ['set' => 'inspection', 'value' => $inspection->status])
        <a href="{{ route('inspections.index', $project) }}" class="btn btn-ghost">{{ __('All inspections') }}</a>
    </x-page-header>

    <form method="POST" action="{{ route('inspections.update', $inspection) }}" class="card max-w-4xl">
        @csrf @method('PATCH')
        <div class="card-head">
            <h2 class="card-title">{{ __('Check points') }}</h2>
            <span class="text-sm text-ink-3">{{ __('Inspector') }}: {{ $inspection->inspector?->name ?? '—' }}</span>
        </div>
        @foreach ($inspection->items as $index => $item)
            <div class="border-b border-rule px-4 py-3 last:border-b-0" x-data="{ result: @js(old("items.$index.result", $item->result)) }">
                <input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                    <p class="min-w-0 flex-1 text-sm"><span class="num mr-1 text-ink-3">{{ $item->sort }}.</span>{{ $item->text }}</p>
                    @if ($open)
                        <div class="flex gap-1.5" role="radiogroup" aria-label="{{ $item->text }}">
                            @foreach (['pass', 'fail', 'na'] as $value)
                                <label class="flex-1 cursor-pointer sm:flex-none">
                                    <input type="radio" class="peer sr-only" name="items[{{ $index }}][result]" value="{{ $value }}" x-model="result">
                                    <span class="btn btn-ghost w-full px-3 py-2 text-sm sm:py-1 sm:text-xs {{ $value === 'pass' ? 'peer-checked:border-moss peer-checked:bg-moss peer-checked:text-white' : ($value === 'fail' ? 'peer-checked:border-laterite peer-checked:bg-laterite peer-checked:text-white' : 'peer-checked:border-ink-3 peer-checked:bg-ink-3 peer-checked:text-white') }} peer-focus-visible:outline-2">{{ __($results[$value]) }}</span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        @include('modules.field_ops._chip', ['set' => 'result', 'value' => $item->result])
                    @endif
                </div>
                @if ($open)
                    <input name="items[{{ $index }}][remark]" value="{{ old("items.$index.remark", $item->remark) }}" class="input mt-2 py-1 text-sm" maxlength="255" placeholder="{{ __('Remark') }}" aria-label="{{ __('Remark') }}" :required="result === 'fail'">
                    @error("items.$index.remark")<p class="error">{{ $message }}</p>@enderror
                @elseif ($item->remark)
                    <p class="mt-1 border-l-2 border-haldi pl-2 text-sm text-ink-2">{{ $item->remark }}</p>
                @endif
            </div>
        @endforeach
        @if ($open)
            <div class="flex flex-wrap gap-2 border-t border-rule p-4">
                <button class="btn btn-ghost">{{ __('Save') }}</button>
                <button name="complete" value="1" class="btn btn-primary">{{ __('Complete inspection') }}</button>
            </div>
        @elseif (! $inspection->isOpen())
            <p class="border-t border-rule px-4 py-3 text-sm text-ink-3">{{ __('Completed on :date. A completed inspection cannot be changed.', ['date' => $inspection->completed_at->format('d M Y')]) }}</p>
        @endif
    </form>
@endsection
