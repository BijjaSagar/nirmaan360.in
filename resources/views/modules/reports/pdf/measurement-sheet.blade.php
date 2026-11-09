<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Measurement sheet') }} {{ $project->code }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        h2 { font-size: 10px; margin: 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .head td { vertical-align: top; }
        .item { margin-top: 12px; page-break-inside: auto; }
        .item-head td { background: #ebe5d7; border-top: 1px solid #16201f; border-bottom: 1px solid #16201f; padding: 4px 5px; font-weight: bold; }
        .rows th { border-bottom: 1px solid #16201f; padding: 3px 4px; text-align: left; font-size: 7.5px; background: #f6f2e8; }
        .rows td { border-bottom: 1px solid #b9b2a2; padding: 3px 4px; vertical-align: top; }
        .rows tr { page-break-inside: avoid; }
        .sum td { border-bottom: 1px solid #b9b2a2; padding: 3px 4px; background: #fbf9f3; }
        .sum tr.strong td { font-weight: bold; border-top: 1.5px solid #16201f; }
        .r, .rows th.r, .abstract th.r { text-align: right; white-space: nowrap; }
        .neg { color: #9a4225; }
        .abstract { margin-top: 16px; page-break-before: always; }
        .abstract th { background: #ebe5d7; border-top: 1px solid #16201f; border-bottom: 1px solid #16201f; padding: 4px; text-align: left; font-size: 7.5px; }
        .abstract td { border-bottom: 1px solid #b9b2a2; padding: 4px; vertical-align: top; }
        .abstract tr.total td { font-weight: bold; border-top: 1.5px solid #16201f; background: #f6f2e8; }
        .sign { margin-top: 44px; }
        .sign td { width: 23%; text-align: center; border-top: 1px solid #16201f; padding-top: 4px; }
        .gap { width: 2.6%; border: 0 !important; }
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
            <h1>{{ __('Measurement sheet') }}</h1>
            <div>{{ $from->format('d M Y') }} {{ __('to') }} {{ $to->format('d M Y') }}</div>
            <div class="muted">{{ __('Approved measurements only') }}</div>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding-top: 8px;">
            <strong>{{ __('Project') }}:</strong> {{ $project->name }} ({{ $project->code }})
            @if ($project->location) · {{ $project->location }} @endif
            @if ($client)<br><strong>{{ __('Client') }}:</strong> {{ $client }}@endif
        </td>
    </tr>
</table>

@forelse ($items as $item)
    <table class="item">
        <tr class="item-head">
            <td colspan="9">{{ $item['code'] }} · {{ $item['description'] }} <span class="muted">({{ $item['unit'] }})</span></td>
        </tr>
    </table>
    <table class="rows">
        <thead>
        <tr>
            <th style="width: 4%">{{ __('No.') }}</th><th style="width: 9%">{{ __('Date') }}</th><th>{{ __('Description of work and location') }}</th>
            <th class="r" style="width: 7%">{{ __('Nos') }}</th><th class="r" style="width: 8%">L</th><th class="r" style="width: 8%">B</th><th class="r" style="width: 8%">D / H</th>
            <th class="r" style="width: 11%">{{ __('Quantity') }}</th><th style="width: 16%">{{ __('Remarks') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($item['rows'] as $row)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $row['date']->format('d M Y') }}</td>
                <td>{{ $row['description'] }}</td>
                <td class="r">{{ $row['nos'] === null ? '' : fmt_qty($row['nos']) }}</td>
                <td class="r">{{ $row['length'] === null ? '' : fmt_qty($row['length']) }}</td>
                <td class="r">{{ $row['breadth'] === null ? '' : fmt_qty($row['breadth']) }}</td>
                <td class="r">{{ $row['depth'] === null ? '' : fmt_qty($row['depth']) }}</td>
                <td class="r {{ \App\Support\Dec::of($row['qty'])->isNegative() ? 'neg' : '' }}">{{ fmt_qty($row['qty']) }}</td>
                <td>{{ $row['remarks'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="sum" style="width: 55%; margin-left: auto;">
        <tr><td>{{ __('Quantity this period') }}</td><td class="r">{{ fmt_qty($item['period_qty']) }} {{ $item['unit'] }}</td></tr>
        <tr><td>{{ __('Approved before :date', ['date' => $from->format('d M Y')]) }}</td><td class="r">{{ fmt_qty($item['previous_qty']) }} {{ $item['unit'] }}</td></tr>
        <tr class="strong"><td>{{ __('Cumulative to :date', ['date' => $to->format('d M Y')]) }}</td><td class="r">{{ fmt_qty($item['cumulative_qty']) }} {{ $item['unit'] }}</td></tr>
        <tr><td>{{ __('BOQ quantity') }}</td><td class="r">{{ fmt_qty($item['boq_qty']) }} {{ $item['unit'] }}</td></tr>
    </table>
@empty
    <p style="margin-top: 20px;" class="muted">{{ __('No approved measurements in this period.') }}</p>
@endforelse

@if ($items !== [])
    <div class="abstract">
        <h2>{{ __('Abstract of quantities and value for the period') }}</h2>
        <table style="margin-top: 6px;">
            <thead>
            <tr>
                <th>{{ __('Item') }}</th><th style="width: 30%">{{ __('Description') }}</th><th>{{ __('Unit') }}</th>
                <th class="r">{{ __('BOQ qty') }}</th><th class="r">{{ __('Previous') }}</th><th class="r">{{ __('This period') }}</th><th class="r">{{ __('Cumulative') }}</th>
                @if ($with_money)<th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Amount') }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item['code'] }}</td><td>{{ $item['description'] }}</td><td>{{ $item['unit'] }}</td>
                    <td class="r">{{ fmt_qty($item['boq_qty']) }}</td><td class="r">{{ fmt_qty($item['previous_qty']) }}</td>
                    <td class="r">{{ fmt_qty($item['period_qty']) }}</td><td class="r">{{ fmt_qty($item['cumulative_qty']) }}</td>
                    @if ($with_money)<td class="r">{{ \App\Support\Dec::indian($item['rate']) }}</td><td class="r">{{ \App\Support\Dec::indian($item['amount']) }}</td>@endif
                </tr>
            @endforeach
            @if ($with_money)
                <tr class="total"><td colspan="8">{{ __('Total value of work this period') }}</td><td class="r">{{ inr($total_amount) }}</td></tr>
            @endif
            </tbody>
        </table>

        <table class="sign">
            <tr>
                <td>{{ __('Measured by') }}</td><td class="gap"></td>
                <td>{{ __('Checked by') }}</td><td class="gap"></td>
                <td>{{ __('Approved by') }}</td><td class="gap"></td>
                <td>{{ __('Accepted by client') }}</td>
            </tr>
        </table>
    </div>
@endif
</body>
</html>
