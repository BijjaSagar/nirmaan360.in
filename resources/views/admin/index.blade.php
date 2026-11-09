@extends('layouts.app')
@section('title', __('Platform admin'))
@section('content')
    <x-page-header :title="__('Platform admin')" :subtitle="__('Every company on Nirmaan360')" />
    @include('admin._tabs')

    <section class="card overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Companies') }}</h2><span class="num text-sm">{{ $organizations->total() }}</span></div>
        <table class="ledger min-w-[980px]">
            <thead><tr><th>{{ __('Company') }}</th><th class="r">{{ __('Users') }}</th><th>{{ __('Joined') }}</th><th>{{ __('Effective plan') }}</th><th>{{ __('Change plan') }}</th></tr></thead>
            <tbody>
            @foreach ($organizations as $organization)
                <tr>
                    <td class="font-semibold">{{ $organization->name }}</td>
                    <td class="r">{{ $organization->users_count }}</td>
                    <td class="num text-xs">{{ $organization->created_at->format('d M Y') }}</td>
                    <td>{{ __($plans[$organization->effectivePlan()]['label']) }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.organizations.update', $organization) }}" class="flex flex-wrap items-center gap-1.5">
                            @csrf @method('PATCH')
                            <select name="plan" class="input w-32 py-1 text-xs" aria-label="{{ __('Plan') }}">@foreach ($plans as $key => $plan)<option value="{{ $key }}" @selected($organization->plan === $key)>{{ $plan['label'] }}</option>@endforeach</select>
                            <select name="plan_status" class="input w-20 py-1 text-xs" aria-label="{{ __('Status') }}"><option value="active" @selected($organization->plan_status === 'active')>{{ __('Active') }}</option><option value="trial" @selected($organization->plan_status === 'trial')>{{ __('Trial') }}</option></select>
                            <input type="date" name="trial_ends_at" value="{{ $organization->trial_ends_at?->format('Y-m-d') }}" class="input w-32 py-1 text-xs" aria-label="{{ __('Trial ends') }}" title="{{ __('Trial ends') }}">
                            <input type="date" name="paid_until" value="{{ $organization->paid_until?->format('Y-m-d') }}" class="input w-32 py-1 text-xs" aria-label="{{ __('Paid until') }}" title="{{ __('Paid until') }}">
                            <button class="btn btn-ghost py-0.5 text-xs">{{ __('Save') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
    <div class="mt-4">{{ $organizations->links() }}</div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="card overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Enquiries') }}</h2></div>
            @forelse ($requests as $request)
                <div class="border-b border-rule px-4 py-3 text-sm last:border-b-0">
                    <p class="font-semibold">{{ $request->name }} @if ($request->company)<span class="font-normal text-ink-3">· {{ $request->company }}</span>@endif</p>
                    <p class="text-xs text-ink-3">{{ str_replace('_', ' ', $request->type) }} · {{ $request->email }} @if ($request->phone)· {{ $request->phone }}@endif · {{ $request->created_at->format('d M Y H:i') }}</p>
                    @if ($request->message)<p class="mt-1 whitespace-pre-line">{{ $request->message }}</p>@endif
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No enquiries yet.') }}</p>
            @endforelse
        </section>

        <section class="card overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Recent payments') }}</h2></div>
            @forelse ($payments as $payment)
                <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                    <span class="min-w-0 truncate">{{ $payment->organization?->name }} <span class="text-ink-3">· {{ $payment->plan }} · {{ $payment->cycle }}</span></span>
                    <span class="num shrink-0">{{ inr($payment->total_amount) }}</span>
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No payments yet.') }}</p>
            @endforelse
        </section>
    </div>
@endsection
