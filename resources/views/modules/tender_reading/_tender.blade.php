{{--
    The tender reader's card on the tender page. Needs $tender and $documents (the tender's documents
    as the page lists them). Shows nothing when there is no sheet and no reading to offer.
--}}
@php
    $tenderReader = app(\App\Services\TenderReading\TenderCard::class)->for($tender, auth()->user(), $documents);
@endphp
@if ($tenderReader !== null)
<section class="card" id="tender-reader">
    <div class="card-head">
        <h2 class="card-title">{{ __('Tender reader') }}</h2>
        @if ($tenderReader['proposed'] + $tenderReader['confirmed'] > 0)
            <a href="{{ route('tender-facts.index', $tender) }}" class="btn btn-ghost min-h-11 py-1 text-xs">{{ __('Open the tender sheet') }}</a>
        @endif
    </div>
    @if ($tenderReader['proposed'] + $tenderReader['confirmed'] > 0)
        <p class="border-b border-rule px-4 py-2 text-xs text-ink-3">
            {{ __('Not checked yet: :proposed · Confirmed: :confirmed', ['proposed' => $tenderReader['proposed'], 'confirmed' => $tenderReader['confirmed']]) }}
            @if ($tenderReader['conflicts'] > 0) · <span class="font-medium text-laterite">{{ __('Places where the tender says two things: :count', ['count' => $tenderReader['conflicts']]) }}</span>@endif
        </p>
    @endif
    @foreach ($tenderReader['documents'] as $row)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
            <span class="min-w-0 break-words">{{ $row['title'] }}</span>
            <span class="flex flex-wrap items-center gap-2">
                @if ($row['read'])
                    <a href="{{ route('tender-reads.view', $row['read']->id) }}" class="link text-xs">{{ __('Open the reading') }}</a>
                @endif
                @if ($tenderReader['can_read'] && $row['is_pdf'])
                    <form method="POST" action="{{ route('tender-reads.store', ['tender' => $tender, 'document' => $row['id']]) }}">
                        @csrf
                        <input type="hidden" name="client_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <button class="btn btn-ghost min-h-11 py-1 text-xs">{{ __('Read this document') }}</button>
                    </form>
                @endif
            </span>
        </div>
    @endforeach
    @if ($tenderReader['can_read'])
        <p class="px-4 py-2 text-xs text-ink-3">{{ __('Lists the dates, deposits, periods and documents a tender asks for, each with its page. You confirm each one against the page.') }}</p>
    @endif
</section>
@endif
