@extends('layouts.app')
@section('title', __('Materials'))
@section('content')
    <x-page-header :title="__('Materials')" :subtitle="__('Company-wide list used by every project')" />

    <div class="grid items-start gap-5 lg:grid-cols-3">
        <section class="card overflow-x-auto lg:col-span-2">
            @if ($materials->isEmpty())
                <x-empty :title="__('No materials yet')" :note="__('Add the materials you buy, with the unit you count them in.')" />
            @else
                <table class="ledger min-w-[560px]">
                    <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th>{{ __('Base unit') }}</th><th class="r">{{ __('GST %') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($materials as $material)
                        <tr>
                            <td class="num text-xs"><a href="{{ route('procurement.materials.show', $material) }}" class="link">{{ $material->code }}</a></td>
                            <td>{{ $material->name }}@if ($material->category)<span class="block text-xs text-ink-3">{{ $material->category }}</span>@endif</td>
                            <td>{{ $material->baseUnit->code }}</td>
                            <td class="r">{{ $material->gst_pct }}</td>
                            <td><x-stamp :status="$material->is_active ? 'active' : 'inactive'" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <div class="space-y-5">
            @can('procurement.masters')
                <form method="POST" action="{{ route('procurement.materials.store') }}" class="card space-y-3 p-4">
                    @csrf
                    <h2 class="card-title">{{ __('Add material') }}</h2>
                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <x-field :label="__('Code')" name="code"><input id="code" name="code" value="{{ old('code') }}" class="input" maxlength="40" required></x-field>
                    <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name') }}" class="input" maxlength="160" required></x-field>
                    <x-field :label="__('Category')" name="category"><input id="category" name="category" value="{{ old('category') }}" class="input" maxlength="60"></x-field>
                    <x-field :label="__('Base unit')" name="base_unit_id" :hint="__('Stock is counted in this unit. It cannot change once the material is used.')">
                        <select id="base_unit_id" name="base_unit_id" class="input" required>
                            <option value="">{{ __('Choose') }}</option>
                            @foreach ($units as $unit)<option value="{{ $unit->id }}" @selected((string) old('base_unit_id') === (string) $unit->id)>{{ $unit->code }}</option>@endforeach
                        </select>
                    </x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field :label="__('HSN code')" name="hsn_code"><input id="hsn_code" name="hsn_code" value="{{ old('hsn_code') }}" class="input" maxlength="12"></x-field>
                        <x-field :label="__('GST %')" name="gst_pct"><input id="gst_pct" name="gst_pct" type="number" step="0.01" min="0" max="100" value="{{ old('gst_pct', 18) }}" class="input num" required></x-field>
                    </div>
                    <button class="btn btn-primary">{{ __('Add material') }}</button>
                </form>
            @endcan

            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Units') }}</h2></div>
                <p class="flex flex-wrap gap-1.5 p-4 text-sm">
                    @foreach ($units as $unit)<span class="num rounded-[3px] border border-rule px-2 py-0.5 text-xs">{{ $unit->code }}</span>@endforeach
                </p>
                @can('procurement.masters')
                    <form method="POST" action="{{ route('procurement.materials.units.store') }}" class="flex flex-wrap items-end gap-2 border-t border-rule p-4">
                        @csrf
                        <x-field :label="__('New unit')" name="code" class="min-w-24 flex-1"><input name="code" class="input" maxlength="20" required></x-field>
                        <button class="btn btn-ghost">{{ __('Add') }}</button>
                    </form>
                @endcan
            </section>
        </div>
    </div>
    <div class="mt-4">{{ $materials->links() }}</div>
@endsection
