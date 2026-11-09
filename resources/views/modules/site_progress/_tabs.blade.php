@php
    $tabs = [
        'site-progress.index' => __('Overview'),
        'site-progress.entries' => __('Entries'),
        'site-progress.floors.index' => __('Floors'),
        'site-progress.pending' => __('Pending'),
        'site-progress.week' => __('Weekly'),
        'site-stages.index' => __('Stages'),
    ];
@endphp
<nav class="-mx-4 mb-4 flex gap-1 overflow-x-auto border-b border-rule px-4 sm:mx-0 sm:px-0" aria-label="{{ __('Site progress') }}">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route, $project) }}" @if ($active === $route) aria-current="page" @endif
           class="-mb-px whitespace-nowrap border-b-2 px-3.5 py-3 text-[15px] font-semibold {{ $active === $route ? 'border-laterite text-ink' : 'border-transparent text-ink-3 hover:text-ink' }}">{{ $label }}</a>
    @endforeach
</nav>
@include('modules.site_progress._warnings')
