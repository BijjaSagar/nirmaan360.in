@extends('layouts.app')
@section('title', __('Tenders for this line'))
@section('content')
    {{--
        Opened from a line on the BOQ page. $line comes from ContractorControlPresenter::lineTenders, which has already
        left every price out for someone without money.view, so a figure is only ever printed inside "if ($money)".
    --}}
    @php
        use App\Services\ContractorControl\Figures;
        use App\Support\Dec;
        use Illuminate\Support\Carbon;
        use Illuminate\Support\Str;

        $day = fn (?string $value) => $value ? Carbon::parse($value)->format('d M Y') : null;
        $itemId = $line['boq_item_id'];

        // Which form was sent, so a refused one opens again with what was typed, and only that one shows the errors.
        $sent = is_string(old('form')) ? old('form') : '';
        $uuid = fn (string $form) => $sent === $form ? old('client_uuid', (string) Str::uuid()) : (string) Str::uuid();

        // With nothing sent, the first open tender still waiting for a quote has its form open, and a line with no tender offers a new one.
        $waiting = collect($line['tenders'])->first(fn (array $tender) => $tender['status'] === 'OPEN' && $tender['quote_count'] === 0);
        $quoting = str_starts_with($sent, 'quote-') ? (int) Str::after($sent, 'quote-') : ($sent === '' ? (int) ($waiting['id'] ?? 0) : 0);
        // Quotes are compared by their totals, so a line with no quantity cannot start a tender of its own.
        $noQty = Dec::of($line['qty'])->isZero();
        $starting = ! $noQty && ($sent === 'tender' || ($sent === '' && $line['tender_count'] === 0));
    @endphp

    <div @if ($canQuote) x-data="{ ...contractorPopup(), quoting: {{ $quoting }}, starting: @js($starting) }" @endif>
    <div @if ($canQuote) :inert="open" @endif>
        <x-page-header :title="__('Tenders for this line')" :subtitle="$line['code'].' · '.$project->name">
            <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost">{{ __('Back to BOQ') }}</a>
            @if ($canQuote && ! $noQty)
                <button type="button" class="btn btn-primary" @click="starting = !starting">{{ __('New tender') }}</button>
            @endif
        </x-page-header>

        <section class="card mb-5 px-4 py-3">
            <p class="eyebrow text-ink-3">{{ __('BOQ line') }} {{ $line['code'] }}</p>
            <p class="mt-1">{{ $line['description'] }}</p>
            <p class="num mt-1 text-sm text-ink-2">{{ fmt_qty($line['qty']) }} {{ $line['unit'] }}@if ($money) × {{ inr($line['boq_rate']) }} = {{ inr($line['boq_amount']) }}@endif</p>
        </section>

        {{-- Nothing to count until the line has been in a tender. --}}
        @if ($line['tenders'] !== [])
            <div class="mb-5 grid grid-cols-2 gap-3 {{ $money ? 'sm:grid-cols-3' : '' }}">
                @include('modules.contractor_control._stat', ['label' => __('Tenders'), 'value' => $line['tender_count'], 'note' => __(':count still open', ['count' => $line['open_tender_count']])])
                @include('modules.contractor_control._stat', ['label' => __('Quotes'), 'value' => $line['quote_count']])
                @if ($money)
                    <div class="col-span-2 sm:col-span-1">
                        @include('modules.contractor_control._stat', ['label' => __('Lowest rate'), 'value' => $line['lowest_rate'] !== null ? inr($line['lowest_rate']) : '—', 'note' => $line['lowest_contractor']])
                    </div>
                @endif
            </div>
        @endif

        @unless ($money)
            <p class="mb-5 text-sm text-ink-3">{{ __('Your role sees who quoted, not the prices.') }}</p>
        @endunless

        @if ($canQuote && $noQty)
            <p class="mb-5 text-sm text-ink-2">{{ __('This line has no quantity yet. Enter its quantity in the BOQ before asking contractors for quotes.') }}</p>
        @elseif ($canQuote)
            <section class="card mb-5" x-show="starting" x-cloak>
                <div class="card-head"><h2 class="card-title">{{ __('New tender for this line') }}</h2></div>
                <form method="POST" action="{{ route('boq.tenders.store', $itemId) }}" class="grid gap-4 p-4 sm:grid-cols-3">
                    @csrf
                    <input type="hidden" name="form" value="tender">
                    <input type="hidden" name="client_uuid" value="{{ $uuid('tender') }}">
                    <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title', Str::limit($line['description'], 80, '')) }}" maxlength="160" class="input" required></x-field>
                    <x-field :label="__('Quotes needed by')" name="due_on"><input id="due_on" name="due_on" type="date" value="{{ old('due_on') }}" class="input"></x-field>
                    <x-field :label="__('Scope')" name="scope" class="sm:col-span-3"><textarea id="scope" name="scope" rows="2" class="input">{{ old('scope') }}</textarea></x-field>
                    @error('boq_item_ids')<p class="error sm:col-span-3">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 sm:col-span-3">
                        <button class="btn btn-primary">{{ __('Create tender') }}</button>
                        <a href="{{ route('contractor-control.tenders.create', [$project, 'item' => $itemId]) }}" class="link text-sm">{{ __('Put more BOQ lines in the tender') }}</a>
                    </div>
                </form>
            </section>
        @endif

        @forelse ($line['tenders'] as $tender)
            @php
                $isOpen = $tender['status'] === 'OPEN';
                $prefix = 'quote-'.$tender['id'];
                $active = $sent === $prefix;
                $thisLine = collect($tender['lines'])->firstWhere('boq_item_id', $itemId);
                $otherLines = collect($tender['lines'])->where('boq_item_id', '!=', $itemId);
            @endphp
            <section class="card mb-5 {{ $tender['status'] === 'CANCELLED' ? 'opacity-70' : '' }}">
                <div class="card-head flex-wrap">
                    <div class="min-w-0">
                        <h2 class="card-title">
                            @if ($money)
                                <a href="{{ route('contractor-control.tenders.show', $tender['id']) }}" class="hover:underline">{{ $tender['title'] }}</a>
                            @else
                                {{ $tender['title'] }}
                            @endif
                        </h2>
                        <p class="text-xs text-ink-3">
                            <span class="num">{{ $tender['number'] }}</span>
                            · {{ trans_choice(':count item|:count items', count($tender['lines']), ['count' => count($tender['lines'])]) }}
                            · {{ trans_choice(':count quote|:count quotes', $tender['quote_count'], ['count' => $tender['quote_count']]) }}
                            @if ($tender['due_on']) · {{ __('Quotes needed by') }} <span class="num">{{ $day($tender['due_on']) }}</span>@endif
                        </p>
                    </div>
                    @include('modules.contractor_control.tenders._status', ['status' => $tender['status']])
                </div>

                @if ($tender['work_order_id'])
                    <p class="border-b border-rule px-4 py-2 text-sm">{{ __('Awarded as') }} <a href="{{ route('contractor-control.contracts.show', $tender['work_order_id']) }}" class="link num">{{ $tender['work_order_number'] }}</a></p>
                @endif

                @if ($money)
                    {{-- The tender keeps the BOQ quantity and rate it was made at, and the quotes are set against those. --}}
                    <p class="border-b border-rule px-4 py-2 text-xs text-ink-3">{{ __('BOQ in this tender') }}: <span class="num text-ink-2">{{ fmt_qty($tender['qty']) }} {{ $line['unit'] }} × {{ inr($tender['boq_rate']) }} = {{ inr($tender['boq_amount']) }}</span></p>
                @endif

                @forelse ($tender['quotes'] as $quote)
                    {{-- Who is cheapest is itself about price. --}}
                    @php $isLowest = $money && ($quote['lowest'] ?? false); @endphp
                    <div class="border-b border-rule px-4 py-3 last:border-b-0 {{ $isLowest ? 'bg-sage/40' : '' }} {{ $quote['current'] ? '' : 'opacity-60' }}">
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                            <p class="font-semibold">{{ $quote['contractor'] }}</p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if ($quote['selected'])<span class="stamp border-moss bg-moss text-white">{{ __('Selected') }}</span>@endif
                                @if ($isLowest)<span class="stamp border-moss text-moss">{{ __('Lowest') }}</span>@endif
                                @unless ($quote['current'])<span class="stamp border-ink-3 text-ink-3">{{ __('Replaced') }}</span>@endunless
                            </div>
                        </div>

                        @if ($money)
                            @php $tone = Dec::of($quote['diff'])->isPositive() ? 'text-laterite' : 'text-moss'; @endphp
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm sm:grid-cols-4">
                                <div><dt class="text-xs text-ink-3">{{ __('Rate') }}</dt><dd class="num font-medium">{{ inr($quote['rate']) }}</dd></div>
                                <div><dt class="text-xs text-ink-3">{{ __('vs BOQ rate (₹)') }}</dt><dd class="num {{ $tone }}">{{ Figures::signedInr($quote['diff']) }}</dd></div>
                                <div><dt class="text-xs text-ink-3">{{ __('vs BOQ (%)') }}</dt><dd class="num {{ $tone }}">{{ $quote['diff_pct'] === null ? '—' : Figures::signedPct($quote['diff_pct']).'%' }}</dd></div>
                                <div><dt class="text-xs text-ink-3">{{ __('Amount') }}</dt><dd class="num">{{ inr($quote['amount']) }} <span class="block text-xs {{ $tone }}">{{ Figures::signedInr($quote['diff_amount']) }}</span></dd></div>
                            </dl>
                        @endif

                        @include('modules.contractor_control.tenders._offer', [
                            'receivedOn' => $quote['received_on'],
                            'validUntil' => $quote['valid_until'],
                            'includesMaterial' => $quote['includes_material'],
                            'completionDays' => $quote['completion_days'],
                            'enteredBy' => $quote['entered_by'],
                            'paymentTerms' => $money ? $quote['payment_terms'] : null,
                            'remarks' => $money ? $quote['remarks'] : null,
                        ])

                        @if (($money && $quote['has_quotation']) || ($canAward && $isOpen && $quote['current']))
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                                @if ($money && $quote['has_quotation'])
                                    <a href="{{ route('contractor-control.tenders.bids.quotation', $quote['bid_id']) }}" target="_blank" rel="noopener" class="link text-sm">{{ __('Open the quotation') }}</a>
                                @endif
                                @if ($canAward && $isOpen && $quote['current'])
                                    {{-- The award is for the whole tender, so it is made on the tender's own page, with this quote already chosen. --}}
                                    <a href="{{ route('contractor-control.tenders.show', ['tender' => $tender['id'], 'bid' => $quote['bid_id']]) }}#award" class="btn btn-ghost sm:py-1 sm:text-xs">{{ __('Select this contractor') }}</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <x-empty :title="__('No quotes yet')" />
                @endforelse

                @if ($canQuote && $isOpen)
                    <div class="border-t border-rule px-4 py-3">
                        <button type="button" class="btn btn-ghost" @click="quoting = quoting === {{ $tender['id'] }} ? 0 : {{ $tender['id'] }}" :aria-expanded="quoting === {{ $tender['id'] }}">{{ __('Add a quote') }}</button>

                        <form x-show="quoting === {{ $tender['id'] }}" x-cloak method="POST" action="{{ route('contractor-control.tenders.bids.store', $tender['id']) }}" enctype="multipart/form-data" class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            @csrf
                            <input type="hidden" name="form" value="{{ $prefix }}">
                            <input type="hidden" name="client_uuid" value="{{ $uuid($prefix) }}">

                            @include('modules.contractor_control.tenders._quote-who', ['prefix' => $prefix, 'active' => $active, 'class' => 'sm:col-span-2'])

                            <div class="sm:col-span-2">
                                <label class="label" for="{{ $prefix }}-rate">{{ __('Rate for this line (₹ per :unit)', ['unit' => $line['unit']]) }}</label>
                                <input id="{{ $prefix }}-rate" name="rates[{{ $thisLine['id'] }}]" type="number" inputmode="decimal" step="0.01" min="0" value="{{ $active ? old('rates.'.$thisLine['id']) : '' }}" class="input num" required>
                                @if ($active)
                                    @error('rates')<p class="error">{{ $message }}</p>@enderror
                                    @error('rates.'.$thisLine['id'])<p class="error">{{ $message }}</p>@enderror
                                    @error('quoted_amount')<p class="error">{{ $message }}</p>@enderror
                                @endif
                            </div>

                            @if ($otherLines->isNotEmpty())
                                <fieldset class="sm:col-span-2 lg:col-span-4">
                                    <legend class="label">{{ __('Rates for the other lines in this tender') }}</legend>
                                    <p class="hint">{{ __('A quote covers every line of its tender.') }}</p>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($otherLines as $other)
                                            <label class="flex items-center justify-between gap-3 text-sm">
                                                <span class="min-w-0 flex-1"><span class="num text-xs text-ink-3">{{ $other['code'] }}</span> {{ Str::limit($other['description'], 40) }}</span>
                                                <input name="rates[{{ $other['id'] }}]" type="number" inputmode="decimal" step="0.01" min="0" value="{{ $active ? old('rates.'.$other['id']) : '' }}" class="input num w-28 text-right" aria-label="{{ __('Rate') }} {{ $other['code'] }}" required>
                                            </label>
                                            @if ($active)
                                                @error('rates.'.$other['id'])<p class="error">{{ $message }}</p>@enderror
                                            @endif
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endif

                            @include('modules.contractor_control.tenders._quote-offer', ['prefix' => $prefix, 'active' => $active])

                            <div class="sm:col-span-2 lg:col-span-4"><button class="btn btn-primary">{{ __('Add quote') }}</button></div>
                        </form>
                    </div>
                @endif
            </section>
        @empty
            <div class="card"><x-empty :title="__('No tenders yet')" :note="__('This BOQ line has not gone out for quotes yet.')" /></div>
        @endforelse
    </div>

    @includeWhen($canQuote, 'modules.contractor_control.tenders._contractor-popup')
    </div>
@endsection
