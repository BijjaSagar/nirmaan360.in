@extends('layouts.app')
@section('title', $issue->number)
@section('content')
    <x-page-header :title="$issue->number" :subtitle="$project->name">
        <x-stamp :status="$issue->status" />
        @can('procurement.stock')
            @unless ($issue->isReversed())
                <a href="{{ route('procurement.returns.create-from-issue', $issue) }}" class="btn btn-ghost">{{ __('Return to store') }}</a>
            @endunless
        @endcan
    </x-page-header>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="ledger min-w-[560px]">
                <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Out of stock') }}</th><th>{{ __('BOQ item') }}</th><th>{{ __('Activity') }}</th></tr></thead>
                <tbody>
                @foreach ($issue->items as $line)
                    <tr>
                        <td>{{ $line->material->name }}</td>
                        <td class="r">{{ fmt_qty($line->qty) }} {{ $line->unit->code }}</td>
                        <td class="r">{{ fmt_qty($line->base_qty) }} {{ $line->material->baseUnit->code }}</td>
                        <td>{{ $line->boqItem?->code ?? '—' }}</td>
                        <td>{{ $line->activity ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <dl class="grid gap-2 border-t border-rule p-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-3">{{ __('Date') }}</dt><dd>{{ $issue->issue_date->format('d M Y') }}</dd></div>
            <div><dt class="text-ink-3">{{ __('From store') }}</dt><dd>{{ $issue->store->name }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Issued to') }}</dt><dd>{{ $issue->issued_to ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Posted by') }}</dt><dd>{{ $issue->creator?->name ?? '—' }}</dd></div>
            @if ($issue->isReversed())<div><dt class="text-ink-3">{{ __('Reversal reason') }}</dt><dd>{{ $issue->reversal_reason }}</dd></div>@endif
        </dl>
    </section>

    @unless ($issue->isReversed())
        @include('modules.procurement.partials.reverse', ['action' => route('procurement.issues.reverse', $issue), 'hint' => __('Puts this issue back into stock and takes it off the BOQ item.')])
    @endunless
@endsection
