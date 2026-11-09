@extends('layouts.app')
@section('title', __('Petty cash'))
@section('content')
    <x-page-header :title="__('Petty cash')" :subtitle="$project->name" />

    <div class="space-y-5">
        @forelse ($wallets as $wallet)
            <section class="card" x-data="{ type: 'expense' }">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">{{ $wallet->name }}</h2>
                        @if ($wallet->holder)<p class="text-xs text-ink-3">{{ $wallet->holder->name }}</p>@endif
                    </div>
                    <div class="text-right">
                        <p class="eyebrow text-[10px] text-ink-3">{{ __('Balance') }}</p>
                        <p class="num text-lg font-medium">{{ inr($wallet->balance()) }}</p>
                    </div>
                </div>

                @can('petty_cash.manage')
                    <form method="POST" action="{{ route('petty.entries.store', $wallet) }}" enctype="multipart/form-data" class="grid items-end gap-3 border-b border-rule p-4 sm:grid-cols-6">
                        @csrf
                        <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div>
                            <label class="label">{{ __('Type') }}</label>
                            <select name="type" x-model="type" class="input">
                                <option value="expense">{{ __('Expense') }}</option>
                                <option value="topup">{{ __('Top-up') }}</option>
                            </select>
                        </div>
                        <div><label class="label">{{ __('Amount (₹)') }}</label><input name="amount" type="number" step="0.01" min="0.01" class="input num" required></div>
                        <div><label class="label">{{ __('Date') }}</label><input name="entry_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" class="input" required></div>
                        <div x-show="type === 'expense'">
                            <label class="label">{{ __('Category') }}</label>
                            <select name="category" class="input" :disabled="type !== 'expense'">
                                @foreach (config('buildos.expense_categories') as $category)<option value="{{ $category }}">{{ __($category) }}</option>@endforeach
                            </select>
                        </div>
                        <div><label class="label">{{ __('Note') }}</label><input name="description" class="input"></div>
                        <div><label class="label">{{ __('Bill photo') }}</label><input name="bill" type="file" accept="image/*,.pdf" class="input"></div>
                        <div class="sm:col-span-6"><button class="btn btn-primary">{{ __('Save entry') }}</button></div>
                    </form>
                @endcan

                @if ($wallet->entries->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="ledger">
                            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Category') }}</th><th>{{ __('Note') }}</th><th class="r">{{ __('Amount') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($wallet->entries->take(15) as $entry)
                                <tr>
                                    <td class="num text-xs">{{ $entry->entry_date->format('d M Y') }}</td>
                                    <td>{{ $entry->type === 'topup' ? __('Top-up') : __('Expense') }}</td>
                                    <td>{{ $entry->category ? __($entry->category) : '—' }}</td>
                                    <td>{{ $entry->description ?? '—' }}</td>
                                    <td class="r {{ $entry->type === 'topup' ? 'text-moss' : '' }}">{{ $entry->type === 'topup' ? '+' : '−' }}{{ inr($entry->amount) }}</td>
                                    <td class="text-right">@if ($entry->bill_path)<a href="{{ route('petty.bill', $entry) }}" target="_blank" rel="noopener" class="link text-xs">{{ __('Bill') }}</a>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @empty
            <div class="card"><x-empty :title="__('No wallets yet')" :note="__('Create a wallet for each person who handles site cash.')" /></div>
        @endforelse

        @can('petty_cash.manage')
            <form method="POST" action="{{ route('petty.wallets.store', $project) }}" class="card flex flex-wrap items-end gap-3 p-4">
                @csrf
                <x-field :label="__('New wallet name')" name="name" class="min-w-48 flex-1"><input id="name" name="name" class="input" required></x-field>
                <x-field :label="__('Held by')" name="user_id" class="min-w-48 flex-1">
                    <select id="user_id" name="user_id" class="input">
                        <option value="">—</option>
                        @foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                    </select>
                </x-field>
                <button class="btn btn-ghost">{{ __('Create wallet') }}</button>
            </form>
        @endcan
    </div>
@endsection
