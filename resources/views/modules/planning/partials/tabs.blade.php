@php
    $tabs = [
        ['route' => 'planning.index', 'label' => __('Overview')],
        ['route' => 'planning.schedule', 'label' => __('Schedule')],
        ['route' => 'planning.budget', 'label' => __('Budget')],
    ];
@endphp
<nav class="mb-5 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-rule" aria-label="{{ __('Planning') }}">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route'], $project) }}" @class([
            'border-b-2 px-3 py-2 text-sm font-semibold whitespace-nowrap',
            'border-laterite text-ink' => request()->routeIs($tab['route']),
            'border-transparent text-ink-3 hover:text-ink' => ! request()->routeIs($tab['route']),
        ])>{{ $tab['label'] }}</a>
    @endforeach
</nav>
