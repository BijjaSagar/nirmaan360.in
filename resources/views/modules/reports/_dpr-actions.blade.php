{{-- PDF and WhatsApp buttons for a DPR. The WhatsApp button opens a wa.me link with the text and a signed link. --}}
@if (auth()->user()->organization->allowsFeature('reports'))
    <a href="{{ route('dprs.pdf', $dpr) }}" class="btn btn-ghost">{{ __('PDF') }}</a>
    <a href="{{ app(\App\Services\Reports\DprReportService::class)->whatsappUrl($dpr) }}" target="_blank" rel="noopener" class="btn btn-ghost" title="{{ __('Opens WhatsApp with the report summary and a link that works for :days days.', ['days' => \App\Services\Reports\DprReportService::LINK_DAYS]) }}">{{ __('Share on WhatsApp') }}</a>
@endif
