@extends('layouts.app')
@section('title', $tender->number)
@section('content')
    @php
        use App\Services\ContractorControl\Figures;
        use App\Support\Dec;
        $current = collect($comparison['bids'])->where('current', true);
        $canQuote = $tender->isOpen() && auth()->user()->can('contractor.tender');

        // A BOQ line's page sends its quote here to be chosen; otherwise the lowest starts chosen.
        $asked = old('bid_id', request()->query('bid'));
        $chosen = $current->first(fn (array $row) => is_scalar($asked) && (string) $row['bid']->id === (string) $asked) ?? $current->firstWhere('lowest', true);
    @endphp

    <div @if ($canQuote) x-data="contractorPopup()" @endif>
    <div @if ($canQuote) :inert="open" @endif>
    <x-page-header :title="$tender->title" :subtitle="$tender->number.' · '.$project->name">
        @include('modules.contractor_control.tenders._status', ['status' => $tender->status])
    </x-page-header>

    @if ($tender->scope || $tender->due_on)
        <div class="card mb-5 px-4 py-3 text-sm">
            @if ($tender->due_on)<p class="text-xs text-ink-3">{{ __('Quotes needed by') }} <span class="num">{{ $tender->due_on->format('d M Y') }}</span></p>@endif
            @if ($tender->scope)<p class="mt-1 whitespace-pre-line">{{ $tender->scope }}</p>@endif
        </div>
    @endif

    @if ($tender->workOrder)
        <div class="card mb-5 flex flex-wrap items-center justify-between gap-2 px-4 py-3">
            <p class="text-sm">{{ __('Awarded as') }} <a href="{{ route('contractor-control.contracts.show', $tender->workOrder) }}" class="link num">{{ $tender->workOrder->number }}</a>@if ($tender->awarded_at) · <span class="num text-xs text-ink-3">{{ $tender->awarded_at->format('d M Y') }}</span>@endif</p>
            @if ($tender->award_note)<p class="text-xs text-ink-3">{{ $tender->award_note }}</p>@endif
        </div>
    @endif

    <section class="card">
        <div class="card-head flex-wrap">
            <h2 class="card-title">{{ __('Quotes against the BOQ') }}</h2>
            <p class="text-sm">{{ __('BOQ amount') }} <span class="num font-semibold">{{ inr($comparison['boq_amount']) }}</span></p>
        </div>
        @forelse ($comparison['bids'] as $row)
            <div class="border-b border-rule px-4 py-3 last:border-b-0 {{ $row['lowest'] ? 'bg-sage/40' : '' }} {{ $row['current'] ? '' : 'opacity-60' }}">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <p class="font-semibold">{{ $row['contractor'] }}</p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($row['selected'])<span class="stamp border-moss bg-moss text-white">{{ __('Selected') }}</span>@endif
                        @if ($row['lowest'])<span class="stamp border-moss text-moss">{{ __('Lowest') }}</span>@endif
                        @unless ($row['current'])<span class="stamp border-ink-3 text-ink-3">{{ __('Replaced') }}</span>@endunless
                    </div>
                </div>
                <dl class="mt-2 grid grid-cols-3 gap-x-3 text-sm">
                    <div><dt class="text-xs text-ink-3">{{ __('Quotation') }}</dt><dd class="num font-medium">{{ inr($row['amount']) }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('vs BOQ (₹)') }}</dt><dd class="num {{ Dec::of($row['diff'])->isPositive() ? 'text-laterite' : 'text-moss' }}">{{ Figures::signedInr($row['diff']) }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('vs BOQ (%)') }}</dt><dd class="num {{ Dec::of($row['diff'])->isPositive() ? 'text-laterite' : 'text-moss' }}">{{ $row['diff_pct'] === null ? '—' : Figures::signedPct($row['diff_pct']).'%' }}</dd></div>
                </dl>
                <details class="mt-1.5">
                    <summary class="link cursor-pointer text-xs">{{ __('Rates') }}</summary>
                    <div class="mt-1 space-y-0.5">
                        @foreach ($row['bid']->items as $line)
                            <p class="num text-xs text-ink-3">{{ $line->tenderItem->boqItem->code }} · {{ fmt_qty($line->tenderItem->qty) }} {{ $line->tenderItem->boqItem->unit }} × {{ inr($line->rate) }} = {{ inr($line->amount) }}</p>
                        @endforeach
                    </div>
                </details>
                @include('modules.contractor_control.tenders._offer', [
                    'receivedOn' => $row['bid']->received_on,
                    'validUntil' => $row['bid']->valid_until,
                    'includesMaterial' => $row['bid']->includes_material,
                    'completionDays' => $row['bid']->completion_days,
                    'enteredBy' => $row['bid']->enteredBy?->name,
                    'paymentTerms' => $row['bid']->payment_terms,
                    'remarks' => $row['bid']->remarks,
                ])
                @if ($row['bid']->quotation_path)
                    <p class="mt-1.5"><a href="{{ route('contractor-control.tenders.bids.quotation', $row['bid']) }}" target="_blank" rel="noopener" class="link text-sm">{{ __('Open the quotation') }}</a></p>
                @endif
            </div>
        @empty
            <x-empty :title="__('No quotes yet')" :note="__('Add each contractor\'s quote below.')" />
        @endforelse
    </section>

    @if ($tender->isOpen())
        @if ($canQuote)
            <section class="card mt-5">
                <div class="card-head"><h2 class="card-title">{{ __('Add a quote') }}</h2></div>
                <form method="POST" action="{{ route('contractor-control.tenders.bids.store', $tender) }}" enctype="multipart/form-data" class="grid gap-4 p-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
                    @include('modules.contractor_control.tenders._quote-who', ['prefix' => 'quote', 'active' => true, 'class' => 'sm:col-span-2'])
                    <x-field :label="__('Quoted amount (₹)')" name="quoted_amount" :hint="$tender->items->count() > 1 ? __('One figure for all items, or rates below.') : null" class="sm:col-span-2"><input id="quoted_amount" name="quoted_amount" type="number" inputmode="decimal" step="0.01" min="0.01" value="{{ old('quoted_amount') }}" class="input num"></x-field>
                    @if ($tender->items->count() > 1)
                        <details class="sm:col-span-2" @if ($errors->has('rates') || old('rates')) open @endif>
                            <summary class="link cursor-pointer text-sm">{{ __('Enter a rate for each item') }}</summary>
                            @error('rates')<p class="error">{{ $message }}</p>@enderror
                            <div class="mt-2 space-y-2">
                                @foreach ($tender->items as $item)
                                    <label class="flex items-center justify-between gap-3 text-sm"><span class="min-w-0 flex-1"><span class="num text-xs text-ink-3">{{ $item->boqItem->code }}</span> {{ \Illuminate\Support\Str::limit($item->boqItem->description, 40) }}</span>
                                        <input name="rates[{{ $item->id }}]" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old("rates.{$item->id}") }}" class="input num w-28 text-right" aria-label="{{ __('Rate') }} {{ $item->boqItem->code }}"></label>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    @include('modules.contractor_control.tenders._quote-offer', ['prefix' => 'quote', 'active' => true])
                    <div class="sm:col-span-2"><button class="btn btn-primary">{{ __('Add quote') }}</button></div>
                </form>
            </section>
        @endif

        @can('contractor.award')
            @if ($current->isNotEmpty())
                <section id="award" class="card mt-5 scroll-mt-4">
                    <div class="card-head"><h2 class="card-title">{{ __('Select the winner') }}</h2></div>
                    <form method="POST" action="{{ route('contractor-control.tenders.award', $tender) }}" class="grid gap-4 p-4 sm:grid-cols-6">
                        @csrf
                        <x-field :label="__('Quotation')" name="bid_id" class="sm:col-span-6">
                            <select id="bid_id" name="bid_id" class="input" required>
                                @foreach ($current as $row)
                                    <option value="{{ $row['bid']->id }}" @selected($chosen && $chosen['bid']->id === $row['bid']->id)>{{ $row['contractor'] }} · {{ inr($row['amount']) }}@if ($row['lowest']) ({{ __('Lowest') }})@endif</option>
                                @endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Start date')" name="start_date" class="sm:col-span-2"><input id="start_date" name="start_date" type="date" value="{{ old('start_date') }}" class="input"></x-field>
                        <x-field :label="__('Finish by')" name="end_date" class="sm:col-span-2"><input id="end_date" name="end_date" type="date" value="{{ old('end_date') }}" class="input"></x-field>
                        <x-field :label="__('Note')" name="award_note" class="sm:col-span-2"><input id="award_note" name="award_note" value="{{ old('award_note') }}" maxlength="500" class="input"></x-field>
                        <x-field :label="__('Retention %')" name="retention_pct" class="sm:col-span-2"><input id="retention_pct" name="retention_pct" type="number" step="0.01" value="{{ old('retention_pct', 5) }}" class="input num" required></x-field>
                        <x-field :label="__('TDS %')" name="tds_pct" class="sm:col-span-2"><input id="tds_pct" name="tds_pct" type="number" step="0.01" value="{{ old('tds_pct', 1) }}" class="input num" required></x-field>
                        <x-field :label="__('GST %')" name="gst_pct" class="sm:col-span-2"><input id="gst_pct" name="gst_pct" type="number" step="0.01" value="{{ old('gst_pct', 18) }}" class="input num" required></x-field>
                        <x-field :label="__('Advance (₹)')" name="advance_amount" class="sm:col-span-2"><input id="advance_amount" name="advance_amount" type="number" step="0.01" value="{{ old('advance_amount', 0) }}" class="input num" required></x-field>
                        <x-field :label="__('Advance recovery %')" name="advance_recovery_pct" class="sm:col-span-2"><input id="advance_recovery_pct" name="advance_recovery_pct" type="number" step="0.01" value="{{ old('advance_recovery_pct', 0) }}" class="input num" required></x-field>
                        <x-field :label="__('Deductions on')" name="deduction_basis" class="sm:col-span-2">
                            <select id="deduction_basis" name="deduction_basis" class="input">
                                <option value="gross">{{ __('Gross') }}</option>
                                <option value="running">{{ __('Balance') }}</option>
                            </select>
                        </x-field>
                        <x-field :label="__('Deduction order')" name="deduction_order" class="sm:col-span-6">
                            <select id="deduction_order" name="deduction_order" class="input">
                                @foreach (config('buildos.deduction_orders') as $value => $label)
                                    <option value="{{ $value }}" @selected(old('deduction_order') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-field>
                        <div class="sm:col-span-6"><button class="btn btn-primary">{{ __('Create work order at these rates') }}</button></div>
                    </form>
                </section>
            @endif
        @endcan

        @can('contractor.tender')
            <form method="POST" action="{{ route('contractor-control.tenders.cancel', $tender) }}" class="mt-4" onsubmit="return confirm(@js(__('Cancel this tender? The quotes stay on record.')))">
                @csrf
                <button class="btn btn-ghost">{{ __('Cancel tender') }}</button>
            </form>
        @endcan
    @endif
    </div>

    @includeWhen($canQuote, 'modules.contractor_control.tenders._contractor-popup')
    </div>
@endsection
