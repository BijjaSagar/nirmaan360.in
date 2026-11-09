@props(['tone' => 'dark'])
<div {{ $attributes->merge(['class' => 'flex items-center gap-1 text-[13px]']) }}>
    @foreach (config('buildos.locales') as $code => $label)
        <a href="{{ route('locale', $code) }}"
           class="locale-link rounded-[2px] px-1.5 py-0.5 {{ app()->getLocale() === $code
               ? ($tone === 'dark' ? 'bg-ink text-paper' : 'bg-paper text-ink')
               : ($tone === 'dark' ? 'text-ink-2 hover:bg-paper-2' : 'text-paper/70 hover:text-paper') }}">{{ $label }}</a>
    @endforeach
</div>
