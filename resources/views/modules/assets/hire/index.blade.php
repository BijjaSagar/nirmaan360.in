@extends('layouts.app')
@section('title', __('Hire bills'))
@section('content')
    <x-page-header :title="__('Hire bills')" :subtitle="__('What you owe the owners of hired machines')">
        <a href="{{ route('hire-bills.create') }}" class="btn btn-primary">{{ __('New hire bill') }}</a>
    </x-page-header>

    <div class="mb-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat :label="__('Final, not yet paid')" :value="inr($owed)" :accent="$owed !== '0.00'" />
    </div>

    <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
        <a href="{{ route('hire-bills.index') }}" class="btn {{ $status ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
        @foreach (['DRAFT', 'FINAL', 'PAID', 'CANCELLED'] as $value)
            <a href="{{ route('hire-bills.index', ['status' => $value]) }}" class="btn {{ $status === $value ? 'btn-ink' : 'btn-ghost' }}">{{ __(\App\Services\FieldOps\Tone::chip('bill', $value)[0]) }}</a>
        @endforeach
    </div>

    <div class="card overflow-x-auto">
        @if ($bills->isEmpty())
            <x-empty :title="__('No hire bills yet')" :note="__('Log the days a hired machine worked, then work out its bill here.')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Bill') }}</th><th>{{ __('Machine') }}</th><th>{{ __('Owner of the machine') }}</th><th>{{ __('Period') }}</th><th class="r">{{ __('Net payable') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($bills as $bill)
                    <tr>
                        <td><a href="{{ route('hire-bills.show', $bill) }}" class="link num">{{ $bill->number }}</a></td>
                        <td>{{ $bill->asset->code }} <span class="text-ink-3">{{ $bill->asset->name }}</span></td>
                        <td>{{ $bill->vendor?->name ?? '—' }}</td>
                        <td class="num text-xs">{{ $bill->period_from->format('d M Y') }} – {{ $bill->period_to->format('d M Y') }}</td>
                        <td class="r">{{ inr($bill->net_payable) }}</td>
                        <td>@include('modules.field_ops._chip', ['set' => 'bill', 'value' => $bill->status])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $bills->links() }}</div>
@endsection
