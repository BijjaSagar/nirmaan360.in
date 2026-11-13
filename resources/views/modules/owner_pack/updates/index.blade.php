@extends('layouts.app')
@section('title', __('Weekly updates'))
@section('content')
    @php
        use App\Services\Wording\Fact;
        use App\Services\Wording\FactFormat;

        $date = fn ($day) => FactFormat::display(Fact::DATE, $day->toDateString(), app()->getLocale());
        $seesMoney = auth()->user()->can('money.view');
    @endphp

    <x-page-header :title="__('Weekly updates')" :subtitle="$project->name">
        <a href="{{ route('owner-updates.create', $project) }}" class="btn btn-primary">{{ __('Prepare update') }}</a>
    </x-page-header>

    <section class="card">
        @forelse ($updates as $update)
            @php $opens = ! $update->show_money || $seesMoney; @endphp
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="font-semibold">
                            @if ($opens)
                                <a href="{{ route('owner-updates.show', $update) }}" class="link">{{ __(':from to :to', ['from' => $date($update->week_start), 'to' => $date($update->week_end)]) }}</a>
                            @else
                                {{ __(':from to :to', ['from' => $date($update->week_start), 'to' => $date($update->week_end)]) }}
                            @endif
                        </p>
                        <p class="text-xs text-ink-3">
                            {{ config('buildos.locales.'.$update->language, $update->language) }}
                            · {{ trans_choice(':count photo|:count photos', count($update->photoIds()), ['count' => count($update->photoIds())]) }}
                            @if ($update->createdBy) · {{ __('Prepared by :name', ['name' => $update->createdBy->name]) }}@endif
                        </p>
                        <p class="mt-0.5 text-xs text-ink-2">
                            @if (! $update->show_money)
                                {{ __('Carries no money figures') }}
                            @elseif ($opens)
                                {{ __('States the payment position') }}
                            @else
                                {{ __('States the payment position, which your role does not see') }}
                            @endif
                        </p>
                    </div>
                    @include('modules.owner_pack.updates._status', ['update' => $update])
                </div>
            </div>
        @empty
            <x-empty :title="__('No weekly updates yet')" :note="__('Prepare an update from the week\'s approved daily reports, pick the photos, check every figure and share it with your client as a link.')">
                <a href="{{ route('owner-updates.create', $project) }}" class="btn btn-primary">{{ __('Prepare update') }}</a>
            </x-empty>
        @endforelse
    </section>

    <div class="mt-3">{{ $updates->links() }}</div>
@endsection
