@extends('layouts.app')
@section('title', __('Alerts'))
@section('content')
    <x-page-header :title="__('Alerts')" :subtitle="__('Open alerts on every project you can see')" />

    @if ($groups->isEmpty())
        <div class="card">
            <x-empty :title="__('No open alerts')" :note="__('Alerts open here when a project falls behind, a deadline nears, or another module flags a problem. They close on their own when the cause clears.')" />
        </div>
    @else
        <div class="space-y-5">
            @foreach ($groups as $alerts)
                @php $project = $alerts->first()->project; @endphp
                <section class="card">
                    <div class="card-head">
                        <h2 class="card-title">{{ $project?->name ?? '—' }}</h2>
                        @if ($project)
                            <a href="{{ route('alerts.index', $project) }}" class="link text-sm">{{ __('All alerts') }}</a>
                        @endif
                    </div>
                    <ul class="divide-y divide-rule">
                        @foreach ($alerts as $alert)
                            @include('modules.site_alerts.partials.alert', ['alert' => $alert])
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
@endsection
