@php
    $tabs = [
        ['route' => 'material_control.index', 'pattern' => 'material_control.index', 'label' => __('Check'), 'can' => 'material_control.view'],
        ['route' => 'material_control.challans.index', 'pattern' => 'material_control.challans.*', 'label' => __('Challans'), 'can' => 'material_control.view'],
        ['route' => 'material_control.consumption.index', 'pattern' => 'material_control.consumption.*', 'label' => __('Use'), 'can' => 'material_control.view'],
        ['route' => 'material_control.stock.index', 'pattern' => 'material_control.stock.*', 'label' => __('Stock'), 'can' => 'material_control.view'],
        ['route' => 'material_control.extra.index', 'pattern' => 'material_control.extra.*', 'label' => __('Extra work'), 'can' => 'material_control.view'],
        ['route' => 'material_control.bills.index', 'pattern' => 'material_control.bills.*', 'label' => __('Bills'), 'can' => 'material_control.bills'],
    ];
@endphp
<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-rule pb-px" aria-label="{{ __('Material control') }}">
    @foreach ($tabs as $tab)
        @can($tab['can'])
            <a href="{{ route($tab['route'], $project) }}"
               class="flex min-h-11 shrink-0 items-center border-b-2 px-3 text-sm font-semibold {{ request()->routeIs($tab['pattern']) ? 'border-laterite text-laterite' : 'border-transparent text-ink-2' }}">{{ $tab['label'] }}</a>
        @endcan
    @endforeach
</nav>
