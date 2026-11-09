@extends('layouts.app')
@section('title', __('Tenders'))
@section('content')
    @php
        $stages = \App\Services\Crm\Vocab::stages();
        $query = array_filter($filters);
    @endphp
    <x-page-header :title="__('Tenders')" :subtitle="__('Every bid from enquiry to award')">
        <a href="{{ route('crm.tenders.index', array_merge($query, $list ? [] : ['view' => 'list'])) }}" class="btn btn-ghost">{{ $list ? __('Board view') : __('List view') }}</a>
        @can('crm.manage')
            <a href="{{ route('crm.tenders.create') }}" class="btn btn-primary">{{ __('Add tender') }}</a>
        @endcan
    </x-page-header>

    <div x-data="{ show: {{ $query ? 'true' : 'false' }} }" class="mb-4">
    <button type="button" class="btn btn-ghost min-h-10 w-full sm:hidden" @click="show = !show" :aria-expanded="show">{{ __('Search and filter') }}</button>
    <form method="GET" action="{{ route('crm.tenders.index') }}" :class="show ? 'grid' : 'hidden sm:grid'" class="mt-2 gap-2 sm:mt-0 sm:grid-cols-2 lg:grid-cols-5">
        @if ($list)<input type="hidden" name="view" value="list">@endif
        <input name="q" value="{{ $filters['q'] ?? '' }}" class="input lg:col-span-2" placeholder="{{ __('Search title, authority or reference') }}" aria-label="{{ __('Search') }}">
        <select name="owner" class="input" aria-label="{{ __('Owner') }}">
            <option value="">{{ __('Any owner') }}</option>
            @foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected((string) ($filters['owner'] ?? '') === (string) $owner->id)>{{ $owner->name }}</option>@endforeach
        </select>
        <select name="tag" class="input" aria-label="{{ __('Tags') }}">
            <option value="">{{ __('Any tag') }}</option>
            @foreach ($tagOptions as $option)<option value="{{ $option }}" @selected(($filters['tag'] ?? '') === $option)>{{ $option }}</option>@endforeach
        </select>
        <button class="btn btn-ghost">{{ __('Filter') }}</button>
    </form>
    </div>

    @if ($list)
        <div class="card overflow-x-auto">
            @if ($tenders->isEmpty())
                <x-empty :title="__('No tenders found')" :note="__('Add a tender to start tracking it from enquiry to award.')" />
            @else
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>{{ __('Tender') }}</th><th>{{ __('Authority') }}</th><th>{{ __('Stage') }}</th><th class="r">{{ __('Value') }}</th><th>{{ __('Due') }}</th><th>{{ __('Owner') }}</th></tr></thead>
                    <tbody>
                    @foreach ($tenders as $tender)
                        <tr>
                            <td><a href="{{ route('crm.tenders.show', $tender) }}" class="link">{{ $tender->title }}</a>@include('modules.crm.partials.tags', ['tags' => $tender->tags, 'route' => 'crm.tenders.index', 'routeParams' => ['view' => 'list']])</td>
                            <td>{{ $tender->authority }}</td>
                            <td>@include('modules.crm.partials.stage', ['stage' => $tender->stage])</td>
                            <td class="r">{{ inr($tender->headlineValue()) }}</td>
                            <td class="num text-xs">{{ $tender->due_at?->format('d M Y, h:i A') ?? '—' }}</td>
                            <td>{{ $tender->owner?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $tenders->links() }}</div>
    @else
        {{-- Phone: one stage at a time, picked from the tabs. Larger screens: every stage side by side. --}}
        <div x-data="{ tab: 'enquiry' }" x-init="const h = location.hash.slice(1); if (@js(array_keys($stages)).includes(h)) tab = h">
            <div class="-mx-4 mb-3 flex gap-1.5 overflow-x-auto px-4 pb-1 md:hidden" role="tablist">
                @foreach ($columns as $stage => $column)
                    <button type="button" role="tab" @click="tab = '{{ $stage }}'; history.replaceState(null, '', '#{{ $stage }}')"
                            :class="tab === '{{ $stage }}' ? 'btn-ink' : 'btn-ghost'" :aria-selected="tab === '{{ $stage }}'" class="btn min-h-10 shrink-0">
                        {{ $stages[$stage] }} <span class="num text-xs opacity-80">{{ $column['count'] }}</span>
                    </button>
                @endforeach
            </div>

            <div class="md:flex md:items-start md:gap-3 md:overflow-x-auto md:pb-3">
                @foreach ($columns as $stage => $column)
                    <section id="{{ $stage }}" :class="tab === '{{ $stage }}' ? 'block' : 'hidden md:block'" class="md:w-72 md:shrink-0">
                        <div class="mb-2 flex items-baseline justify-between gap-2 border-b border-ink/70 pb-1.5">
                            <h2 class="text-lg">{{ $stages[$stage] }} <span class="num text-sm text-ink-3">{{ $column['count'] }}</span></h2>
                            <span class="num text-xs text-ink-3">{{ inr($column['value']) }}</span>
                        </div>
                        <div class="space-y-2">
                            @forelse ($column['tenders'] as $tender)
                                @php $state = $tender->deadlineState(); @endphp
                                <article class="card p-3">
                                    <a href="{{ route('crm.tenders.show', $tender) }}" class="block">
                                        <p class="font-semibold leading-snug">{{ $tender->title }}</p>
                                        <p class="mt-0.5 text-xs text-ink-3">{{ $tender->authority }}</p>
                                    </a>
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs">
                                        <span class="num font-medium">{{ inr($tender->headlineValue()) }}</span>
                                        @if ($tender->due_at && $state)
                                            <span class="{{ $state === 'overdue' ? 'font-semibold text-laterite' : ($state === 'soon' ? 'font-semibold text-[#8a6208]' : 'text-ink-3') }}">
                                                {{ $state === 'overdue' ? __('Overdue') : __('Due') }} {{ $tender->due_at->format('d M') }}
                                            </span>
                                        @elseif ($tender->closed_on)
                                            <span class="text-ink-3">{{ $tender->closed_on->format('d M Y') }}</span>
                                        @endif
                                    </div>
                                    @if ($tender->owner)<p class="mt-1 text-xs text-ink-3">{{ $tender->owner->name }}</p>@endif
                                    @include('modules.crm.partials.tags', ['tags' => $tender->tags, 'route' => 'crm.tenders.index'])
                                    @can('crm.manage')
                                        @if ($tender->isOpen())
                                            <form method="POST" action="{{ route('crm.tenders.move', $tender) }}" class="mt-2 flex gap-1.5">
                                                @csrf
                                                <select name="stage" class="input min-h-10 flex-1 py-1 text-xs" aria-label="{{ __('Move to') }}">
                                                    @foreach ($stages as $key => $label)
                                                        <option value="{{ $key }}" @selected($key === $tender->stage)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="btn btn-ghost min-h-10 px-3">{{ __('Move') }}</button>
                                            </form>
                                        @endif
                                    @endcan
                                </article>
                            @empty
                                <p class="rounded-[3px] border border-dashed border-rule px-3 py-6 text-center text-xs text-ink-3">{{ __('No tenders here.') }}</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>

        <p class="mt-4 text-xs text-ink-3">
            @if ($allClosed)
                <a href="{{ route('crm.tenders.index', $query) }}" class="link">{{ __('Show only recent closed tenders') }}</a>
            @else
                {{ __('Won, lost and no-bid columns show the last :days days.', ['days' => \App\Services\Crm\TenderService::CLOSED_WINDOW_DAYS]) }}
                <a href="{{ route('crm.tenders.index', [...$query, 'all' => 1]) }}" class="link">{{ __('Show all') }}</a>
            @endif
        </p>
    @endif
@endsection
