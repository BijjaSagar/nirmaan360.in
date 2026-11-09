@extends('layouts.app')
@section('title', __('Staff leave and claims'))
@section('content')
    @php $plain = \App\Support\Figure::trim(...); @endphp
    <x-page-header :title="__('Staff leave and claims')" :subtitle="__('Ask for leave or claim money you spent. Someone else decides.')" />

    <div class="mb-6 grid gap-4 lg:grid-cols-2">
        <form method="POST" action="{{ route('payroll.staff.leaves.store') }}" class="card grid gap-3 p-4 sm:grid-cols-2" x-data="{ half: {{ old('half_day') ? 'true' : 'false' }} }">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <p class="card-title sm:col-span-2">{{ __('Ask for leave') }}</p>
            <x-field :label="__('Kind of leave')" name="leave_type">
                <select id="leave_type" name="leave_type" class="input">
                    @foreach ($leaveTypes as $type)<option value="{{ $type }}" @selected(old('leave_type') === $type)>{{ __(ucfirst($type).' leave') }}</option>@endforeach
                </select>
            </x-field>
            <label class="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" name="half_day" value="1" x-model="half"> {{ __('Half day') }}</label>
            <x-field :label="__('From')" name="from_date"><input id="from_date" name="from_date" type="date" value="{{ old('from_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('To')" name="to_date"><input id="to_date" name="to_date" type="date" value="{{ old('to_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Reason')" name="reason" class="sm:col-span-2"><input id="reason" name="reason" value="{{ old('reason') }}" class="input" maxlength="190"></x-field>
            <div class="sm:col-span-2"><button class="btn btn-primary">{{ __('Send request') }}</button></div>
        </form>

        <form method="POST" action="{{ route('payroll.staff.claims.store') }}" class="card grid gap-3 p-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <p class="card-title sm:col-span-2">{{ __('Claim an expense') }}</p>
            <x-field :label="__('Date')" name="claim_date"><input id="claim_date" name="claim_date" type="date" value="{{ old('claim_date', now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" class="input" required></x-field>
            <x-field :label="__('Amount (₹)')" name="amount"><input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input num" required></x-field>
            <x-field :label="__('Category')" name="category">
                <select id="category" name="category" class="input">
                    @foreach ($categories as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ __($category) }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Project')" name="project_id">
                <select id="project_id" name="project_id" class="input">
                    <option value="">—</option>
                    @foreach ($projects as $choice)<option value="{{ $choice->id }}" @selected((int) old('project_id') === $choice->id)>{{ $choice->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('What was it for?')" name="description" class="sm:col-span-2"><input id="description" name="description" value="{{ old('description') }}" class="input" maxlength="190"></x-field>
            <div class="sm:col-span-2"><button class="btn btn-primary">{{ __('Submit claim') }}</button></div>
        </form>
    </div>

    @if ($canDecide)
        <div class="card mb-6 overflow-x-auto">
            <div class="card-head"><span class="card-title">{{ __('Waiting for a decision') }}</span></div>
            @if ($leavesToDecide->isEmpty() && $claimsToDecide->isEmpty())
                <x-empty :title="__('Nothing is waiting')" />
            @else
                <table class="ledger min-w-[820px]">
                    <thead><tr><th>{{ __('Who') }}</th><th>{{ __('Request') }}</th><th class="r">{{ __('Days or amount') }}</th><th>{{ __('Decision') }}</th></tr></thead>
                    <tbody>
                    @foreach ($leavesToDecide as $leave)
                        <tr>
                            <td class="font-semibold">{{ $leave->user?->name }}</td>
                            <td>{{ __(ucfirst($leave->leave_type).' leave') }}, {{ $leave->from_date->format('d M') }}@if (! $leave->from_date->equalTo($leave->to_date)) – {{ $leave->to_date->format('d M') }}@endif @if ($leave->reason)<span class="block text-xs text-ink-3">{{ $leave->reason }}</span>@endif</td>
                            <td class="r">{{ __(':days days', ['days' => $plain($leave->days)]) }}</td>
                            <td>
                                <form method="POST" action="{{ route('payroll.staff.leaves.decide', $leave) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf @method('PATCH')
                                    <input name="note" class="input w-44 py-1 text-xs" placeholder="{{ __('Note (needed to reject)') }}" maxlength="190" aria-label="{{ __('Note') }}">
                                    <button name="action" value="approve" class="btn btn-primary py-0.5 text-xs">{{ __('Approve') }}</button>
                                    <button name="action" value="reject" class="btn btn-danger py-0.5 text-xs">{{ __('Reject') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($claimsToDecide as $claim)
                        <tr>
                            <td class="font-semibold">{{ $claim->user?->name }}</td>
                            <td>{{ __($claim->category) }}, {{ $claim->claim_date->format('d M') }}@if ($claim->project) · {{ $claim->project->name }}@endif @if ($claim->description)<span class="block text-xs text-ink-3">{{ $claim->description }}</span>@endif</td>
                            <td class="r">{{ inr($claim->amount) }}</td>
                            <td>
                                @if ($claim->status === 'PENDING')
                                    <form method="POST" action="{{ route('payroll.staff.claims.decide', $claim) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf @method('PATCH')
                                        <input name="note" class="input w-44 py-1 text-xs" placeholder="{{ __('Note (needed to reject)') }}" maxlength="190" aria-label="{{ __('Note') }}">
                                        <button name="action" value="approve" class="btn btn-primary py-0.5 text-xs">{{ __('Approve') }}</button>
                                        <button name="action" value="reject" class="btn btn-danger py-0.5 text-xs">{{ __('Reject') }}</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('payroll.staff.claims.paid', $claim) }}" class="flex items-center gap-2">
                                        @csrf <x-stamp :status="$claim->status" /><button class="btn btn-ink py-0.5 text-xs">{{ __('Mark as paid') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card overflow-x-auto">
            <div class="card-head"><span class="card-title">{{ __('My leave') }}</span></div>
            @if ($myLeaves->isEmpty())
                <x-empty :title="__('No leave requests yet')" />
            @else
                <table class="ledger min-w-[420px]">
                    <thead><tr><th>{{ __('Dates') }}</th><th class="r">{{ __('Days') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($myLeaves as $leave)
                        <tr>
                            <td>{{ __(ucfirst($leave->leave_type).' leave') }}<span class="block text-xs text-ink-3">{{ $leave->from_date->format('d M') }}@if (! $leave->from_date->equalTo($leave->to_date)) – {{ $leave->to_date->format('d M Y') }}@else {{ $leave->from_date->format('Y') }}@endif @if ($leave->decision_note) · {{ $leave->decision_note }}@endif</span></td>
                            <td class="r">{{ $plain($leave->days) }}</td>
                            <td><x-stamp :status="$leave->status" /></td>
                            <td class="r">@if ($leave->status === 'PENDING')<form method="POST" action="{{ route('payroll.staff.leaves.cancel', $leave) }}">@csrf<button class="link text-xs">{{ __('Withdraw') }}</button></form>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card overflow-x-auto">
            <div class="card-head"><span class="card-title">{{ __('My claims') }}</span></div>
            @if ($myClaims->isEmpty())
                <x-empty :title="__('No claims yet')" />
            @else
                <table class="ledger min-w-[420px]">
                    <thead><tr><th>{{ __('Claim') }}</th><th class="r">{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($myClaims as $claim)
                        <tr>
                            <td>{{ __($claim->category) }}<span class="block text-xs text-ink-3">{{ $claim->claim_date->format('d M Y') }}@if ($claim->decision_note) · {{ $claim->decision_note }}@endif</span></td>
                            <td class="r">{{ inr($claim->amount) }}</td>
                            <td><x-stamp :status="$claim->status" /></td>
                            <td class="r">@if ($claim->status === 'PENDING')<form method="POST" action="{{ route('payroll.staff.claims.cancel', $claim) }}">@csrf @method('DELETE')<button class="link text-xs">{{ __('Withdraw') }}</button></form>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
