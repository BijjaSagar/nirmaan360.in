<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 26px 26px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ count($report->columns) > 10 ? '6.5' : '8' }}px; color: #16201f; }
        h1 { font-size: 14px; margin: 0; }
        h2 { font-size: 11px; margin: 2px 0 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .head td { vertical-align: top; }
        .rows { margin-top: 10px; }
        .rows th { background: #ebe5d7; border-top: 1px solid #16201f; border-bottom: 1px solid #16201f; padding: 3px; text-align: left; }
        .rows td { border-bottom: 1px solid #b9b2a2; padding: 3px; vertical-align: top; }
        .rows tr.total td { font-weight: bold; border-top: 1.5px solid #16201f; background: #f6f2e8; }
        .r, .rows th.r { text-align: right; white-space: nowrap; }
        .note { margin-top: 8px; font-style: italic; color: #4d5a56; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $organization->name }}</h1>
            @if ($organization->address)<div class="muted">{{ $organization->address }}</div>@endif
            @if ($organization->gstin)<div class="muted">GSTIN: {{ $organization->gstin }}</div>@endif
        </td>
        <td class="r">
            <h2>{{ $report->title }}</h2>
            <div>{{ $report->subtitle }}</div>
            <div class="muted">{{ __('Generated') }} {{ now()->format('d M Y H:i') }}</div>
        </td>
    </tr>
</table>

<table class="rows">
    <thead>
    <tr>
        @foreach ($report->columns as $column)
            <th class="{{ in_array($column['type'], $numeric, true) ? 'r' : '' }}">{{ $column['label'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse ($report->rows as $row)
        <tr>
            @foreach ($report->columns as $column)
                <td class="{{ in_array($column['type'], $numeric, true) || $column['type'] === 'date' ? 'r' : '' }}">{{ \App\Services\Reports\ReportResult::display($column['type'], $row[$column['key']] ?? null) }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($report->columns) }}" class="muted">{{ __('No data for these choices.') }}</td></tr>
    @endforelse
    @if ($report->totals !== null && $report->rows !== [])
        <tr class="total">
            @foreach ($report->columns as $column)
                <td class="{{ in_array($column['type'], $numeric, true) ? 'r' : '' }}">
                    @if ($loop->first){{ __('Total') }}@elseif (array_key_exists($column['key'], $report->totals)){{ \App\Services\Reports\ReportResult::display($column['type'], $report->totals[$column['key']]) }}@endif
                </td>
            @endforeach
        </tr>
    @endif
    </tbody>
</table>

@foreach ($report->notes as $note)
    <div class="note">{{ $note }}</div>
@endforeach
@if (count($report->rows) < $totalRows)
    <div class="note">{{ __('This PDF shows the first :shown of :total rows. The totals cover all :total rows. Use the Excel export for the full list.', ['shown' => count($report->rows), 'total' => $totalRows]) }}</div>
@endif
</body>
</html>
