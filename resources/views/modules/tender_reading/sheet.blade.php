@extends('layouts.app')
@section('title', __('Tender sheet'))
@section('content')
    <x-page-header :title="__('Tender sheet')" :subtitle="$tender->title">
        <a href="{{ route('crm.tenders.show', $tender) }}" class="btn btn-ghost">{{ __('Back to the tender') }}</a>
    </x-page-header>

    @include('modules.tender_reading._pages')

    <div class="space-y-5">
        <section class="card space-y-1.5 p-4 text-sm">
            <p class="font-medium">{{ __('This sheet is a reading aid. Read the tender conditions yourself. A corrigendum is seen only if you upload it.') }}</p>
            @if ($sheet['text_matched'])
                <p class="text-ink-2">{{ __("The quoted line matches the PDF's text on that page. The page is shown beside it for you to check.") }}</p>
            @endif
            @if ($sheet['pictures'])
                <p class="text-ink-2">{{ __('Read from the page picture. Check it against the page.') }}</p>
            @endif
            <p class="text-ink-2">{{ __('Nothing here changes the tender. Each fact stays a proposal until a person confirms it with its page open, and reaches the tender only when a person puts it there.') }}</p>
            <p class="text-xs text-ink-3">{{ __('Not checked yet: :proposed · Confirmed: :confirmed · Rejected: :rejected', $sheet['counts']) }}</p>
        </section>

        @if ($sheet['changes'] !== [])
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('What another document changes') }}</h2></div>
                <p class="border-b border-rule px-4 py-2 text-xs text-ink-3">{{ __('Another document gives a different value from one already confirmed. Check the new value against its page. Confirming it replaces the confirmed one; rejecting it keeps the confirmed one.') }}</p>
                @foreach ($sheet['changes'] as $change)
                    <div class="space-y-1 border-b border-rule px-4 py-3 text-sm last:border-b-0">
                        <h3 class="eyebrow text-ink-3">{{ $change['name'] }}</h3>
                        @foreach ($change['old'] as $old)
                            <p class="break-words"><span class="text-xs text-ink-3">{{ __('Confirmed against its page') }}:</span> <span class="font-medium">{{ $old['value'] }}</span>
                                <span class="text-xs text-ink-3">· {{ __('Page :page', ['page' => $old['page']]) }}@if ($old['document']) · {{ $old['document'] }}@endif</span>
                                @if ($old['applied'])<span class="text-xs text-ink-3">· {{ __('on the tender') }}</span>@endif</p>
                        @endforeach
                        @foreach ($change['new'] as $new)
                            <p class="break-words"><span class="text-xs font-medium text-[#8a6208]">{{ __('New') }}:</span> <span class="font-medium">{{ $new['value'] }}</span>
                                <span class="text-xs text-ink-3">· {{ __('Page :page', ['page' => $new['page']]) }}@if ($new['document']) · {{ $new['document'] }}@endif</span>
                                <a href="#fact-{{ $new['id'] }}" class="link text-xs">{{ __('Check it against the page') }}</a></p>
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endif

        @foreach ($sheet['warnings'] as $warning)
            <p class="rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium text-[#8a6208]" role="status">{{ $warning }}</p>
        @endforeach

        @if ($sheet['emd'])
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('EMD worked out by the app') }}</h2></div>
                <div class="space-y-1.5 p-4 text-sm">
                    <p class="num">{{ __(':percent of :estimated is :worked.', ['percent' => $sheet['emd']['percent'], 'estimated' => $sheet['emd']['estimated'], 'worked' => $sheet['emd']['worked']]) }}</p>
                    <p class="text-xs text-ink-3">{{ __('From the confirmed EMD percentage and the confirmed estimated value.') }}</p>
                    @forelse ($sheet['emd']['printed'] as $printed)
                        <p class="num {{ $printed['differs'] ? 'font-medium text-[#8a6208]' : 'text-moss' }}">
                            {{ $printed['differs']
                                ? __('The EMD amount printed on page :page is :amount. It differs from the worked-out figure.', ['page' => $printed['page'], 'amount' => $printed['amount']])
                                : __('The EMD amount printed on page :page is :amount, the same as the worked-out figure.', ['page' => $printed['page'], 'amount' => $printed['amount']]) }}
                        </p>
                    @empty
                        <p class="text-xs text-ink-3">{{ __('No EMD amount was read to compare it with.') }}</p>
                    @endforelse
                </div>
            </section>
        @endif

        @forelse ($sheet['groups'] as $group)
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ $group['heading'] }}</h2></div>
                <div class="space-y-4 p-4">
                    @foreach ($group['topics'] as $topic)
                        <div class="space-y-2">
                            <h3 class="eyebrow text-ink-3">{{ $topic['name'] }}</h3>
                            @if ($topic['conflict'])
                                <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-xs font-medium text-laterite-dark" role="status">{{ $topic['change']
                                    ? __('Another document gives a different value from the confirmed one. Check the new one against its page; the app does not choose between them.')
                                    : __('The tender says this in more than one way. Check each against its page; the app does not choose between them.') }}</p>
                                <div class="grid gap-3 lg:grid-cols-2">
                                    @foreach ($topic['facts'] as $fact)
                                        @include('modules.tender_reading._fact', ['fact' => $fact, 'beside' => false])
                                    @endforeach
                                </div>
                            @else
                                @foreach ($topic['facts'] as $fact)
                                    @include('modules.tender_reading._fact', ['fact' => $fact, 'beside' => true])
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="card"><p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing has been read from this tender yet.') }}</p></section>
        @endforelse

        @if ($sheet['reads'] !== [])
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Documents read') }}</h2></div>
                @foreach ($sheet['reads'] as $read)
                    <div class="space-y-1 border-b border-rule px-4 py-3 text-sm last:border-b-0">
                        <p><a href="{{ route('tender-reads.view', $read['id']) }}" class="link break-words">{{ $read['document'] ?? __('Removed document') }}</a>
                            <span class="text-xs text-ink-3">· {{ $read['created_at']?->format('d M Y') }}</span></p>
                        @foreach ($read['not_read'] as $line)
                            <p class="text-xs text-[#8a6208]">{{ $line }}</p>
                        @endforeach
                        @if ($read['flagged'] !== null)
                            <p class="text-xs font-medium text-laterite">{{ $read['flagged'] }}</p>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        @if ($sheet['superseded'] !== [])
            <details class="card">
                <summary class="card-head cursor-pointer"><span class="card-title">{{ __('Replaced by another document') }} ({{ count($sheet['superseded']) }})</span></summary>
                <div class="space-y-3 p-4">
                    @foreach ($sheet['superseded'] as $fact)
                        @include('modules.tender_reading._fact', ['fact' => $fact, 'beside' => true])
                    @endforeach
                </div>
            </details>
        @endif

        @if ($sheet['rejected'] !== [])
            <details class="card">
                <summary class="card-head cursor-pointer"><span class="card-title">{{ __('Rejected facts') }} ({{ count($sheet['rejected']) }})</span></summary>
                <div class="space-y-3 p-4">
                    @foreach ($sheet['rejected'] as $fact)
                        @include('modules.tender_reading._fact', ['fact' => $fact, 'beside' => true])
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endsection
