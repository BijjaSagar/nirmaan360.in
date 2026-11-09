@extends('layouts.app')
@section('title', __('Takeoff'))
@section('content')
    <x-page-header :title="__('Takeoff')" :subtitle="$project->name">
        <a href="{{ route('drawings.register.index', [$project, 'view' => 'sheets']) }}" class="btn btn-primary">{{ __('Open a sheet to measure') }}</a>
    </x-page-header>

    @if ($superseded > 0)
        <div class="mb-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium text-[#8a6208]" role="status">{{ __(':count measurements are on old revisions and are left out of these totals.', ['count' => $superseded]) }}</div>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <div class="card overflow-x-auto lg:col-span-2">
            <div class="card-head">
                <h2 class="card-title">{{ __('Linked to BOQ') }}</h2>
                @if ($unlinked > 0)<span class="text-xs text-ink-3">{{ __(':count measurements are not linked yet.', ['count' => $unlinked]) }}</span>@endif
            </div>
            @if ($boqTotals->isEmpty())
                <x-empty :title="__('No BOQ links yet')" :note="__('Open a sheet, select a measurement and choose its BOQ item.')" />
            @else
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>{{ __('Code') }}</th><th class="w-[36%]">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Measurements') }}</th><th class="r">{{ __('Takeoff') }}</th><th class="r">{{ __('BOQ') }}</th><th class="r">{{ __('Difference') }}</th>@can('boq.manage')<th></th>@endcan</tr></thead>
                    <tbody>
                    @foreach ($boqTotals as $row)
                        <tr>
                            <td class="num text-xs">{{ $row['item']->code }}</td>
                            <td>{{ $row['item']->description }}</td>
                            <td>{{ $row['item']->unit }}</td>
                            <td class="r">{{ $row['count'] }}</td>
                            <td class="r">{{ fmt_qty($row['takeoff']) }}</td>
                            <td class="r">{{ fmt_qty($row['boq']) }}</td>
                            <td class="r">{{ fmt_qty($row['difference']) }}</td>
                            @can('boq.manage')
                                <td class="text-right">
                                    @can('drawings.takeoff')
                                        <form method="POST" action="{{ route('drawings.takeoff.apply', $row['item']) }}" onsubmit="return confirm(@js(__('Replace the BOQ quantity with the takeoff total?')))">
                                            @csrf
                                            <button class="link text-xs whitespace-nowrap">{{ __('Use in BOQ') }}</button>
                                        </form>
                                    @endcan
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Totals by layer') }}</h2></div>
            @if ($layerTotals->isEmpty())
                <x-empty :title="__('Nothing measured yet')" />
            @else
                <table class="ledger min-w-[420px]">
                    <thead><tr><th>{{ __('Layer') }}</th><th class="r">{{ __('Measurements') }}</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('Unit') }}</th></tr></thead>
                    <tbody>
                    @foreach ($layerTotals as $row)
                        <tr>
                            <td><span class="mr-1.5 inline-block size-2.5 rounded-full align-middle" style="background: {{ $row['layer']->colour }}"></span>{{ $row['layer']->name }}</td>
                            <td class="r">{{ $row['count'] }}</td>
                            <td class="r">{{ fmt_qty($row['qty']) }}</td>
                            <td>{{ $row['unit'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Sheets measured') }}</h2></div>
            @if ($sheets->isEmpty())
                <x-empty :title="__('No sheets measured yet')" />
            @else
                <table class="ledger min-w-[420px]">
                    <thead><tr><th>{{ __('Drawing') }}</th><th>{{ __('Sheet') }}</th><th class="r">{{ __('Measurements') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($sheets as $sheet)
                        <tr>
                            <td class="num text-xs">{{ $sheet->version->drawing->number }}</td>
                            <td>{{ $sheet->label() }}</td>
                            <td class="r">{{ $sheet->measurements_count }}</td>
                            <td class="text-right"><a href="{{ route('drawings.register.sheet', $sheet) }}" class="link text-xs">{{ __('Open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Layers') }}</h2></div>
            <div class="divide-y divide-rule">
                @forelse ($layers as $layer)
                    <div class="flex flex-wrap items-center gap-2 p-3">
                        @can('drawings.takeoff')
                            <form method="POST" action="{{ route('drawings.takeoff.layers.update', $layer) }}" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                @csrf @method('PATCH')
                                <input type="color" name="colour" value="{{ $layer->colour }}" class="h-8 w-10 shrink-0 rounded-[3px] border border-rule" aria-label="{{ __('Colour') }}">
                                <input name="name" value="{{ $layer->name }}" maxlength="80" class="input max-w-xs flex-1" aria-label="{{ __('Layer') }}" required>
                                <button class="btn btn-ghost">{{ __('Save') }}</button>
                            </form>
                            @if ($layer->measurements_count === 0)
                                <form method="POST" action="{{ route('drawings.takeoff.layers.destroy', $layer) }}">
                                    @csrf @method('DELETE')
                                    <button class="link text-xs">{{ __('Remove') }}</button>
                                </form>
                            @endif
                        @else
                            <span class="inline-block size-2.5 rounded-full" style="background: {{ $layer->colour }}"></span> {{ $layer->name }}
                        @endcan
                    </div>
                @empty
                    <x-empty :title="__('No layers yet')" :note="__('Add layers from the sheet viewer, such as Brickwork or Plaster.')" />
                @endforelse
            </div>
        </div>
    </div>
@endsection
