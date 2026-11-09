@extends('layouts.app')
@section('title', __('Agent runs'))
@section('content')
    <x-page-header :title="__('Agent runs')" :subtitle="__('Every step, tool call and credit is recorded here.')" />

    @include('modules.agents._connection')

    <div class="card overflow-x-auto">
        @if ($runs->isEmpty())
            <x-empty :title="__('No runs yet')" :note="__('Open an agent and send it a message to start.')">
                <a href="{{ route('agents.index') }}" class="btn btn-primary">{{ __('AI agents') }}</a>
            </x-empty>
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Run') }}</th><th>{{ __('Agent') }}</th><th>{{ __('By') }}</th><th>{{ __('Project') }}</th><th class="r">{{ __('Credits') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td><a href="{{ route('agent-runs.show', $run) }}" class="link num">#{{ $run->id }}</a> <span class="num block text-[11px] text-ink-3">{{ $run->created_at->format('d M H:i') }}</span></td>
                        <td>{{ __($run->agent->name) }}</td>
                        <td>{{ $run->actor?->name ?? '—' }}</td>
                        <td>{{ $run->project?->name ?? '—' }}</td>
                        <td class="r">{{ \App\Support\Dec::indian($run->credits_used, 2) }}</td>
                        <td><x-stamp :status="$run->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $runs->links() }}</div>
@endsection
