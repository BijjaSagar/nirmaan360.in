@php
    $moduleGroups = collect(\App\Support\Modules::nav($place, $organization))
        ->filter(fn ($item) => collect((array) $item['can'])->every(fn ($permission) => auth()->user()->can($permission)))
        ->groupBy('group');
    $href = fn ($item) => isset($project) ? route($item['route'], $project) : route($item['route']);
@endphp
@foreach ($moduleGroups as $group => $items)
    @if ($group === '')
        @foreach ($items as $item)
            <a href="{{ $href($item) }}" class="{{ $nav($item['active']) }}">{{ __($item['label']) }}</a>
        @endforeach
    @else
        <details class="nav-group" @if ($items->contains(fn ($item) => request()->routeIs($item['active']))) open @endif>
            <summary class="nav-link justify-between">{{ __($group) }}<x-icon name="chevron" class="nav-chevron size-3.5" /></summary>
            <div class="mt-0.5 ml-2.5 space-y-0.5 border-l border-white/10 pl-1.5">
                @foreach ($items as $item)
                    <a href="{{ $href($item) }}" class="{{ $nav($item['active']) }}">{{ __($item['label']) }}</a>
                @endforeach
            </div>
        </details>
    @endif
@endforeach
