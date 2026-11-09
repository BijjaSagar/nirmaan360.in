@php
    /** @var \App\Models\Alert $alert */
    $tone = match ($alert->severity) {
        'critical' => 'border-laterite text-laterite',
        'warning' => 'border-haldi text-[#8a6208]',
        default => 'border-neel text-neel',
    };
@endphp
<li class="px-4 py-3">
    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="stamp {{ $tone }}">{{ __(\Illuminate\Support\Str::headline($alert->severity)) }}</span>
                <x-stamp :status="$alert->status" />
                @if ($showProject ?? false)
                    <span class="truncate text-xs text-ink-3">{{ $alert->project?->name }}</span>
                @endif
            </div>
            <p class="mt-1.5 font-semibold">{{ $alert->titleIn() }}</p>
            <p class="text-sm text-ink-2">{{ $alert->messageIn() }}</p>
            <p class="num mt-1 text-[11px] text-ink-3">
                {{ __('Since :date', ['date' => $alert->first_seen_at->format('d M Y')]) }}
                @if ($alert->isOpen())
                    · {{ __('Checked :date', ['date' => $alert->last_seen_at->format('d M, H:i')]) }}
                @else
                    · {{ __('Resolved :date', ['date' => $alert->resolved_at?->format('d M Y')]) }}
                @endif
            </p>
        </div>
        @if ($alert->url && $alert->isOpen())
            <a href="{{ $alert->url }}" class="btn btn-ghost shrink-0">{{ __('Open') }}</a>
        @endif
    </div>
</li>
