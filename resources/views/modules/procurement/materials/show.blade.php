@extends('layouts.app')
@section('title', $material->name)
@section('content')
    <x-page-header :title="$material->name" :subtitle="$material->code">
        <a href="{{ route('procurement.materials.index') }}" class="btn btn-ghost">{{ __('All materials') }}</a>
    </x-page-header>

    <div class="grid items-start gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('procurement.materials.update', $material) }}" class="card space-y-3 p-4">
            @csrf @method('PATCH')
            <h2 class="card-title">{{ __('Details') }}</h2>
            <fieldset class="space-y-3" @cannot('procurement.masters') disabled @endcannot>
                <x-field :label="__('Code')" name="code"><input id="code" name="code" value="{{ old('code', $material->code) }}" class="input" maxlength="40" required></x-field>
                <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name', $material->name) }}" class="input" maxlength="160" required></x-field>
                <x-field :label="__('Category')" name="category"><input id="category" name="category" value="{{ old('category', $material->category) }}" class="input" maxlength="60"></x-field>
                <x-field :label="__('Base unit')" name="base_unit_id">
                    <select id="base_unit_id" name="base_unit_id" class="input" required>
                        @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected((string) old('base_unit_id', $material->base_unit_id) === (string) $unit->id)>{{ $unit->code }}</option>@endforeach
                    </select>
                </x-field>
                <div class="grid grid-cols-2 gap-3">
                    <x-field :label="__('HSN code')" name="hsn_code"><input id="hsn_code" name="hsn_code" value="{{ old('hsn_code', $material->hsn_code) }}" class="input" maxlength="12"></x-field>
                    <x-field :label="__('GST %')" name="gst_pct"><input id="gst_pct" name="gst_pct" type="number" step="0.01" min="0" max="100" value="{{ old('gst_pct', $material->gst_pct) }}" class="input num" required></x-field>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $material->is_active))> {{ __('Active (can be indented)') }}</label>
            </fieldset>
            @can('procurement.masters')<button class="btn btn-primary">{{ __('Save') }}</button>@endcan
        </form>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Unit conversions') }}</h2></div>
            @if ($material->conversions->isEmpty())
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('Only the base unit can be used until you add a conversion.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ledger">
                        <thead><tr><th>{{ __('Unit') }}</th><th class="r">{{ __('Equals') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($material->conversions as $conversion)
                            <tr>
                                <td>1 {{ $conversion->unit->code }}</td>
                                <td class="r">{{ rtrim(rtrim($conversion->factor, '0'), '.') }} {{ $material->baseUnit->code }}</td>
                                <td class="text-right">
                                    @can('procurement.masters')
                                        <form method="POST" action="{{ route('procurement.materials.conversions.destroy', $conversion) }}">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @can('procurement.masters')
                <form method="POST" action="{{ route('procurement.materials.conversions.store', $material) }}" class="flex flex-wrap items-end gap-3 border-t border-rule p-4">
                    @csrf
                    <x-field :label="__('1 of this unit')" name="unit_id" class="min-w-28 flex-1">
                        <select name="unit_id" class="input" required>
                            <option value="">{{ __('Choose') }}</option>
                            @foreach ($units->where('id', '!=', $material->base_unit_id) as $unit)<option value="{{ $unit->id }}">{{ $unit->code }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('equals (:unit)', ['unit' => $material->baseUnit->code])" name="factor" class="min-w-28 flex-1"><input name="factor" type="number" step="0.000001" min="0.000001" class="input num" required></x-field>
                    <button class="btn btn-ghost">{{ __('Save conversion') }}</button>
                </form>
            @endcan
        </section>
    </div>
@endsection
