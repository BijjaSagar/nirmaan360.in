@foreach ($run->drafts() as $draft)
    @php $dpr = $draft['type'] === 'dpr' ? ($dprDrafts[$draft['id']] ?? null) : null; @endphp
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-[3px] border border-rule bg-paper/60 px-3 py-2">
        <span class="min-w-0 text-sm">
            <span class="eyebrow block text-[10px] text-ink-3">{{ __('Draft for you to check') }}</span>
            <a href="{{ $draft['url'] }}" class="link break-words">{{ $draft['label'] }}</a>
        </span>
        @if ($dpr && $dpr->isEditable() && auth()->user()->can('dpr.create'))
            <span class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('dprs.submit', $dpr) }}">@csrf<button class="btn btn-primary">{{ __('Confirm') }}</button></form>
                <a href="{{ route('agents.drafts.dpr.edit', $dpr) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
            </span>
        @elseif ($dpr)
            <x-stamp :status="$dpr->status" />
        @endif
    </div>
@endforeach
