@props(['tone' => 'light'])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    <svg viewBox="0 0 32 32" class="size-6 shrink-0" aria-hidden="true"><rect width="32" height="32" rx="4" fill="#b4451f"/><path d="M8 24V8h3.4l9.2 10.4V8H24v16h-3.4l-9.2-10.4V24z" fill="#f5f1e8"/></svg>
    <span class="font-display text-[22px] leading-none font-semibold tracking-wide {{ $tone === 'light' ? 'text-paper' : 'text-ink' }}">NIRMAAN<span class="text-laterite {{ $tone === 'light' ? 'brightness-150' : '' }}">360</span></span>
</span>
