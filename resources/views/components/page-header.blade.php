@props(['title', 'subtitle' => null])
<div class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-ink/70 pb-3">
    <div class="min-w-0">
        <h1 class="text-[32px]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-0.5 text-sm text-ink-3">{{ $subtitle }}</p>
        @endif
    </div>
    @if (trim($slot) !== '')
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
