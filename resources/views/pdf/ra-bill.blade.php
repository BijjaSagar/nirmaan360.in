<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $bill->number }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .items { margin-top: 12px; }
        .items th { background: #ebe5d7; border: 1px solid #16201f; padding: 4px; font-size: 8px; text-align: left; }
        .items td { border: 1px solid #b9b2a2; padding: 4px; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .summary { width: 42%; margin-top: 12px; margin-left: auto; }
        .summary td { border-bottom: 1px solid #b9b2a2; padding: 4px; }
        .net td { font-weight: bold; font-size: 11px; border-top: 1.5px solid #16201f; border-bottom: 1.5px solid #16201f; }
        .sign { margin-top: 46px; }
        .sign td { width: 33%; text-align: center; border-top: 1px solid #16201f; padding-top: 4px; }
        .gap { width: 3%; border: 0 !important; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $bill->organization->name }}</h1>
            @if ($bill->organization->address)<div class="muted">{{ $bill->organization->address }}</div>@endif
            @if ($bill->organization->gstin)<div class="muted">GSTIN: {{ $bill->organization->gstin }}</div>@endif
        </td>
        <td class="r">
            <h1>{{ $bill->type === 'client' ? 'Running Account Bill' : 'Subcontractor Bill' }}</h1>
            <div>{{ $bill->number }}</div>
            <div class="muted">Period: {{ $bill->period_from->format('d M Y') }} to {{ $bill->period_to->format('d M Y') }}</div>
            <div class="muted">Status: {{ str_replace('_', ' ', $bill->status) }}</div>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding-top:8px">
            <strong>Project:</strong> {{ $bill->project->name }} ({{ $bill->project->code }})<br>
            <strong>{{ $bill->type === 'client' ? 'Client' : 'Subcontractor' }}:</strong> {{ $bill->party?->name ?? '—' }}
            @if ($bill->party?->gstin) · GSTIN {{ $bill->party->gstin }}@endif
            @if ($bill->workOrder)<br><strong>Work order:</strong> {{ $bill->workOrder->number }}@endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th>Item</th><th style="width:30%">Description</th><th>Unit</th><th class="r">Rate</th>
        <th class="r">Previous qty</th><th class="r">This bill qty</th><th class="r">Cumulative qty</th>
        <th class="r">Previous amount</th><th class="r">This bill amount</th><th class="r">Cumulative amount</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($bill->items as $line)
        <tr>
            <td>{{ $line->code }}</td>
            <td>{{ $line->description }}</td>
            <td>{{ $line->unit }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->rate) }}</td>
            <td class="r">{{ fmt_qty($line->prev_qty) }}</td>
            <td class="r">{{ fmt_qty($line->current_qty) }}</td>
            <td class="r">{{ fmt_qty($line->cumulative_qty) }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->prev_amount) }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->current_amount) }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->cumulative_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="summary">
    <tr><td>Gross value of work</td><td class="r">{{ inr($bill->gross_amount) }}</td></tr>
    @foreach ($bill->deductions as $deduction)
        <tr><td>Less: {{ $deduction->label }} @if ($deduction->pct !== null && (float) $deduction->pct > 0)({{ (float) $deduction->pct }}%)@endif</td><td class="r">{{ inr($deduction->amount) }}</td></tr>
    @endforeach
    <tr><td>{{ $bill->hasGst() ? 'Add: GST ('.(float) ($bill->terms['gst_pct'] ?? 0).'%)' : 'GST' }}</td><td class="r">{{ $bill->hasGst() ? inr($bill->gst_amount) : 'Not charged' }}</td></tr>
    <tr class="net"><td>Net payable</td><td class="r">{{ inr($bill->net_payable) }}</td></tr>
</table>

<table class="sign">
    <tr>
        <td>Prepared by</td><td class="gap"></td>
        <td>Checked by</td><td class="gap"></td>
        <td>Approved by</td>
    </tr>
</table>
</body>
</html>
