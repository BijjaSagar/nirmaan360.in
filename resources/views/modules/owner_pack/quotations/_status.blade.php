{{-- $status: draft or issued. $replacedBy: the number of a later issued revision, when there is one. --}}
@if (($replacedBy ?? null) !== null)
    <span class="stamp border-ink-3 text-ink-3">{{ __('Replaced') }}</span>
@elseif ($status === \App\Models\ClientQuotation::ISSUED)
    <span class="stamp border-moss text-moss">{{ __('Issued') }}</span>
@else
    <span class="stamp border-haldi text-[#8a6208]">{{ __('Draft') }}</span>
@endif
