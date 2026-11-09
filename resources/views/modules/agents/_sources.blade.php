@if (! empty($sources))
    <div class="mt-2 border-t border-rule pt-2">
        <p class="eyebrow text-[10px] text-ink-3">{{ __('Sources') }}</p>
        <ul class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[13px]">
            @foreach ($sources as $source)
                @if (empty($source['draft']))
                    <li class="min-w-0 break-words"><a href="{{ $source['url'] }}" class="link">{{ $source['label'] }}</a></li>
                @endif
            @endforeach
        </ul>
    </div>
@endif
