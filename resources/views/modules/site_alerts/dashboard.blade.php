@extends('layouts.app')
@section('title', __('Site dashboard'))
@section('content')
    <x-page-header :title="__('Site dashboard')" :subtitle="$project->name.' · '.now()->format('d M Y')">
        @can('alerts.manage')
            <form method="POST" action="{{ route('alerts.scan', $project) }}">@csrf<button class="btn btn-ghost"><x-icon name="bell" class="size-4" />{{ __('Check alerts now') }}</button></form>
        @endcan
        @can('alerts.view')
            <a href="{{ route('alerts.index', $project) }}" class="btn btn-ghost">{{ __('Alerts') }}</a>
        @endcan
    </x-page-header>

    @if ($cards === [])
        <div class="card">
            <x-empty :title="__('Nothing to show yet')" :note="__('Add the BOQ and daily progress, and the dashboard fills itself in.')" />
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cards as $card)
                @include('modules.site_alerts.partials.card', ['card' => $card])
            @endforeach
        </div>
    @endif
@endsection
