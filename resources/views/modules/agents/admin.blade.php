@extends('layouts.app')
@section('title', __('AI agents'))
@section('content')
    <x-page-header :title="__('AI agents')" :subtitle="__('Connect the AI service and see what each company uses')">
        <x-stamp :status="$connected ? 'active' : 'draft'" />
    </x-page-header>
    @include('admin._tabs')
    @include('modules.agents._connection')

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('agents.admin.update') }}" class="card" autocomplete="off">
            @csrf @method('PATCH')
            <div class="card-head"><h2 class="card-title">{{ __('Anthropic') }}</h2><span class="text-sm {{ $connected ? 'text-moss' : 'text-ink-3' }}">{{ $connected ? __('Connected') : __('Not connected') }}</span></div>
            <div class="space-y-3 p-4">
                <x-field :label="__('API key')" name="anthropic_api_key" :hint="$connected ? __('Saved. Leave blank to keep it.') : __('Not saved yet.')"><input id="anthropic_api_key" name="anthropic_api_key" type="password" class="input num" autocomplete="new-password"></x-field>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="clear_key" value="1"> {{ __('Remove the saved key') }}</label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-field :label="__('Rupees per US dollar')" name="ai_usd_inr"><input id="ai_usd_inr" name="ai_usd_inr" type="number" step="0.01" min="0.01" value="{{ old('ai_usd_inr', $usdInr) }}" class="input num" required></x-field>
                    <x-field :label="__('Margin multiplier')" name="ai_margin" :hint="__('1.30 charges 30% over cost.')"><input id="ai_margin" name="ai_margin" type="number" step="0.01" min="0.01" value="{{ old('ai_margin', $margin) }}" class="input num" required></x-field>
                </div>
            </div>
            <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>

        <section class="card self-start p-4">
            <h2 class="card-title">{{ __('Check your key') }}</h2>
            <p class="mt-1 text-sm text-ink-3">{{ __('Asks the AI service whether the saved key works. It uses no tokens.') }}</p>
            <form method="POST" action="{{ route('agents.admin.test') }}" class="mt-3">@csrf<button class="btn btn-ghost" @disabled(! $connected)>{{ __('Test key') }}</button></form>
        </section>

        <section class="card overflow-x-auto lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Use by company this month') }}</h2></div>
            @if ($usage->isEmpty())
                <x-empty :title="__('No use yet this month')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Company') }}</th><th class="r">{{ __('Calls') }}</th><th class="r">{{ __('Tokens in') }}</th><th class="r">{{ __('Tokens out') }}</th><th class="r">{{ __('Cost (US$)') }}</th><th class="r">{{ __('Credits') }}</th></tr></thead>
                    <tbody>
                    @foreach ($usage as $row)
                        <tr>
                            <td>{{ $row->company }}</td>
                            <td class="r">{{ number_format((int) $row->calls) }}</td>
                            <td class="r">{{ number_format((int) $row->input_total) }}</td>
                            <td class="r">{{ number_format((int) $row->output_total) }}</td>
                            <td class="r">{{ number_format((float) $row->cost_usd, 4) }}</td>
                            <td class="r">{{ \App\Support\Dec::indian($row->credits, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection
