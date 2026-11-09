@extends('layouts.app')
@section('title', __('Measurement').' #'.$entry->id)
@section('content')
    <x-page-header :title="__('Measurement').' #'.$entry->id" :subtitle="$project->name">
        <x-stamp :status="$entry->status" />
        @if ($entry->deviation)<x-stamp status="deviation" />@endif
        @can('measurement.create')
            @if ($entry->isEditable())
                <a href="{{ route('measurements.edit', $entry) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('measurements.destroy', $entry) }}" onsubmit="return confirm(@js(__('Delete this draft?')))">@csrf @method('DELETE')<button class="btn btn-danger">{{ __('Delete') }}</button></form>
                <form method="POST" action="{{ route('measurements.submit', $entry) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>
            @endif
        @endcan
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card relative overflow-hidden">
                @if ($entry->status === 'APPROVED')
                    <span class="animate-stamp pointer-events-none absolute right-5 bottom-5 rounded-[3px] border-[3px] border-moss px-3 py-1 font-display text-2xl font-bold tracking-widest text-moss opacity-80">{{ __('APPROVED') }}</span>
                @endif
                <div class="border-b border-rule px-4 py-3">
                    <p class="num text-xs text-ink-3">{{ $entry->boqItem->code }}</p>
                    <p class="max-w-[75%] font-semibold">{{ $entry->boqItem->description }}</p>
                </div>
                <div class="p-4">
                    <p class="num text-lg">
                        {{ (float) $entry->nos }}
                        @foreach ([$entry->length, $entry->breadth, $entry->depth] as $dimension)
                            @if ($dimension !== null)<span class="text-ink-3">×</span> {{ $dimension }}@endif
                        @endforeach
                        <span class="text-ink-3">=</span>
                        <span class="font-medium {{ \App\Support\Dec::of($entry->qty)->isNegative() ? 'text-laterite' : '' }}">{{ fmt_qty($entry->qty) }} {{ $entry->boqItem->unit }}</span>
                    </p>
                    @if ($entry->is_deduction)<p class="mt-1 text-xs font-semibold text-laterite">{{ __('Deduction') }}</p>@endif
                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                        <div><dt class="eyebrow text-[10px] text-ink-3">{{ __('Measured on') }}</dt><dd class="num">{{ $entry->measured_on->format('d M Y') }}</dd></div>
                        <div><dt class="eyebrow text-[10px] text-ink-3">{{ __('Location') }}</dt><dd>{{ $entry->location ?? '—' }}</dd></div>
                        <div><dt class="eyebrow text-[10px] text-ink-3">{{ __('Entered by') }}</dt><dd>{{ $entry->creator?->name ?? '—' }}</dd></div>
                        @if ($entry->workOrder)<div><dt class="eyebrow text-[10px] text-ink-3">{{ __('Work order') }}</dt><dd><a href="{{ route('work-orders.show', $entry->workOrder) }}" class="link">{{ $entry->workOrder->number }}</a></dd></div>@endif
                        @if ($entry->description)<div class="col-span-2 sm:col-span-3"><dt class="eyebrow text-[10px] text-ink-3">{{ __('Description') }}</dt><dd>{{ $entry->description }}</dd></div>@endif
                        @if ($entry->remarks)<div class="col-span-2 sm:col-span-3"><dt class="eyebrow text-[10px] text-ink-3">{{ __('Remarks') }}</dt><dd>{{ $entry->remarks }}</dd></div>@endif
                    </dl>
                    @if ($entry->reversalOf)
                        <p class="mt-4 border-l-2 border-haldi pl-2 text-sm">{{ __('This entry reverses') }} <a href="{{ route('measurements.show', $entry->reversalOf) }}" class="link">#{{ $entry->reversalOf->id }}</a></p>
                    @endif
                    @if ($entry->reversal)
                        <p class="mt-4 border-l-2 border-haldi pl-2 text-sm">{{ __('Reversed by') }} <a href="{{ route('measurements.show', $entry->reversal) }}" class="link">#{{ $entry->reversal->id }}</a></p>
                    @endif
                </div>
            </section>

            @can('measurement.create')
                @if ($entry->status === 'APPROVED' && ! $entry->reversal && ! $entry->reversal_of_id)
                    <form method="POST" action="{{ route('measurements.reverse', $entry) }}" class="card p-4" x-data="{ open: false }">
                        @csrf
                        <p class="text-sm text-ink-2">{{ __('Approved entries are locked. To correct this one, create a reversal entry and record the right measurement.') }}</p>
                        <button type="button" class="btn btn-ghost mt-3" x-show="!open" @click="open = true">{{ __('Reverse this entry') }}</button>
                        <div x-show="open" x-cloak class="mt-3 space-y-2">
                            <x-field :label="__('Reason')" name="remarks"><textarea id="remarks" name="remarks" rows="2" class="input" required></textarea></x-field>
                            <button class="btn btn-danger">{{ __('Create reversal') }}</button>
                        </div>
                    </form>
                @endif
            @endcan
        </div>

        <x-approval-trail :approvals="$entry->approvals" />
    </div>
@endsection
