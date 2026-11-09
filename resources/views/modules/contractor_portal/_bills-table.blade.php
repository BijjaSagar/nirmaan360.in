{{-- Approved and paid bills, as RaBill models. --}}
@if ($bills->isEmpty())
    <x-empty :title="__('No bills yet')" :note="__('A bill shows here once :company has approved it.', ['company' => auth()->user()->organization->name])" />
@else
    <div class="overflow-x-auto">
        <table class="ledger min-w-[640px]">
            <thead><tr><th>{{ __('Bill') }}</th><th>{{ __('Work order') }}</th><th>{{ __('Period') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('Net payable') }}</th><th class="r">{{ __('Received') }}</th></tr></thead>
            <tbody>
            @foreach ($bills as $bill)
                <tr>
                    <td><a href="{{ route('contractor-portal.bills.show', $bill) }}" class="link num text-xs">{{ $bill->number }}</a>@if ($bill->is_final) <span class="stamp border-ink-3 text-ink-3">{{ __('Final') }}</span>@endif</td>
                    <td class="text-xs"><span class="num">{{ $bill->workOrder?->number }}</span><br><span class="text-ink-3">{{ $bill->project?->name }}</span></td>
                    <td class="num text-xs whitespace-nowrap">{{ $bill->period_from->format('d M Y') }} – {{ $bill->period_to->format('d M Y') }}</td>
                    <td><x-stamp :status="$bill->status" /></td>
                    <td class="r">{{ inr($bill->net_payable) }}</td>
                    <td class="r">{{ $bill->status === 'PAID' ? inr($bill->paid_amount ?? $bill->net_payable) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
