@extends('layouts.app')
@section('title', $run->number)
@section('content')
    @php $plain = \App\Support\Figure::trim(...); @endphp

    <x-page-header :title="__('Wage run')" :subtitle="$run->number.' · '.$run->period_from->format('d M').' – '.$run->period_to->format('d M Y')">
        <x-stamp :status="$run->status" />
        <a href="{{ route('payroll.runs.index') }}" class="btn btn-ghost">{{ __('All runs') }}</a>
        @if ($run->isLocked())
            @can('payroll.view')
                <a href="{{ route('payroll.payslips.all', $run) }}" class="btn btn-ink">{{ __('Download all payslips') }}</a>
            @endcan
        @endif
    </x-page-header>

    @if ($stale)
        <p class="mb-4 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" role="alert">{{ __('Attendance changed after this run was calculated. Recalculate it before approving.') }}</p>
    @endif

    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="__('Gross wages')" :value="inr($run->total_gross)" :note="__(':days payable days', ['days' => $plain($run->total_days)])" />
        <x-stat :label="__('Advances taken back')" :value="inr($run->total_recovery)" />
        <x-stat :label="__('Net pay')" :value="inr($run->total_net)" :accent="true" />
        <x-stat :label="__('Workers')" :value="$run->lines->count()" :note="$run->project?->name ?? __('All projects')" />
    </div>

    <p class="mb-4 text-sm text-ink-3">
        {{ __('Overtime pays :times times the hourly rate. The hourly rate is the daily wage divided by :hours hours.', ['times' => $plain($run->terms['ot_multiplier'] ?? 2), 'hours' => $plain($run->terms['shift_hours'] ?? 8)]) }}
        {{ __('Prepared by :name', ['name' => $run->preparer?->name ?? '—']) }}@if ($run->approver) · {{ __('Approved by :name on :date', ['name' => $run->approver->name, 'date' => $run->approved_at->format('d M Y')]) }}@endif
        @if ($run->paid_at) · {{ __('Paid on :date', ['date' => $run->paid_at->format('d M Y')]) }}@if ($run->payment_note) ({{ $run->payment_note }})@endif @endif
    </p>

    <div class="card mb-5 overflow-x-auto">
        <table class="ledger min-w-[960px]">
            <thead>
            <tr>
                <th>{{ __('Worker') }}</th><th class="r">{{ __('Daily wage') }}</th><th class="r">{{ __('Present') }}</th><th class="r">{{ __('Half') }}</th>
                <th class="r">{{ __('Overtime hours') }}</th><th class="r">{{ __('Payable days') }}</th><th class="r">{{ __('Day pay') }}</th><th class="r">{{ __('Overtime pay') }}</th>
                <th class="r">{{ __('Gross') }}</th><th class="r">{{ __('Advance') }}</th><th class="r">{{ __('Net pay') }}</th><th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($run->lines as $line)
                <tr>
                    <td class="font-semibold">{{ $line->worker_name }}<span class="block text-xs font-normal text-ink-3">{{ __($line->trade) }}</span></td>
                    <td class="r">{{ inr($line->daily_wage) }}</td>
                    <td class="r">{{ $plain($line->present_days) }}</td>
                    <td class="r">{{ $plain($line->half_days) }}</td>
                    <td class="r">{{ $plain($line->ot_hours) }}</td>
                    <td class="r">{{ $plain($line->payable_days) }}</td>
                    <td class="r">{{ inr($line->day_pay) }}</td>
                    <td class="r">{{ inr($line->ot_pay) }}</td>
                    <td class="r">{{ inr($line->gross) }}</td>
                    <td class="r">{{ (float) $line->advance_recovery > 0 ? '−'.inr($line->advance_recovery) : '—' }}</td>
                    <td class="r font-semibold">{{ inr($line->net) }}</td>
                    <td class="r">@if ($run->isLocked())<a href="{{ route('payroll.payslips.show', [$run, $line]) }}" class="link text-xs">{{ __('Payslip') }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="font-semibold">
                <td colspan="5">{{ __('Total') }}</td><td class="r">{{ $plain($run->total_days) }}</td><td></td><td></td>
                <td class="r">{{ inr($run->total_gross) }}</td><td class="r">−{{ inr($run->total_recovery) }}</td><td class="r">{{ inr($run->total_net) }}</td><td></td>
            </tr>
            </tfoot>
        </table>
    </div>

    @if ($run->notes)<p class="mb-5 text-sm"><span class="font-semibold">{{ __('Notes') }}:</span> {{ $run->notes }}</p>@endif

    <div class="flex flex-wrap items-start gap-3">
        @if ($run->status === 'DRAFT')
            @can('payroll.manage')
                <form method="POST" action="{{ route('payroll.runs.recalculate', $run) }}">@csrf<button class="btn btn-ghost">{{ __('Recalculate') }}</button></form>
                <form method="POST" action="{{ route('payroll.runs.destroy', $run) }}" onsubmit="return confirm(@js(__('Remove this draft wage run?')))">@csrf @method('DELETE')<button class="btn btn-danger">{{ __('Remove draft') }}</button></form>
            @endcan
            @can('payroll.approve')
                @if ($canApprove)
                    <form method="POST" action="{{ route('payroll.runs.approve', $run) }}" onsubmit="return confirm(@js(__('Approve and lock this wage run? It cannot be changed afterwards.')))">@csrf<button class="btn btn-primary">{{ __('Approve and lock') }}</button></form>
                @else
                    <p class="text-sm text-ink-3">{{ __('A different person must approve this wage run.') }}</p>
                @endif
            @endcan
        @elseif ($run->status === 'APPROVED')
            @can('payroll.approve')
                <form method="POST" action="{{ route('payroll.runs.paid', $run) }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    <x-field :label="__('How it was paid')" name="payment_note"><input id="payment_note" name="payment_note" class="input" maxlength="190" placeholder="{{ __('Bank transfer, cash …') }}"></x-field>
                    <button class="btn btn-primary">{{ __('Mark as paid') }}</button>
                </form>
            @endcan
        @endif
    </div>
@endsection
