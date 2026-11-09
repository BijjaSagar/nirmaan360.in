@extends('layouts.app')
@section('title', __($agent->name))
@section('content')
    <x-page-header :title="__($agent->name)" :subtitle="__($agent->description)">
        @if ($enabled && auth()->user()->can('agents.use') && auth()->user()->can($version->permission))
            <a href="{{ route('agents.chat', $agent->key) }}" class="btn btn-primary">{{ __('Open chat') }}</a>
        @endif
    </x-page-header>

    @include('modules.agents._connection')

    <div class="grid gap-5 lg:grid-cols-2">
        @can('agents.manage')
            <form method="POST" action="{{ route('agents.settings', $agent->key) }}" class="card self-start">
                @csrf @method('PATCH')
                <div class="card-head"><h2 class="card-title">{{ __('Settings for your company') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-3">
                    <x-field :label="__('Status')" name="enabled" class="sm:col-span-3">
                        <select id="enabled" name="enabled" class="input">
                            <option value="1" @selected(old('enabled', $enabled ? '1' : '0') === '1')>{{ __('On') }}</option>
                            <option value="0" @selected(old('enabled', $enabled ? '1' : '0') === '0')>{{ __('Off') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('What it may do')" name="autonomy" class="sm:col-span-3" :hint="__('Approving records and moving money always stay with people, at every level.')">
                        <select id="autonomy" name="autonomy" class="input">
                            @foreach ($autonomyOptions as $level => $label)
                                <option value="{{ $level }}" @selected(old('autonomy', $autonomy) === $level)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Most steps per run')" name="max_steps"><input id="max_steps" name="max_steps" type="number" min="1" max="{{ $version->max_steps }}" value="{{ old('max_steps', $limits['max_steps']) }}" class="input num" required></x-field>
                    <x-field :label="__('Most seconds per run')" name="max_seconds"><input id="max_seconds" name="max_seconds" type="number" min="10" max="{{ $version->max_seconds }}" value="{{ old('max_seconds', $limits['max_seconds']) }}" class="input num" required></x-field>
                    <x-field :label="__('Most credits per run')" name="max_credits"><input id="max_credits" name="max_credits" type="number" step="0.01" min="0.01" max="{{ $version->max_credits }}" value="{{ old('max_credits', rtrim(rtrim($limits['max_credits'], '0'), '.')) }}" class="input num" required></x-field>
                    @if ($agent->key === 'owners_copilot')
                        <label class="flex items-start gap-2 text-sm sm:col-span-3">
                            <input type="hidden" name="daily_brief" value="0">
                            <input type="checkbox" name="daily_brief" value="1" class="mt-0.5" @checked(old('daily_brief', $install->daily_brief))>
                            <span>{{ __('Write a brief at 8 am every day. Each brief uses credits.') }}</span>
                        </label>
                    @endif
                </div>
                <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
            </form>
        @endcan

        <section class="card self-start overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Tools it can use') }}</h2></div>
            <table class="ledger">
                <thead><tr><th>{{ __('Tool') }}</th><th>{{ __('Effect') }}</th><th>{{ __('Needs permission') }}</th></tr></thead>
                <tbody>
                @foreach ($tools as $tool)
                    <tr>
                        <td class="num text-xs">{{ $tool->name() }}</td>
                        <td>{{ ['read' => __('Reads'), 'draft' => __('Saves a draft'), 'approval' => __('Needs a person first')][$tool->effect()] }}</td>
                        <td class="num text-xs">{{ $tool->permission() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section class="card self-start overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Versions') }}</h2></div>
            <table class="ledger">
                <thead><tr><th>{{ __('Version') }}</th><th>{{ __('Model') }}</th><th>{{ __('Date') }}</th></tr></thead>
                <tbody>
                @foreach ($versions as $row)
                    <tr>
                        <td class="num">v{{ $row->version }} @if ($row->id === $version->id)<span class="text-xs text-moss">· {{ __('Current') }}</span>@endif</td>
                        <td class="num text-xs">{{ $row->model }}</td>
                        <td class="num text-xs">{{ $row->created_at->format('d M Y') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>

        <section class="card self-start overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Recent runs') }}</h2></div>
            @if ($runs->isEmpty())
                <x-empty :title="__('No runs yet')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Run') }}</th><th>{{ __('By') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($runs as $run)
                        <tr>
                            <td><a href="{{ route('agent-runs.show', $run) }}" class="link num">#{{ $run->id }}</a></td>
                            <td>{{ $run->actor?->name ?? '—' }}</td>
                            <td><x-stamp :status="$run->status" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection
