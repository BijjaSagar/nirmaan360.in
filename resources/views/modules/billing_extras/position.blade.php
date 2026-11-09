@extends('layouts.app')
@section('title', __('Contract position'))
@section('content')
    <x-page-header :title="__('Contract position')" :subtitle="$project->name" />

    <section class="card overflow-x-auto">
        <table class="ledger min-w-[1080px]">
            <thead>
            <tr>
                <th>{{ __('Contract') }}</th><th>{{ __('Party') }}</th>
                <th class="r">{{ __('Billed') }}</th><th class="r">{{ __('Notes') }}</th><th class="r">{{ __('Adjusted') }}</th>
                <th class="r">{{ __('Paid') }}</th><th class="r">{{ __('Outstanding') }}</th>
                <th class="r">{{ __('Retention held') }}</th><th class="r">{{ __('Advance left') }}</th><th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($contracts as $contract)
                <tr>
                    <td>
                        @if ($contract['work_order'])
                            <a href="{{ route('work-orders.show', $contract['work_order']) }}" class="link num text-xs">{{ $contract['work_order']->number }}</a>
                        @else
                            {{ __('Client contract') }}
                        @endif
                    </td>
                    <td>{{ $contract['party']?->name ?? '—' }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($contract['billed']) }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($contract['notes']) }}</td>
                    <td class="r font-medium">{{ \App\Support\Dec::indian($contract['adjusted']) }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($contract['paid']) }}</td>
                    <td class="r font-medium">{{ \App\Support\Dec::indian($contract['outstanding']) }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($contract['retention_held']) }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($contract['advance_outstanding']) }}</td>
                    <td class="r whitespace-nowrap">
                        @if ($contract['closed'])
                            <a href="{{ route('ra-bills.show', $contract['final_bill']) }}"><x-stamp status="closed" /></a>
                        @elseif ($contract['final_bill'])
                            <a href="{{ route('ra-bills.show', $contract['final_bill']) }}" class="link num text-xs">{{ $contract['final_bill']->number }}</a>
                        @else
                            @can('ra_bill.generate')
                                <a href="{{ route('billing-extras.final-bills.create', [$project, ...($contract['work_order'] ? ['work_order_id' => $contract['work_order']->id] : [])]) }}" class="btn btn-ghost py-1 text-xs">{{ __('Prepare final bill') }}</a>
                            @endcan
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
    <p class="mt-2 text-xs text-ink-3">{{ __('Only approved bills and approved notes count. For the client, outstanding is what they still owe us. For a subcontractor, it is what we still owe them.') }}</p>

    @can('bill_note.view')
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Credit and debit notes') }}</h2></div>
            @forelse ($notes as $note)
                <a href="{{ route('bill-notes.show', $note) }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="num text-xs">{{ $note->number }}</span>
                    <span>{{ __($note->label()) }} · {{ $note->party?->name ?? '—' }}</span>
                    <span class="num text-xs text-ink-3">{{ $note->bill->number }}</span>
                    <span class="num ml-auto whitespace-nowrap {{ $note->lowersPosition() ? 'text-laterite' : '' }}">{{ $note->lowersPosition() ? '−' : '+' }}{{ inr($note->total_amount) }}</span>
                    <x-stamp :status="$note->status" />
                </a>
            @empty
                <x-empty :title="__('No notes yet')" :note="__('Raise a credit or debit note from an approved RA bill.')" />
            @endforelse
        </section>
    @endcan
@endsection
