@extends('layouts.app')
@section('title', __('Contractor payments'))
@section('content')
    <x-page-header :title="__('Contractor payments')" :subtitle="$project->name">
        <a href="{{ route('contractor-control.board', $project) }}" class="btn btn-ghost">{{ __('Work vs payment') }}</a>
    </x-page-header>

    <div class="mb-3 flex flex-wrap gap-1.5">
        <a href="{{ route('contractor-control.payments.index', $project) }}" class="btn {{ $status ? 'btn-ghost' : 'btn-ink' }} py-1 text-xs">{{ __('All') }}</a>
        @foreach ($statuses as $option)
            <a href="{{ route('contractor-control.payments.index', [$project, 'status' => $option]) }}" class="btn {{ $status === $option ? 'btn-ink' : 'btn-ghost' }} py-1 text-xs">{{ __(\Illuminate\Support\Str::headline(strtolower($option))) }}</a>
        @endforeach
    </div>

    <section class="card">
        @forelse ($payments as $payment)
            @if ($money)
                <div>@include('modules.contractor_control.payments._row', ['payment' => $payment])</div>
            @else
                <a href="{{ route('contractor-control.payments.show', $payment) }}" class="block border-b border-rule px-4 py-2.5 last:border-b-0 hover:bg-paper/70">
                    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <span class="text-sm"><span class="num text-xs text-ink-3">{{ $payment->number }}</span> {{ $payment->paid_on->format('d M Y') }}</span>
                        <x-stamp :status="$payment->status" />
                    </div>
                    <p class="mt-0.5 text-xs text-ink-3">{{ $payment->party?->name }}</p>
                </a>
            @endif
        @empty
            <x-empty :title="__('No payments yet')" :note="__('Open a contract on the Work vs payment page to request a payment.')" />
        @endforelse
    </section>

    <div class="mt-3">{{ $payments->links() }}</div>
@endsection
