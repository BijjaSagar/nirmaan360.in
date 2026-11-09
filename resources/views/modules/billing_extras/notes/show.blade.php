@extends('layouts.app')
@section('title', $note->number)
@section('content')
    <x-page-header :title="__($note->label()).' '.$note->number" :subtitle="$project->name.' · '.($note->party?->name ?? '—')">
        <x-stamp :status="$note->status" />
        <a href="{{ route('bill-notes.pdf', $note) }}" class="btn btn-ghost">{{ __('Download PDF') }}</a>
        @can('bill_note.manage')
            @if ($note->isCancellable())
                <form method="POST" action="{{ route('bill-notes.cancel', $note) }}" onsubmit="return confirm(@js(__('Cancel this note?')))">@csrf<button class="btn btn-danger">{{ __('Cancel note') }}</button></form>
            @endif
            @if ($note->isEditable())
                <form method="POST" action="{{ route('bill-notes.submit', $note) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>
            @endif
        @endcan
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="card lg:col-span-2">
            <dl class="text-sm">
                <div class="flex justify-between gap-3 border-b border-rule px-4 py-2"><dt>{{ __('Against bill') }}</dt><dd><a href="{{ route('ra-bills.show', $note->bill) }}" class="link num">{{ $note->bill->number }}</a></dd></div>
                <div class="flex justify-between gap-3 border-b border-rule px-4 py-2"><dt>{{ __('Note date') }}</dt><dd class="num">{{ $note->note_date->format('d M Y') }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-rule px-4 py-2"><dt>{{ __('Raised by') }}</dt><dd>{{ $note->creator?->name ?? '—' }}</dd></div>
                <div class="border-b border-rule px-4 py-2"><dt>{{ __('Reason') }}</dt><dd class="mt-1 text-ink-2">{{ $note->reason }}</dd></div>
                @can('money.view')
                    <div class="flex justify-between gap-3 border-b border-rule px-4 py-2"><dt>{{ __('Amount before GST') }}</dt><dd class="num">{{ inr($note->taxable_amount) }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-rule px-4 py-2"><dt>{{ __('GST') }} <span class="num text-xs text-ink-3">{{ (float) $note->gst_pct }}%</span></dt><dd class="num">{{ inr($note->gst_amount) }}</dd></div>
                    <div class="flex items-baseline justify-between gap-3 bg-paper-2/60 px-4 py-3"><dt class="font-display text-lg font-semibold">{{ __('Note total') }}</dt><dd class="num text-lg font-medium whitespace-nowrap">{{ inr($note->total_amount) }}</dd></div>
                @endcan
            </dl>
            <p class="border-t border-rule px-4 py-3 text-sm {{ $note->lowersPosition() ? 'text-laterite' : '' }}">
                @can('money.view')
                    @if ($note->bill->type === 'client')
                        {{ $note->lowersPosition() ? __('Lowers what the client owes us by :amount once approved.', ['amount' => inr($note->total_amount)]) : __('Raises what the client owes us by :amount once approved.', ['amount' => inr($note->total_amount)]) }}
                    @else
                        {{ $note->lowersPosition() ? __('Lowers what we owe the subcontractor by :amount once approved.', ['amount' => inr($note->total_amount)]) : __('Raises what we owe the subcontractor by :amount once approved.', ['amount' => inr($note->total_amount)]) }}
                    @endif
                @else
                    {{ $note->lowersPosition() ? __('Lowers the bill once approved.') : __('Raises the bill once approved.') }}
                @endcan
            </p>
        </section>

        <x-approval-trail :approvals="$note->approvals" />
    </div>
@endsection
