@if (! empty($tags))
    <div class="flex flex-wrap gap-1">
        @foreach ($tags as $tag)
            @if (! empty($route))
                <a href="{{ route($route, [...($routeParams ?? []), 'tag' => $tag]) }}" class="inline-flex rounded-full border border-rule bg-paper-2/60 px-2 py-0.5 text-[11px] text-ink-2 hover:border-ink-3">{{ $tag }}</a>
            @else
                <span class="inline-flex rounded-full border border-rule bg-paper-2/60 px-2 py-0.5 text-[11px] text-ink-2">{{ $tag }}</span>
            @endif
        @endforeach
    </div>
@endif
