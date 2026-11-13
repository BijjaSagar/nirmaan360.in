{{-- $update: the weekly update. Shared means a link is open now; an update whose link was withdrawn or ran out reads as approved. --}}
@if ($update->isShared())
    <span class="stamp border-neel text-neel">{{ __('Shared') }}</span>
@elseif ($update->isApproved())
    <span class="stamp border-moss text-moss">{{ __('Approved') }}</span>
@else
    <span class="stamp border-haldi text-[#8a6208]">{{ __('Draft') }}</span>
@endif
