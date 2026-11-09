@extends('layouts.app')
@section('title', __('Reconciliation'))
@section('content')
    @php
        $statusLabels = [
            'ok' => __('Within norm'),
            'wastage' => __('Wastage'),
            'under' => __('Under norm'),
            'no_work' => __('Issued, no work yet'),
            'no_norm' => __('No norm set'),
        ];
        $statusTones = ['ok' => 'text-moss', 'wastage' => 'text-laterite', 'under' => 'text-neel', 'no_work' => 'text-ink-3', 'no_norm' => 'text-ink-3'];
    @endphp
    <x-page-header :title="__('Reconciliation')" :subtitle="$project->name">
        <form method="GET" class="flex items-center gap-2">
            <label for="basis" class="text-sm text-ink-3">{{ __('Work done from') }}</label>
            <select id="basis" name="basis" class="input w-auto" onchange="this.form.submit()">
                <option value="measured" @selected($basis === 'measured')>{{ __('Measurement book') }}</option>
                <option value="approved" @selected($basis === 'approved')>{{ __('Approved measurements') }}</option>
                <option value="dpr" @selected($basis === 'dpr')>{{ __('Daily reports') }}</option>
            </select>
        </form>
    </x-page-header>

    <div class="space-y-5">
        @if ($wastage !== [])
            <section class="card border-laterite">
                <div class="card-head"><h2 class="card-title text-laterite">{{ __('Wastage alerts') }}</h2><span class="num text-sm">{{ count($wastage) }}</span></div>
                @foreach ($wastage as $row)
                    <p class="border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                        <span class="font-semibold">{{ $row['material_name'] }}</span> · {{ $row['boq_code'] }} ·
                        {{ __(':variance% over BOQ, :allowed% allowed', ['variance' => $row['variance_pct'], 'allowed' => $row['wastage_pct']]) }}
                    </p>
                @endforeach
            </section>
        @endif

        @if ($belowReorder->isNotEmpty())
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Below reorder level') }}</h2><span class="num text-sm">{{ $belowReorder->count() }}</span></div>
                @foreach ($belowReorder as $row)
                    <p class="border-b border-rule px-4 py-2.5 text-sm last:border-b-0">{{ $row['material']->name }} · {{ $row['store']->name }} · <span class="num">{{ fmt_qty($row['balance']) }} / {{ fmt_qty($row['reorder_level']) }} {{ $row['material']->baseUnit->code }}</span></p>
                @endforeach
            </section>
        @endif

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Consumption against BOQ') }}</h2></div>
            @if ($rows === [])
                <x-empty :title="__('Nothing to reconcile yet')" :note="__('Set norms below, then issue material against BOQ items.')" />
            @else
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[900px]">
                        <thead><tr><th>{{ __('BOQ item') }}</th><th class="r">{{ __('Work done') }}</th><th>{{ __('Material') }}</th><th class="r">{{ __('Theoretical') }}</th><th class="r">{{ __('Allowed') }}</th><th class="r">{{ __('Actual') }}</th><th class="r">{{ __('Variance') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><span class="num text-xs">{{ $row['boq_code'] }}</span><span class="block text-xs text-ink-3">{{ \Illuminate\Support\Str::limit($row['boq_description'], 50) }}</span></td>
                                <td class="r">{{ fmt_qty($row['executed']) }} {{ $row['boq_unit'] }}</td>
                                <td>{{ $row['material_name'] }}</td>
                                <td class="r">{{ $row['theoretical'] === null ? '—' : fmt_qty($row['theoretical']) }}</td>
                                <td class="r">{{ $row['allowed'] === null ? '—' : fmt_qty($row['allowed']) }}</td>
                                <td class="r">{{ fmt_qty($row['actual']) }} {{ $row['unit'] }}</td>
                                <td class="r">{{ $row['variance'] === null ? '—' : fmt_qty($row['variance']) }}@if ($row['variance_pct'] !== null)<span class="block text-[11px] text-ink-3">{{ $row['variance_pct'] }}%</span>@endif</td>
                                <td class="{{ $statusTones[$row['status']] }} text-sm font-medium">{{ $statusLabels[$row['status']] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if ($unallocated->isNotEmpty())
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Issued to activities without a BOQ item') }}</h2></div>
                @foreach ($unallocated as $row)
                    <p class="border-b border-rule px-4 py-2.5 text-sm last:border-b-0">{{ $row['material']->name }} · {{ $row['activity'] }} · <span class="num">{{ fmt_qty($row['actual']) }} {{ $row['material']->baseUnit->code }}</span></p>
                @endforeach
            </section>
        @endif

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Material norms') }}</h2></div>
            @if ($norms->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[640px]">
                        <thead><tr><th>{{ __('BOQ item') }}</th><th>{{ __('Material') }}</th><th class="r">{{ __('Per unit of work') }}</th><th class="r">{{ __('Wastage allowed') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($norms as $norm)
                            <tr>
                                <td><span class="num text-xs">{{ $norm->boqItem->code }}</span></td>
                                <td>{{ $norm->material->name }}</td>
                                <td class="r">{{ rtrim(rtrim($norm->qty_per_unit, '0'), '.') }} {{ $norm->material->baseUnit->code }} / {{ $norm->boqItem->unit }}</td>
                                <td class="r">{{ $norm->wastage_pct }}%</td>
                                <td class="text-right">
                                    @can('procurement.masters')
                                        <form method="POST" action="{{ route('procurement.reconciliation.norms.destroy', $norm) }}">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @can('procurement.masters')
                <form method="POST" action="{{ route('procurement.reconciliation.norms.store', $project) }}" class="grid items-end gap-3 border-t border-rule p-4 sm:grid-cols-5">
                    @csrf
                    <x-field :label="__('BOQ item')" name="boq_item_id" class="sm:col-span-2">
                        <select name="boq_item_id" class="input" required>
                            <option value="">{{ __('Choose') }}</option>
                            @foreach ($boqItems as $boq)<option value="{{ $boq->id }}">{{ $boq->code }} · {{ \Illuminate\Support\Str::limit($boq->description, 50) }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Material')" name="material_id">
                        <select name="material_id" class="input" required>
                            <option value="">{{ __('Choose') }}</option>
                            @foreach ($materialOptions as $option)<option value="{{ $option['id'] }}">{{ $option['label'] }} ({{ $option['base_unit'] }})</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Per unit of work')" name="qty_per_unit"><input name="qty_per_unit" type="number" step="0.000001" min="0.000001" class="input num" required></x-field>
                    <x-field :label="__('Wastage allowed %')" name="wastage_pct"><input name="wastage_pct" type="number" step="0.01" min="0" max="100" value="0" class="input num"></x-field>
                    <div class="sm:col-span-5"><button class="btn btn-ghost">{{ __('Save norm') }}</button></div>
                </form>
            @endcan
        </section>
    </div>
@endsection
