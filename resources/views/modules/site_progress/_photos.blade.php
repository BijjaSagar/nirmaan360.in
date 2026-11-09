@if ($photos->isNotEmpty())
    <div class="mt-1.5 flex flex-wrap gap-1.5">
        @foreach ($photos as $photo)
            <a href="{{ route('site-progress.photos.show', $photo) }}" target="_blank" rel="noopener" aria-label="{{ __('Open photo') }}">
                <img src="{{ route('site-progress.photos.show', $photo) }}" alt="{{ $photo->caption ?? __('Site photo') }}" loading="lazy" class="size-14 rounded-[3px] border border-rule object-cover">
            </a>
        @endforeach
    </div>
@endif
