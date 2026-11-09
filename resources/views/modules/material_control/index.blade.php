@extends('layouts.app')
@section('title', __('Material control'))
@section('content')
    @php
        $flagLabels = [
            'over_used' => __('Used more than BOQ'),
            'under_used' => __('Used less than BOQ'),
            'unaccounted' => __('Unaccounted'),
            'over_received' => __('Received above BOQ'),
            'short_delivery' => __('Short delivery'),
        ];
        $money = auth()->user()->can('money.view');
    @endphp

    <x-page-header :title="__('Material control')" :subtitle="$project->name" />

    @include('modules.material_control.partials.tabs')

    @canany(['material_control.challan', 'material_control.consume', 'material_control.count'])
    <div class="mb-4 grid grid-cols-3 gap-2">
        @can('material_control.challan')
            <a href="{{ route('material_control.challans.create', $project) }}" class="btn btn-primary min-h-14 flex-col gap-0 text-center text-sm">{{ __('Delivery challan') }}</a>
        @endcan
        @can('material_control.consume')
            <a href="{{ route('material_control.consumption.index', $project) }}" class="btn btn-ghost min-h-14 text-center text-sm">{{ __('Use material') }}</a>
        @endcan
        @can('material_control.count')
            <a href="{{ route('material_control.stock.index', $project) }}" class="btn btn-ghost min-h-14 text-center text-sm">{{ __('Stock check') }}</a>
        @endcan
    </div>
    @endcanany

    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card p-3">
            <p class="eyebrow text-[10px] text-ink-3">{{ __('Project wastage') }}</p>
            <p class="num text-xl font-medium {{ $check['wastage_pct'] !== null && \App\Support\Dec::of($check['wastage_pct'])->isGreaterThan(2) ? 'text-laterite' : '' }}">{{ $check['wastage_pct'] !== null ? $check['wastage_pct'].'%' : '—' }}</p>
            <p class="hint">{{ __('Target 1–2%') }}@if ($check['wastage_basis'] === 'quantity') · {{ __('By quantity, until rates are known.') }}@endif</p>
        </div>
        <div class="card p-3">
            <p class="eyebrow text-[10px] text-ink-3">{{ __('Flags to answer') }}</p>
            <p class="num text-xl font-medium {{ $check['open_flags'] > 0 ? 'text-laterite' : 'text-moss' }}">{{ $check['open_flags'] }}</p>
        </div>
        @if ($billsPending !== null)
            <a href="{{ route('material_control.bills.index', $project) }}" class="card block p-3">
                <p class="eyebrow text-[10px] text-ink-3">{{ __('Bills pending') }}</p>
                <p class="num text-xl font-medium">{{ $billsPending }}</p>
            </a>
        @endif
        @if ($check['wastage_basis'] === 'value' && $check['left_out'] > 0)
            <p class="hint col-span-2 self-center sm:col-span-1">{{ __(':count materials have no known rate and are left out of the project figure.', ['count' => $check['left_out']]) }}</p>
        @endif
    </div>

    @if ($check['rows'] === [])
        <div class="card"><x-empty :title="__('Nothing to check yet')" :note="__('Add material norms to the BOQ and enter deliveries and use, and the check will appear here.')" /></div>
    @else
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($check['rows'] as $row)
                <section class="card">
                    <div class="card-head">
                        <h2 class="card-title min-w-0 truncate">{{ $row['material'] }}</h2>
                        @if ($row['open_flag_count'] > 0)
                            <span class="stamp border-laterite text-laterite">{{ __(':count to answer', ['count' => $row['open_flag_count']]) }}</span>
                        @endif
                    </div>
                    <dl class="grid grid-cols-3 gap-px bg-rule text-center">
                        @foreach ([[__('BOQ for work done'), $row['estimate']], [__('Received'), $row['received']], [__('Used'), $row['used']]] as [$label, $value])
                            <div class="bg-sheet px-1 py-2.5">
                                <dt class="text-[11px] leading-tight text-ink-3">{{ $label }}</dt>
                                <dd class="num mt-0.5 text-[15px] font-medium break-words">{{ fmt_qty($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <dl class="grid grid-cols-3 gap-px border-b border-rule bg-rule text-center">
                        <div class="bg-sheet px-1 py-2.5">
                            <dt class="text-[11px] leading-tight text-ink-3">{{ __('Stock on site') }}</dt>
                            <dd class="num mt-0.5 text-[15px] font-medium break-words">{{ fmt_qty($row['stock_on_site']) }}</dd>
                        </div>
                        <div class="bg-sheet px-1 py-2.5">
                            <dt class="text-[11px] leading-tight text-ink-3">{{ __('Unaccounted') }}</dt>
                            <dd class="num mt-0.5 text-[15px] font-medium break-words {{ \App\Support\Dec::of($row['unaccounted'])->isZero() ? '' : 'text-laterite' }}">{{ fmt_qty($row['unaccounted']) }}</dd>
                        </div>
                        <div class="bg-sheet px-1 py-2.5">
                            <dt class="text-[11px] leading-tight text-ink-3">{{ __('Wastage') }}</dt>
                            <dd class="num mt-0.5 text-[15px] font-medium">{{ $row['wastage_pct'] !== null ? $row['wastage_pct'].'%' : '—' }}</dd>
                        </div>
                    </dl>
                    <p class="px-4 py-2 text-xs text-ink-3">
                        {{ $row['unit'] }}
                        @if ($row['last_counted'])· {{ __('Last counted :date', ['date' => \Illuminate\Support\Carbon::parse($row['last_counted'])->format('d M Y')]) }}@else· {{ __('Not counted yet') }}@endif
                        @if (\App\Support\Dec::of($row['extra_work'])->isPositive())· {{ __('Extra work :qty', ['qty' => fmt_qty($row['extra_work'])]) }}@endif
                        @if ($money && ($row['wastage_value'] ?? null) !== null)· {{ __('Wastage value :amount', ['amount' => inr($row['wastage_value'])]) }}@endif
                    </p>

                    @foreach ($row['flags'] as $flag)
                        <div class="border-t border-rule px-4 py-3 {{ $flag['open'] ? 'bg-laterite/5' : '' }}">
                            <p class="text-xs font-semibold {{ $flag['open'] ? 'text-laterite' : 'text-moss' }}">{{ $flagLabels[$flag['type']] }}</p>
                            <p class="mt-0.5 text-sm">{{ $flag['message'] }}</p>

                            @if ($flag['open'])
                                @can('material_control.reason')
                                    <form method="POST" action="{{ route('material_control.reasons.store', $project) }}" class="mt-2 space-y-2">
                                        @csrf
                                        <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <input type="hidden" name="material_id" value="{{ $row['material_id'] }}">
                                        <input type="hidden" name="flag_type" value="{{ $flag['type'] }}">
                                        <label class="label" for="reason-{{ $row['material_id'] }}-{{ $flag['type'] }}">{{ __('Reason') }}</label>
                                        <textarea id="reason-{{ $row['material_id'] }}-{{ $flag['type'] }}" name="reason" rows="2" class="input text-base" maxlength="2000" required></textarea>
                                        <button class="btn btn-ink min-h-11 w-full text-base sm:w-auto">{{ __('Save reason') }}</button>
                                    </form>
                                @else
                                    <p class="mt-1 text-xs text-ink-3">{{ __('Needs a written reason.') }}</p>
                                @endcan
                            @else
                                <p class="mt-1 text-sm text-ink-2">{{ $flag['reason'] }}</p>
                                <p class="hint">{{ $flag['reason_by'] }} · {{ $flag['reason_at'] ? \Illuminate\Support\Carbon::parse($flag['reason_at'])->format('d M Y') : '' }}</p>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif
@endsection
