@extends('layouts.app')
@section('title', $challan->number)
@section('content')
    <x-page-header :title="$challan->number" :subtitle="$project->name">
        <a href="{{ route('material_control.challans.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('All challans') }}</a>
    </x-page-header>

    @if ($warning)
        <p class="mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite" role="alert">{{ $challan->supplier->name }}: {{ $warning }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="card p-4">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <x-stamp :status="$challan->status" />
                @if ($challan->status === 'POSTED')
                    @if ($challan->billPending())
                        <span class="stamp border-haldi text-[#8a6208]">{{ __('Bill pending') }}</span>
                    @else
                        <span class="stamp border-moss text-moss">{{ __('Bill attached') }}</span>
                    @endif
                @endif
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-xs text-ink-3">{{ __('Supplier') }}</dt><dd><a href="{{ route('material_control.suppliers.show', $challan->supplier) }}" class="link">{{ $challan->supplier->name }}</a></dd></div>
                <div><dt class="text-xs text-ink-3">{{ __('Material') }}</dt><dd>{{ $challan->material->name }}</dd></div>
                <div><dt class="text-xs text-ink-3">{{ __('Received') }}</dt><dd class="num font-medium">{{ fmt_qty($challan->qty) }} {{ $challan->unit->code }}</dd></div>
                @if ($challan->challan_qty !== null)
                    <div><dt class="text-xs text-ink-3">{{ __('On challan') }}</dt><dd class="num">{{ fmt_qty($challan->challan_qty) }} {{ $challan->unit->code }}</dd></div>
                @endif
                <div><dt class="text-xs text-ink-3">{{ __('Date') }}</dt><dd>{{ $challan->challan_date->format('d M Y') }}</dd></div>
                <div><dt class="text-xs text-ink-3">{{ __('Vehicle no.') }}</dt><dd>{{ $challan->vehicle_no ?? '—' }}</dd></div>
                <div><dt class="text-xs text-ink-3">{{ __('Challan no.') }}</dt><dd>{{ $challan->challan_no ?? '—' }}</dd></div>
                <div><dt class="text-xs text-ink-3">{{ __('Store') }}</dt><dd>{{ $challan->store->name }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-ink-3">{{ __('Entered by') }}</dt><dd>{{ $challan->creator?->name ?? '—' }} · {{ $challan->created_at->format('d M Y H:i') }}</dd></div>
            </dl>

            @if ($challan->photo_path)
                <a href="{{ route('material_control.challans.photo', $challan) }}" target="_blank" class="mt-3 block"><img src="{{ route('material_control.challans.photo', $challan) }}" alt="{{ __('Photo of challan') }}" class="max-h-72 w-full rounded-[3px] border border-rule object-contain"></a>
            @else
                <p class="hint mt-3">{{ __('No photo was attached.') }}</p>
            @endif

            @if ($challan->reversed_at)
                <p class="mt-3 text-sm text-laterite">{{ __('Reversed by :name on :date: :reason', ['name' => $challan->reverser?->name, 'date' => $challan->reversed_at->format('d M Y'), 'reason' => $challan->reversal_reason]) }}</p>
            @endif
        </section>

        <div class="space-y-4">
            @if ($challan->status === 'POSTED')
                <section class="card p-4">
                    <h2 class="card-title mb-2">{{ __('Bill') }}</h2>
                    @can('money.view')
                        @if ($challan->bill_no || $challan->bill_amount)
                            <dl class="mb-3 grid grid-cols-2 gap-2 text-sm">
                                <div><dt class="text-xs text-ink-3">{{ __('Bill no.') }}</dt><dd>{{ $challan->bill_no ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-ink-3">{{ __('Amount') }}</dt><dd class="num">{{ $challan->bill_amount ? inr($challan->bill_amount) : '—' }}</dd></div>
                            </dl>
                        @endif
                        @if ($challan->bill_path)
                            <a href="{{ route('material_control.challans.bill', $challan) }}" target="_blank" class="link text-sm">{{ __('Open bill') }}</a>
                        @endif
                    @else
                        <p class="text-sm text-ink-2">{{ $challan->billPending() ? __('Waiting for the bill.') : __('The bill has been attached.') }}</p>
                    @endcan

                    @can('material_control.bills')
                        <form method="POST" action="{{ route('material_control.challans.bill.store', $challan) }}" enctype="multipart/form-data" class="mt-3 grid gap-3 sm:grid-cols-2">
                            @csrf
                            @includeIf('modules.slip_reading._challan_bill', ['challan' => $challan])
                            <x-field :label="__('Bill file')" name="bill" class="sm:col-span-2">
                                <input id="bill" name="bill" type="file" accept="image/*,application/pdf" class="input min-h-12 text-base">
                            </x-field>
                            @can('money.view')
                                <x-field :label="__('Bill no.')" name="bill_no"><input id="bill_no" name="bill_no" value="{{ old('bill_no', $challan->bill_no) }}" maxlength="60" class="input min-h-12 text-base"></x-field>
                                <x-field :label="__('Bill date')" name="bill_date"><input id="bill_date" name="bill_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('bill_date', $challan->bill_date?->format('Y-m-d')) }}" class="input min-h-12 text-base"></x-field>
                                <x-field :label="__('Amount')" name="bill_amount"><input id="bill_amount" name="bill_amount" type="number" inputmode="decimal" step="0.01" min="0.01" value="{{ old('bill_amount', $challan->bill_amount) }}" class="input num min-h-12 text-base"></x-field>
                                @if ($vendorBills->isNotEmpty())
                                    <x-field :label="__('Match to vendor bill')" name="vendor_bill_id">
                                        <select id="vendor_bill_id" name="vendor_bill_id" class="input min-h-12 text-base">
                                            <option value="">{{ __('None') }}</option>
                                            @foreach ($vendorBills as $bill)<option value="{{ $bill->id }}" @selected((string) old('vendor_bill_id', $challan->vendor_bill_id) === (string) $bill->id)>{{ $bill->bill_no }} · {{ inr($bill->total_amount) }}</option>@endforeach
                                        </select>
                                    </x-field>
                                @endif
                            @endcan
                            <button class="btn btn-ink min-h-12 w-full text-base sm:col-span-2">{{ __('Attach bill') }}</button>
                        </form>
                    @endcan
                </section>

                @can('material_control.challan')
                    <section class="card p-4" x-data="{ open: false }">
                        <button type="button" class="flex min-h-11 w-full items-center justify-between font-semibold" @click="open = !open">{{ __('Delivered short?') }}<x-icon name="chevron" class="size-4" /></button>
                        <form x-show="open" x-cloak method="POST" action="{{ route('material_control.challans.short.store', $challan) }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                            @csrf
                            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <x-field :label="__('Short by (:unit)', ['unit' => $challan->unit->code])" name="short_qty"><input id="short_qty" name="short_qty" type="number" inputmode="decimal" step="0.001" min="0.001" class="input num min-h-12 text-base" required></x-field>
                            <x-field :label="__('Found on')" name="found_on"><input id="found_on" name="found_on" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ today()->format('Y-m-d') }}" class="input min-h-12 text-base" required></x-field>
                            <x-field :label="__('Note')" name="note" class="sm:col-span-2"><textarea id="note" name="note" rows="2" maxlength="500" class="input text-base"></textarea></x-field>
                            <p class="hint sm:col-span-2">{{ __('This takes the quantity out of stock and counts against the supplier.') }}</p>
                            <button class="btn btn-danger min-h-12 w-full text-base sm:col-span-2">{{ __('Record short delivery') }}</button>
                        </form>
                    </section>
                @endcan
            @endif

            @if ($challan->shortDeliveries->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Short deliveries') }}</h2></div>
                    @foreach ($challan->shortDeliveries as $short)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                            <span class="num">{{ fmt_qty($short->short_qty) }} {{ $challan->unit->code }} · {{ $short->found_on->format('d M Y') }}</span>
                            @if ($short->status === 'REVERSED')
                                <x-stamp :status="$short->status" />
                            @elseif (auth()->user()->can('material_control.adjust'))
                                <form method="POST" action="{{ route('material_control.shorts.reverse', $short) }}" class="flex gap-2" onsubmit="return confirm('{{ __('Reverse this short delivery?') }}')">
                                    @csrf
                                    <input type="hidden" name="reason" value="{{ __('Reported in error') }}">
                                    <button class="btn btn-ghost">{{ __('Reverse') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($challan->status === 'POSTED')
                @can('material_control.adjust')
                    <form method="POST" action="{{ route('material_control.challans.reverse', $challan) }}" class="card flex flex-wrap items-end gap-3 p-4">
                        @csrf
                        <x-field :label="__('Reverse this delivery')" name="reason" class="min-w-48 flex-1"><input id="reason" name="reason" class="input min-h-12 text-base" maxlength="255" placeholder="{{ __('Reason') }}" required></x-field>
                        <button class="btn btn-danger min-h-12">{{ __('Reverse') }}</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
@endsection
