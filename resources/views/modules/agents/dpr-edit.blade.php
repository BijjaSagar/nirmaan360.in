@extends('layouts.app')
@section('title', __('Edit draft DPR'))
@section('content')
    <x-page-header :title="__('Edit draft DPR')" :subtitle="$project->name" />

    @include('modules.agents._connection')

    @php
        $manpower = old('manpower', $dpr->manpower->map(fn ($row) => ['trade' => $row->trade, 'count' => $row->count])->values()->all());
        $machinery = old('machinery', $dpr->machinery->map(fn ($row) => ['name' => $row->name, 'hours' => $row->hours])->values()->all());
        $work = old('work', $dpr->workItems->map(fn ($row) => ['boq_item_id' => $row->boq_item_id, 'description' => $row->description, 'qty' => $row->qty])->values()->all());
    @endphp

    <form method="POST" action="{{ route('agents.drafts.dpr.update', $dpr) }}" class="max-w-4xl space-y-5">
        @csrf @method('PATCH')

        <section class="card">
            <div class="grid gap-4 p-4 sm:grid-cols-3">
                <x-field :label="__('Report date')" name="report_date">
                    <input id="report_date" name="report_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('report_date', $dpr->report_date->format('Y-m-d')) }}" class="input" required>
                </x-field>
                <x-field :label="__('Site')" name="site_id">
                    <select id="site_id" name="site_id" class="input">
                        <option value="">—</option>
                        @foreach ($sites as $site)<option value="{{ $site->id }}" @selected(old('site_id', $dpr->site_id) == $site->id)>{{ $site->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Weather')" name="weather"><input id="weather" name="weather" value="{{ old('weather', $dpr->weather) }}" class="input"></x-field>
                <x-field :label="__('Summary of work')" name="summary" class="sm:col-span-3"><textarea id="summary" name="summary" rows="2" class="input">{{ old('summary', $dpr->summary) }}</textarea></x-field>
                <x-field :label="__('Hindrances')" name="hindrances" class="sm:col-span-3"><textarea id="hindrances" name="hindrances" rows="2" class="input">{{ old('hindrances', $dpr->hindrances) }}</textarea></x-field>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Manpower') }}</h2></div>
            <div class="space-y-3 p-4">
                @foreach ([...$manpower, ...array_fill(0, 3, ['trade' => '', 'count' => ''])] as $index => $row)
                    <div class="grid grid-cols-[3fr_1fr] gap-3">
                        <input name="manpower[{{ $index }}][trade]" value="{{ $row['trade'] }}" class="input" placeholder="{{ __('Trade') }}" aria-label="{{ __('Trade') }}" list="trades-list">
                        <input name="manpower[{{ $index }}][count]" type="number" min="0" inputmode="numeric" value="{{ $row['count'] }}" class="input num" placeholder="{{ __('Count') }}" aria-label="{{ __('Count') }}">
                    </div>
                @endforeach
                <datalist id="trades-list">@foreach (config('buildos.trades') as $trade)<option value="{{ $trade }}">@endforeach</datalist>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Work done') }}</h2></div>
            <div class="space-y-3 p-4">
                @foreach ([...$work, ...array_fill(0, 3, ['boq_item_id' => '', 'description' => '', 'qty' => ''])] as $index => $row)
                    <div class="grid gap-3 sm:grid-cols-[2fr_2fr_1fr]">
                        <select name="work[{{ $index }}][boq_item_id]" class="input" aria-label="{{ __('BOQ item') }}">
                            <option value="">{{ __('BOQ item') }}</option>
                            @foreach ($boqItems as $item)
                                <option value="{{ $item->id }}" @selected($row['boq_item_id'] == $item->id)>{{ $item->code }} · {{ \Illuminate\Support\Str::limit($item->description, 60) }}</option>
                            @endforeach
                        </select>
                        <input name="work[{{ $index }}][description]" value="{{ $row['description'] }}" class="input" placeholder="{{ __('Description') }}" aria-label="{{ __('Description') }}">
                        <input name="work[{{ $index }}][qty]" type="number" step="0.001" min="0" value="{{ $row['qty'] }}" class="input num" placeholder="{{ __('Quantity') }}" aria-label="{{ __('Quantity') }}">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Machinery') }}</h2></div>
            <div class="space-y-3 p-4">
                @foreach ([...$machinery, ...array_fill(0, 2, ['name' => '', 'hours' => ''])] as $index => $row)
                    <div class="grid grid-cols-[3fr_1fr] gap-3">
                        <input name="machinery[{{ $index }}][name]" value="{{ $row['name'] }}" class="input" placeholder="{{ __('Machine') }}" aria-label="{{ __('Machine') }}">
                        <input name="machinery[{{ $index }}][hours]" type="number" step="0.5" min="0" max="24" value="{{ $row['hours'] }}" class="input num" placeholder="{{ __('Hours') }}" aria-label="{{ __('Hours') }}">
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <button class="btn btn-primary">{{ __('Save draft') }}</button>
            <a href="{{ route('dprs.show', $dpr) }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
