@extends('layouts.app')
@section('title', __('New DPR'))
@section('content')
    <x-page-header :title="__('New DPR')" :subtitle="$project->name" />

    <form method="POST" action="{{ route('dprs.store', $project) }}" enctype="multipart/form-data" class="max-w-4xl space-y-5">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">

        <section class="card">
            <div class="grid gap-4 p-4 sm:grid-cols-3">
                <x-field :label="__('Report date')" name="report_date">
                    <input id="report_date" name="report_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('report_date', now()->format('Y-m-d')) }}" class="input" required>
                </x-field>
                <x-field :label="__('Site')" name="site_id">
                    <select id="site_id" name="site_id" class="input">
                        <option value="">—</option>
                        @foreach ($sites as $site)<option value="{{ $site->id }}" @selected(old('site_id') == $site->id)>{{ $site->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Weather')" name="weather">
                    <input id="weather" name="weather" value="{{ old('weather') }}" class="input" list="weather-list">
                    <datalist id="weather-list"><option value="{{ __('Clear') }}"><option value="{{ __('Cloudy') }}"><option value="{{ __('Rain') }}"></datalist>
                </x-field>
                <x-field :label="__('Summary of work')" name="summary" class="sm:col-span-3"><textarea id="summary" name="summary" rows="2" class="input">{{ old('summary') }}</textarea></x-field>
                <x-field :label="__('Hindrances')" name="hindrances" class="sm:col-span-3"><textarea id="hindrances" name="hindrances" rows="2" class="input">{{ old('hindrances') }}</textarea></x-field>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Manpower') }}</h2></div>
            <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5">
                @foreach (config('buildos.trades') as $index => $trade)
                    <div>
                        <input type="hidden" name="manpower[{{ $index }}][trade]" value="{{ $trade }}">
                        <label class="label" for="manpower-{{ $index }}">{{ __($trade) }}</label>
                        <input id="manpower-{{ $index }}" name="manpower[{{ $index }}][count]" type="number" min="0" inputmode="numeric" value="{{ old("manpower.{$index}.count") }}" class="input num">
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card" x-data="{ rows: [0] }">
            <div class="card-head">
                <h2 class="card-title">{{ __('Work done') }}</h2>
                <button type="button" class="btn btn-ghost" @click="rows.push(rows.length)">{{ __('Add row') }}</button>
            </div>
            <div class="space-y-3 p-4">
                <template x-for="row in rows" :key="row">
                    <div class="grid gap-3 sm:grid-cols-[2fr_2fr_1fr]">
                        <select :name="`work[${row}][boq_item_id]`" class="input" aria-label="{{ __('BOQ item') }}">
                            <option value="">{{ __('BOQ item') }}</option>
                            @foreach ($boqItems as $item)
                                <option value="{{ $item->id }}">{{ $item->code }} · {{ \Illuminate\Support\Str::limit($item->description, 60) }}</option>
                            @endforeach
                        </select>
                        <input :name="`work[${row}][description]`" class="input" placeholder="{{ __('Description') }}" aria-label="{{ __('Description') }}">
                        <input :name="`work[${row}][qty]`" type="number" step="0.001" min="0" class="input num" placeholder="{{ __('Quantity') }}" aria-label="{{ __('Quantity') }}">
                    </div>
                </template>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Machinery') }}</h2></div>
            <div class="space-y-3 p-4">
                @for ($row = 0; $row < 3; $row++)
                    <div class="grid gap-3 sm:grid-cols-[3fr_1fr]">
                        <input name="machinery[{{ $row }}][name]" value="{{ old("machinery.{$row}.name") }}" class="input" placeholder="{{ __('Machine') }}" aria-label="{{ __('Machine') }}">
                        <input name="machinery[{{ $row }}][hours]" type="number" step="0.5" min="0" max="24" value="{{ old("machinery.{$row}.hours") }}" class="input num" placeholder="{{ __('Hours') }}" aria-label="{{ __('Hours') }}">
                    </div>
                @endfor
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Photos') }}</h2></div>
            <div class="p-4">
                <x-field :label="__('Up to 10 site photos')" name="photos">
                    <input id="photos" name="photos[]" type="file" accept="image/*" multiple class="input">
                </x-field>
            </div>
        </section>

        <button class="btn btn-primary">{{ __('Save draft') }}</button>
    </form>
@endsection
