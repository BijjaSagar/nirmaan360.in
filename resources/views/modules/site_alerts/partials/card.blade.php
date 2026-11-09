@php
    /** @var \App\Services\SiteAlerts\Card $card */
    $text = fn (?string $tone) => match ($tone) {
        'ok' => 'text-moss',
        'warn' => 'text-[#8a6208]',
        'bad' => 'text-laterite',
        default => 'text-ink',
    };
    $bar = fn (?string $tone) => match ($tone) {
        'ok' => 'bg-moss',
        'warn' => 'bg-haldi',
        'bad' => 'bg-laterite',
        default => 'bg-neel',
    };
@endphp
<section class="card flex flex-col {{ $card->wide ? 'sm:col-span-2' : '' }}" data-card="{{ $card->key }}">
    <div class="card-head">
        <h2 class="card-title">{{ $card->title }}</h2>
        @if ($card->href)
            <a href="{{ $card->href }}" class="link text-sm">{{ $card->action ?? __('Open') }}</a>
        @endif
    </div>
    <div class="px-4 pt-3 pb-3.5">
        @if ($card->value !== null)
            <p class="num text-[30px] leading-none font-medium {{ $text($card->tone) }}">{{ $card->value }}</p>
        @endif
        @if ($card->note)
            <p class="mt-1.5 text-[13px] text-ink-3">{{ $card->note }}</p>
        @endif
        @foreach ($card->meters as $meter)
            @php $width = min(100, max(0, (float) $meter['percent'])); @endphp
            <div class="mt-3">
                <div class="flex items-baseline justify-between gap-3 text-xs">
                    <span class="text-ink-2">{{ $meter['label'] }}</span>
                    <span class="num text-ink">{{ \App\Support\Figure::trim($meter['percent']) }}%</span>
                </div>
                <div class="mt-1 h-2 bg-paper-2" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $width }}" aria-label="{{ $meter['label'] }}">
                    <div class="h-full {{ $bar($meter['tone'] ?? null) }}" style="width: {{ $width }}%"></div>
                </div>
            </div>
        @endforeach
    </div>
    @if ($card->rows !== [])
        <ul class="divide-y divide-rule border-t border-rule text-sm">
            @foreach ($card->rows as $row)
                @php $tag = ! empty($row['href']) ? 'a' : 'div'; @endphp
                <li>
                    <{{ $tag }} @if ($tag === 'a') href="{{ $row['href'] }}" @endif class="flex items-start justify-between gap-3 px-4 py-2 {{ $tag === 'a' ? 'hover:bg-paper/70' : '' }}">
                        <span class="min-w-0">
                            <span class="block truncate font-medium {{ $text($row['tone'] ?? null) }}">{{ $row['label'] }}</span>
                            @if (! empty($row['sub']))
                                <span class="block text-xs text-ink-3">{{ $row['sub'] }}</span>
                            @endif
                        </span>
                        @if (isset($row['value']) && $row['value'] !== null)
                            <span class="num shrink-0 text-right text-xs {{ $text($row['tone'] ?? null) }}">{{ $row['value'] }}</span>
                        @endif
                    </{{ $tag }}>
                </li>
            @endforeach
        </ul>
    @endif
</section>
