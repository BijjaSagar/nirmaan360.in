{{-- $work always. $paid and $status only for people who may see money. --}}
@php
    use App\Services\ContractorControl\Figures;
    $paidColour = ($status ?? null) === 'red' ? 'bg-laterite' : 'bg-moss';
@endphp
<div class="space-y-1">
    <div class="flex items-center gap-2 text-xs">
        <span class="w-9 shrink-0 text-ink-3">{{ __('Work') }}</span>
        <span class="h-2 flex-1 rounded-[2px] bg-paper-2"><span class="block h-2 rounded-[2px] bg-neel" style="width: {{ min(100, (float) $work) }}%"></span></span>
        <span class="num w-12 shrink-0 text-right">{{ Figures::pct($work) }}%</span>
    </div>
    @isset($paid)
        <div class="flex items-center gap-2 text-xs">
            <span class="w-9 shrink-0 text-ink-3">{{ __('Paid') }}</span>
            <span class="h-2 flex-1 rounded-[2px] bg-paper-2"><span class="block h-2 rounded-[2px] {{ $paidColour }}" style="width: {{ min(100, (float) $paid) }}%"></span></span>
            <span class="num w-12 shrink-0 text-right">{{ Figures::pct($paid) }}%</span>
        </div>
    @endisset
</div>
