@extends('layouts.app')
@section('title', __('Advances'))
@section('content')
    <x-page-header :title="__('Advances')" :subtitle="__('Money given to workers ahead of wages. Each wage run takes back an instalment.')" />

    @can('payroll.manage')
        <form method="POST" action="{{ route('payroll.advances.store') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-6">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <x-field :label="__('Worker')" name="worker_id" class="sm:col-span-2">
                <select id="worker_id" name="worker_id" class="input" required>
                    <option value="">—</option>
                    @foreach ($workers as $worker)<option value="{{ $worker->id }}" @selected((int) old('worker_id') === $worker->id)>{{ $worker->name }} · {{ __($worker->trade) }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Amount (₹)')" name="amount"><input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input num" required></x-field>
            <x-field :label="__('Given on')" name="given_on"><input id="given_on" name="given_on" type="date" value="{{ old('given_on', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" class="input" required></x-field>
            <x-field :label="__('Take back per wage run (₹)')" name="recovery_per_run" :hint="__('Leave empty to take back as much as the wages allow.')"><input id="recovery_per_run" name="recovery_per_run" type="number" step="0.01" min="0" value="{{ old('recovery_per_run') }}" class="input num"></x-field>
            <x-field :label="__('Reason')" name="reason"><input id="reason" name="reason" value="{{ old('reason') }}" class="input" maxlength="190"></x-field>
            <div class="sm:col-span-6"><button class="btn btn-primary">{{ __('Record advance') }}</button></div>
        </form>
    @endcan

    <div class="card overflow-x-auto">
        @if ($advances->isEmpty())
            <x-empty :title="__('No advances yet')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Worker') }}</th><th>{{ __('Reason') }}</th><th class="r">{{ __('Amount') }}</th><th class="r">{{ __('Per run') }}</th><th class="r">{{ __('Taken back') }}</th><th class="r">{{ __('Still to take back') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($advances as $advance)
                    <tr>
                        <td class="num text-xs">{{ $advance->given_on->format('d M Y') }}</td>
                        <td class="font-semibold">{{ $advance->worker?->name }}</td>
                        <td>{{ $advance->reason ?? '—' }}</td>
                        <td class="r">{{ inr($advance->amount) }}</td>
                        <td class="r">{{ (float) $advance->recovery_per_run > 0 ? inr($advance->recovery_per_run) : __('As wages allow') }}</td>
                        <td class="r">{{ inr($advance->recovered()) }}</td>
                        <td class="r font-semibold">{{ inr($advance->outstanding()) }}</td>
                        <td class="r">
                            @can('payroll.manage')
                                @if ((float) $advance->recovered() === 0.0)
                                    <form method="POST" action="{{ route('payroll.advances.destroy', $advance) }}" onsubmit="return confirm(@js(__('Remove this advance?')))">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $advances->links() }}</div>
@endsection
