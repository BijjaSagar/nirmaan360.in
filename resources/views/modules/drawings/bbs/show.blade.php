@extends('layouts.app')
@section('title', $schedule->number)
@section('content')
    @php
        $firstShape = old('shape_code', array_key_first($shapes));
        $dimensionNames = collect($shapes)->flatMap(fn ($shape) => [...$shape['dims'], ...($shape['optional'] ?? [])])->unique()->values();
        $barForm = fn ($bar) => [
            'url' => route('drawings.bbs.bars.update', $bar),
            'location' => $bar->location, 'mark' => $bar->mark, 'steel_type' => $bar->steel_type, 'dia_mm' => $bar->dia_mm,
            'sets' => $bar->sets, 'per_set' => $bar->per_set, 'shape_code' => $bar->shape_code, 'dims' => $bar->dims,
        ];
    @endphp
    <div x-data="{ details: false, shapes: @js(collect($shapes)->map(fn ($s) => ['dims' => $s['dims'], 'optional' => $s['optional'] ?? [], 'formula' => $s['formula'], 'condition' => isset($s['condition']) ? __($s['condition']) : null, 'sketch' => $s['sketch']])), form: { url: null, location: @js(old('location', '')), mark: @js(old('mark', '')), steel_type: @js(old('steel_type', 'deformed')), dia_mm: @js((int) old('dia_mm', 12)), sets: @js((int) old('sets', 1)), per_set: @js((int) old('per_set', 1)), shape_code: @js($firstShape), dims: @js((object) old('dims', [])) }, editing: false, reset() { this.editing = false; this.form = { url: null, location: '', mark: '', steel_type: 'deformed', dia_mm: 12, sets: 1, per_set: 1, shape_code: @js(array_key_first($shapes)), dims: {} }; }, edit(bar) { this.editing = true; this.form = { ...bar, dims: { ...bar.dims } }; window.scrollTo({ top: 0, behavior: 'smooth' }); }, get shape() { return this.shapes[this.form.shape_code]; }, shown(name) { return this.shape.dims.includes(name) || this.shape.optional.includes(name); } }">
        <x-page-header :title="$schedule->number" :subtitle="$schedule->title">
            <a href="{{ route('drawings.bbs.index', $project) }}" class="btn btn-ghost">{{ __('Back') }}</a>
            <a href="{{ route('drawings.bbs.export', $schedule) }}" class="btn btn-ink">{{ __('Export Excel') }}</a>
            @can('drawings.bbs')
                <button type="button" class="btn btn-ghost" @click="details = !details">{{ __('Edit details') }}</button>
                <form method="POST" action="{{ route('drawings.bbs.destroy', $schedule) }}" onsubmit="return confirm(@js(__('Remove this schedule?')))">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger">{{ __('Remove') }}</button>
                </form>
            @endcan
        </x-page-header>

        @can('drawings.bbs')
            <form x-show="details" x-cloak method="POST" action="{{ route('drawings.bbs.update', $schedule) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-3">
                @csrf @method('PATCH')
                <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title', $schedule->title) }}" maxlength="160" class="input" required></x-field>
                <x-field :label="__('Drawing')" name="drawing_id">
                    <select id="drawing_id" name="drawing_id" class="input">
                        <option value="">—</option>
                        @foreach ($drawings as $drawing)<option value="{{ $drawing->id }}" @selected((int) old('drawing_id', $schedule->drawing_id) === $drawing->id)>{{ $drawing->number }} · {{ $drawing->title }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-3"><textarea id="notes" name="notes" rows="2" maxlength="2000" class="input">{{ old('notes', $schedule->notes) }}</textarea></x-field>
                <div class="sm:col-span-3"><button class="btn btn-primary">{{ __('Save changes') }}</button></div>
            </form>
        @endcan

        <div class="mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="note">
            {{ __('Results are calculated from IS 2502:1963. An engineer must check every bar before cutting.') }}
            {{ __('Laps and development lengths are not added.') }}
        </div>

        @can('drawings.bbs')
            <form method="POST" :action="editing ? form.url : @js(route('drawings.bbs.bars.store', $schedule))" class="card mb-5">
                @csrf
                <template x-if="editing"><input type="hidden" name="_method" value="PATCH"></template>
                <template x-if="!editing"><input type="hidden" name="client_uuid" value="{{ $clientUuid }}"></template>
                <div class="card-head">
                    <h2 class="card-title" x-text="editing ? @js(__('Edit bar')) : @js(__('Add bar'))"></h2>
                    <button type="button" x-show="editing" x-cloak class="link text-xs" @click="reset()">{{ __('Cancel edit') }}</button>
                </div>
                <div class="grid gap-3 p-4 sm:grid-cols-6">
                    <x-field :label="__('Location')" name="location" class="sm:col-span-2"><input id="location" name="location" x-model="form.location" maxlength="120" class="input" placeholder="{{ __('Column C4') }}"></x-field>
                    <x-field :label="__('Mark')" name="mark"><input id="mark" name="mark" x-model="form.mark" maxlength="32" class="input" required></x-field>
                    <x-field :label="__('Steel')" name="steel_type" class="sm:col-span-2">
                        <select id="steel_type" name="steel_type" x-model="form.steel_type" class="input">
                            @foreach ($steels as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Size (mm)')" name="dia_mm">
                        <select id="dia_mm" name="dia_mm" x-model.number="form.dia_mm" class="input num">
                            @foreach ($sizes as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Number of sets')" name="sets"><input id="sets" name="sets" type="number" min="1" x-model.number="form.sets" class="input num" required></x-field>
                    <x-field :label="__('Number per set')" name="per_set"><input id="per_set" name="per_set" type="number" min="1" x-model.number="form.per_set" class="input num" required></x-field>
                    <x-field :label="__('Shape')" name="shape_code" class="sm:col-span-4">
                        <select id="shape_code" name="shape_code" x-model="form.shape_code" class="input">
                            @foreach ($shapes as $code => $shape)<option value="{{ $code }}">{{ $code }} · {{ __($shape['name']) }}</option>@endforeach
                        </select>
                    </x-field>
                    <div class="flex items-center gap-3 sm:col-span-3">
                        <svg viewBox="0 0 120 60" class="h-16 w-32 shrink-0 rounded-[3px] border border-rule bg-paper" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="shape.sketch"/></svg>
                        <div>
                            <p class="num text-sm" x-text="'L = ' + shape.formula"></p>
                            <p class="hint" x-show="shape.condition" x-text="shape.condition"></p>
                        </div>
                    </div>
                    @foreach ($dimensionNames as $name)
                        <div x-show="shown(@js($name))" x-cloak>
                            <label class="label" for="dim-{{ $name }}">{{ $name === 'N' ? __('N (turns)') : $name.' (mm)' }}{{ $name === 'R' ? ' · '.__('optional') : '' }}</label>
                            <input id="dim-{{ $name }}" name="dims[{{ $name }}]" type="number" step="0.001" min="0" x-model="form.dims[@js($name)]" :disabled="!shown(@js($name))" class="input num">
                            @error('dims.'.$name)<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div class="sm:col-span-6">
                        @error('dims')<p class="error mb-2">{{ $message }}</p>@enderror
                        <button class="btn btn-primary" x-text="editing ? @js(__('Save changes')) : @js(__('Add bar'))"></button>
                    </div>
                </div>
            </form>
        @endcan

        <div class="card mb-5 overflow-x-auto">
            @if ($schedule->bars->isEmpty())
                <x-empty :title="__('No bars yet')" :note="__('Add each bar with its shape and dimensions.')" />
            @else
                <table class="ledger min-w-[1040px]">
                    <thead><tr><th>{{ __('Location') }}</th><th>{{ __('Mark') }}</th><th>{{ __('Steel') }}</th><th class="r">{{ __('Size') }}</th><th class="r">{{ __('Sets') }}</th><th class="r">{{ __('Per set') }}</th><th class="r">{{ __('Total') }}</th><th>{{ __('Shape') }}</th><th>{{ __('Dimensions') }}</th><th class="r">{{ __('Cutting length (mm)') }}</th><th class="r">{{ __('Length (m)') }}</th><th class="r">{{ __('Weight (kg)') }}</th>@can('drawings.bbs')<th></th>@endcan</tr></thead>
                    <tbody>
                    @foreach ($schedule->bars as $bar)
                        <tr>
                            <td>{{ $bar->location ?: '—' }}</td>
                            <td class="num text-xs">{{ $bar->mark }}</td>
                            <td>{{ __($steels[$bar->steel_type] ?? $bar->steel_type) }}</td>
                            <td class="r">{{ $bar->dia_mm }}</td>
                            <td class="r">{{ $bar->sets }}</td>
                            <td class="r">{{ $bar->per_set }}</td>
                            <td class="r">{{ $bar->total_bars }}</td>
                            <td><span class="num text-xs">{{ $bar->shape_code }}</span><br><span class="text-xs text-ink-3">{{ __($shapes[$bar->shape_code]['name'] ?? '') }}</span></td>
                            <td class="num text-xs">{{ \App\Services\Drawings\BbsService::describeDims($bar->dims) }}</td>
                            <td class="r">
                                {{ \App\Support\Dec::indian($bar->cutting_length_mm, 0) }}
                                @if (\App\Support\Dec::of($bar->cutting_length_mm)->isGreaterThan(12000))<p class="text-[11px] font-medium text-laterite">{{ __('Over 12 m: add laps') }}</p>@endif
                            </td>
                            <td class="r">{{ fmt_qty($bar->total_length_m) }}</td>
                            <td class="r">{{ fmt_qty($bar->total_weight_kg) }}</td>
                            @can('drawings.bbs')
                                <td class="text-right whitespace-nowrap">
                                    <button type="button" class="link text-xs" @click="edit(@js($barForm($bar)))">{{ __('Edit') }}</button>
                                    <form method="POST" action="{{ route('drawings.bbs.bars.destroy', $bar) }}" class="inline">@csrf @method('DELETE')<button class="link ml-2 text-xs">{{ __('Remove') }}</button></form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @if ($summary['sizes'] !== [])
            <div class="card max-w-xl overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Steel by bar size') }}</h2></div>
                <table class="ledger min-w-[380px]">
                    <thead><tr><th class="r">{{ __('Size (mm)') }}</th><th class="r">{{ __('Bars') }}</th><th class="r">{{ __('Length (m)') }}</th><th class="r">{{ __('Weight (kg)') }}</th></tr></thead>
                    <tbody>
                    @foreach ($summary['sizes'] as $size)
                        <tr><td class="r">{{ $size['dia'] }}</td><td class="r">{{ $size['bars'] }}</td><td class="r">{{ fmt_qty($size['length_m']) }}</td><td class="r">{{ fmt_qty($size['weight_kg']) }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td colspan="3">{{ __('Total weight') }}</td><td class="r">{{ fmt_qty($summary['total_weight_kg']) }}</td></tr>
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
