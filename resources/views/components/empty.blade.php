@props(['title', 'note' => null])
<div class="px-6 py-10 text-center">
    <p class="font-display text-xl font-semibold text-ink-2">{{ $title }}</p>
    @if ($note)
        <p class="mx-auto mt-1 max-w-md text-sm text-ink-3">{{ $note }}</p>
    @endif
    @if (trim($slot) !== '')
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
