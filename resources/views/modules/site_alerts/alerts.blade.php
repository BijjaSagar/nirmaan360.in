@extends('layouts.app')
@section('title', __('Alerts'))
@section('content')
    <x-page-header :title="__('Alerts')" :subtitle="$project->name">
        @can('alerts.manage')
            <form method="POST" action="{{ route('alerts.scan', $project) }}">@csrf<button class="btn btn-primary"><x-icon name="bell" class="size-4" />{{ __('Check alerts now') }}</button></form>
        @endcan
        <a href="{{ route('site-dashboard.show', $project) }}" class="btn btn-ghost">{{ __('Site dashboard') }}</a>
    </x-page-header>

    <div class="mb-4 flex gap-2">
        <a href="{{ route('alerts.index', $project) }}" class="btn {{ $status === 'open' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Open') }} <span class="num text-xs opacity-80">{{ $openCount }}</span></a>
        <a href="{{ route('alerts.index', [$project, 'status' => 'resolved']) }}" class="btn {{ $status === 'resolved' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Resolved') }}</a>
    </div>

    <section class="card">
        @if ($alerts->isEmpty())
            @if ($status === 'open')
                <x-empty :title="__('No open alerts')" :note="__('Alerts open here when the project falls behind, a deadline nears, or another module flags a problem. They close on their own when the cause clears.')" />
            @else
                <x-empty :title="__('Nothing resolved yet')" :note="__('An alert moves here once its cause has cleared.')" />
            @endif
        @else
            <ul class="divide-y divide-rule">
                @foreach ($alerts as $alert)
                    @include('modules.site_alerts.partials.alert', ['alert' => $alert])
                @endforeach
            </ul>
        @endif
    </section>

    @if ($alerts->hasPages())
        <div class="mt-4">{{ $alerts->links() }}</div>
    @endif
@endsection
