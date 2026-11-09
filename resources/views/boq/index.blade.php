@extends('layouts.app')
@section('title', __('BOQ & ledger'))
@section('content')
    @php $setup = auth()->user()->organization->allowsFeature('site_control'); @endphp
    <div x-data="{ add: false, edit: null }">
        <x-page-header :title="__('BOQ & ledger')" :subtitle="$project->name.' · '.__('Total').' '.inr($total)">
            <a href="{{ route('boq.export', $project) }}" class="btn btn-ghost">{{ __('Export CSV') }}</a>
            @include('modules.reports._boq-buttons', ['project' => $project])
            @includeWhen($setup, 'modules.project_setup.boq._header-links', ['project' => $project])
            @can('boq.manage')
                <button type="button" class="btn btn-primary" @click="add = !add">{{ __('Add or import') }}</button>
            @endcan
        </x-page-header>

        @can('boq.manage')
            <div x-show="add" x-cloak class="mb-5 grid gap-5 lg:grid-cols-3">
                <form method="POST" action="{{ route('boq.store', $project) }}" class="card lg:col-span-2">
                    @csrf
                    <div class="card-head"><h2 class="card-title">{{ __('Add BOQ item') }}</h2></div>
                    <div class="grid gap-3 p-4 sm:grid-cols-4">
                        @include('modules.reports._rate-picker')
                        <x-field :label="__('Section')" name="section" class="sm:col-span-2"><input id="section" name="section" value="{{ old('section') }}" class="input"></x-field>
                        <x-field :label="__('Item code')" name="code" class="sm:col-span-2"><input id="code" name="code" value="{{ old('code') }}" class="input" required></x-field>
                        @include('modules.project_setup.boq._add-fields')
                        <x-field :label="__('Description')" name="description" class="sm:col-span-4"><textarea id="description" name="description" rows="2" class="input" required>{{ old('description') }}</textarea></x-field>
                        <x-field :label="__('Unit')" name="unit">
                            <input id="unit" name="unit" value="{{ old('unit') }}" list="units" class="input" required>
                            <datalist id="units">@foreach (config('buildos.units') as $unit)<option value="{{ $unit }}">@endforeach</datalist>
                        </x-field>
                        <x-field :label="__('Quantity')" name="qty"><input id="qty" name="qty" type="number" step="0.001" min="0" value="{{ old('qty') }}" class="input num" required></x-field>
                        <x-field :label="__('Rate (₹)')" name="rate"><input id="rate" name="rate" type="number" step="0.01" min="0" value="{{ old('rate') }}" class="input num" required></x-field>
                        <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Add item') }}</button></div>
                    </div>
                </form>
                <form method="POST" action="{{ route('boq.import', $project) }}" enctype="multipart/form-data" class="card">
                    @csrf
                    <div class="card-head"><h2 class="card-title">{{ __('Import CSV') }}</h2></div>
                    <div class="space-y-3 p-4">
                        <p class="text-sm text-ink-3">{{ __('Rows with an existing item code are updated. If any row has a problem, nothing is imported.') }}</p>
                        <x-field :label="__('CSV file')" name="file"><input id="file" name="file" type="file" accept=".csv,text/csv" class="input" required></x-field>
                        <div class="flex items-center justify-between gap-2">
                            <a href="{{ route('boq.template') }}" class="link text-sm">{{ __('Download template') }}</a>
                            <button class="btn btn-ghost">{{ __('Import') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        @endcan

        <div class="card overflow-x-auto">
            @if ($items->isEmpty())
                <x-empty :title="__('No BOQ items yet')" :note="__('Every measurement and bill hangs off a BOQ line. Add items or import a CSV to begin.')">
                    @if ($setup)
                        @include('modules.project_setup.boq._starter-button', ['project' => $project])
                    @endif
                </x-empty>
            @else
                <table class="ledger min-w-[980px]">
                    <thead>
                    <tr>
                        <th>{{ __('Code') }}</th><th class="w-[30%]">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th>
                        <th class="r">{{ __('BOQ') }}</th><th class="r">{{ __('Executed') }}</th><th class="r">{{ __('Measured') }}</th><th class="r">{{ __('Approved') }}</th><th class="r">{{ __('Billed') }}</th><th class="r">{{ __('Balance') }}</th>
                        @if ($setup || auth()->user()->can('boq.manage'))<th></th>@endif
                    </tr>
                    </thead>
                    <tbody>
                    @php $section = false; @endphp
                    @foreach ($items as $item)
                        @if ($item->section?->name !== $section)
                            @php $section = $item->section?->name; @endphp
                            @if ($section)
                                <tr><td colspan="11" class="bg-paper-2/40 font-display text-base font-semibold">{{ $section }}</td></tr>
                            @endif
                        @endif
                        @php $editPayload = ['url' => route('boq.update', $item), 'removeUrl' => route('boq.destroy', $item), 'code' => $item->code, 'description' => $item->description, 'unit' => $item->unit, 'qty' => $item->qty, 'rate' => $item->rate, 'category' => $item->category ?? '', 'details' => $item->details ?? '', 'reason' => '']; @endphp
                        <tr>
                            <td class="num text-xs">{{ $item->code }}</td>
                            <td>
                                {{ $item->description }}
                                @include('modules.project_setup.boq._line-meta', ['item' => $item])
                                {{-- The table scrolls sideways on a phone, so the actions are repeated here where a thumb can reach them. --}}
                                <div class="mt-1 flex gap-5 text-sm sm:hidden">
                                    @can('boq.manage')<button type="button" class="link py-2" @click="edit = @js($editPayload)">{{ __('Edit') }}</button>@endcan
                                    @if ($setup)<a href="{{ route('project-setup.history', [$project, 'item' => $item->id]) }}" class="link py-2">{{ __('History') }}</a>@endif
                                </div>
                            </td>
                            <td>{{ $item->unit }}</td>
                            <td class="r">{{ \App\Support\Dec::indian($item->rate) }}</td>
                            <td class="r">{{ fmt_qty($item->qty) }}</td>
                            <td class="r">{{ fmt_qty($item->executed_qty ?? 0) }}</td>
                            <td class="r">{{ fmt_qty($item->measured_qty ?? 0) }}</td>
                            <td class="r">{{ fmt_qty($item->approved_qty ?? 0) }}</td>
                            <td class="r">{{ fmt_qty($item->billed_qty ?? 0) }}</td>
                            <td class="r {{ \App\Support\Dec::of($item->balanceQty())->isNegative() ? 'font-medium text-laterite' : '' }}">{{ fmt_qty($item->balanceQty()) }}</td>
                            @if ($setup || auth()->user()->can('boq.manage'))
                                <td class="text-right whitespace-nowrap">
                                    @can('boq.manage')
                                        <button type="button" class="link text-xs" @click="edit = @js($editPayload)">{{ __('Edit') }}</button>
                                    @endcan
                                    @if ($setup)
                                        <a href="{{ route('project-setup.history', [$project, 'item' => $item->id]) }}" class="link ml-2 text-xs">{{ __('History') }}</a>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @can('boq.manage')
            <div x-show="edit" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-ink/50 p-4" @keydown.escape.window="edit = null">
                <template x-if="edit">
                    <div class="card mx-auto my-4 w-full max-w-lg" @click.outside="edit = null">
                        <form method="POST" :action="edit.url">
                            @csrf @method('PATCH')
                            <div class="card-head"><h2 class="card-title">{{ __('Edit BOQ item') }}</h2></div>
                            <div class="grid gap-3 p-4 sm:grid-cols-3">
                                <div class="sm:col-span-3"><label class="label">{{ __('Item code') }}</label><input name="code" x-model="edit.code" class="input" required></div>
                                <div class="sm:col-span-3"><label class="label">{{ __('Description') }}</label><textarea name="description" x-model="edit.description" rows="3" class="input" required></textarea></div>
                                <div><label class="label">{{ __('Unit') }}</label><input name="unit" x-model="edit.unit" class="input" required></div>
                                <div><label class="label">{{ __('Quantity') }}</label><input name="qty" type="number" step="0.001" min="0" x-model="edit.qty" class="input num" required></div>
                                <div><label class="label">{{ __('Rate (₹)') }}</label><input name="rate" type="number" step="0.01" min="0" x-model="edit.rate" class="input num" required></div>
                                @include('modules.project_setup.boq._edit-fields')
                            </div>
                            <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                                <button type="button" class="btn btn-ghost" @click="edit = null">{{ __('Cancel') }}</button>
                                <button class="btn btn-primary">{{ __('Save changes') }}</button>
                            </div>
                        </form>
                        @include('modules.project_setup.boq._remove')
                    </div>
                </template>
            </div>
        @endcan
    </div>
@endsection
