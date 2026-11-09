@extends('layouts.app')
@section('title', __('AI agents'))
@section('content')
    <x-page-header :title="__('AI agents')" :subtitle="__('Answers and drafts from your own records. A person approves anything that matters.')">
        @can('agents.credits')
            <a href="{{ route('agent-credits.index') }}" class="btn btn-ghost">{{ __('Credits') }} · <span class="num">{{ \App\Support\Dec::indian($wallet->available(), 2) }}</span></a>
        @endcan
    </x-page-header>

    @include('modules.agents._connection')

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($agents as $row)
            @php $agent = $row['agent']; @endphp
            <section class="card flex flex-col">
                <div class="card-head">
                    <h2 class="card-title min-w-0">{{ __($agent->name) }}</h2>
                    @unless ($row['enabled'])<span class="text-sm text-ink-3">{{ __('Off') }}</span>@endunless
                </div>
                <div class="flex flex-1 flex-col justify-between gap-3 p-4">
                    <p class="text-sm text-ink-2">{{ __($agent->description) }}</p>
                    <p class="text-xs text-ink-3">{{ __(\App\Services\Agents\Autonomy::LEVELS[$row['autonomy']]) }}</p>
                    <div class="flex flex-wrap gap-2">
                        @if ($row['enabled'] && auth()->user()->can('agents.use') && auth()->user()->can($agent->currentVersion->permission))
                            <a href="{{ route('agents.chat', $agent->key) }}" class="btn btn-primary">{{ __('Open chat') }}</a>
                        @endif
                        <a href="{{ route('agents.show', $agent->key) }}" class="btn btn-ghost">{{ __('Details') }}</a>
                    </div>
                </div>
            </section>
        @endforeach
    </div>
@endsection
