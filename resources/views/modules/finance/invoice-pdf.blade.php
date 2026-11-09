<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding-bottom: 8px; }
        .boxed td { border: 1px solid #b9b2a2; padding: 5px; vertical-align: top; width: 50%; }
        .items { margin-top: 12px; }
        .items th { background: #ebe5d7; border: 1px solid #16201f; padding: 4px; font-size: 8px; text-align: left; }
        .items td { border: 1px solid #b9b2a2; padding: 4px; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .summary { width: 45%; margin-top: 12px; margin-left: auto; }
        .summary td { border-bottom: 1px solid #b9b2a2; padding: 4px; }
        .total td { font-weight: bold; font-size: 11px; border-top: 1.5px solid #16201f; border-bottom: 1.5px solid #16201f; }
        .irn { margin-top: 14px; border: 1px solid #16201f; padding: 6px; }
        .irn div { margin-bottom: 2px; word-break: break-all; }
        .void { color: #a13a1f; font-size: 13px; font-weight: bold; margin-top: 4px; }
        .sign { margin-top: 40px; text-align: right; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $invoice->supplier_name }}</h1>
            @if ($invoice->supplier_address)<div class="muted">{{ $invoice->supplier_address }}</div>@endif
            <div>GSTIN: {{ $invoice->supplier_gstin }}</div>
        </td>
        <td class="r">
            <h1>Tax Invoice</h1>
            <div>{{ $invoice->invoice_no }}</div>
            <div class="muted">Date: {{ $invoice->invoice_date->format('d M Y') }}</div>
            @if ($invoice->status === 'CANCELLED')<div class="void">CANCELLED</div>@endif
        </td>
    </tr>
</table>

<table class="boxed">
    <tr>
        <td>
            <strong>Billed to</strong><br>
            {{ $invoice->buyer_name }}<br>
            @if ($invoice->buyer_address){{ $invoice->buyer_address }}<br>@endif
            GSTIN: {{ $invoice->buyer_gstin ?? 'Unregistered' }}
        </td>
        <td>
            <strong>Place of supply:</strong> {{ $invoice->place_of_supply_code }}-{{ $invoice->place_of_supply }}<br>
            <strong>Project:</strong> {{ $invoice->project->name }} ({{ $invoice->project->code }})@if ($invoice->project->location), {{ $invoice->project->location }}@endif<br>
            <strong>RA bill:</strong> {{ $invoice->raBill->number }}<br>
            <strong>Tax payable on reverse charge:</strong> No
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr><th>Item</th><th style="width:42%">Description</th><th>SAC</th><th>Unit</th><th class="r">Qty this bill</th><th class="r">Rate</th><th class="r">Taxable value</th></tr>
    </thead>
    <tbody>
    @foreach ($invoice->raBill->items as $line)
        <tr>
            <td>{{ $line->code }}</td>
            <td>{{ $line->description }}</td>
            <td>{{ $invoice->sac }}</td>
            <td>{{ $line->unit }}</td>
            <td class="r">{{ fmt_qty($line->current_qty) }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->rate) }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->current_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="summary">
    <tr><td>Taxable value</td><td class="r">{{ inr($invoice->taxable_amount) }}</td></tr>
    @if ($invoice->isInterState())
        <tr><td>IGST ({{ (float) $invoice->gst_pct }}%)</td><td class="r">{{ inr($invoice->igst_amount) }}</td></tr>
    @else
        <tr><td>CGST ({{ (float) $invoice->gst_pct / 2 }}%)</td><td class="r">{{ inr($invoice->cgst_amount) }}</td></tr>
        <tr><td>SGST ({{ (float) $invoice->gst_pct / 2 }}%)</td><td class="r">{{ inr($invoice->sgst_amount) }}</td></tr>
    @endif
    <tr class="total"><td>Invoice value</td><td class="r">{{ inr($invoice->invoice_value) }}</td></tr>
    @foreach ($invoice->raBill->deductions as $deduction)
        <tr><td class="muted">Less: {{ $deduction->label }}</td><td class="r muted">{{ inr($deduction->amount) }}</td></tr>
    @endforeach
    <tr><td><strong>Net payable on RA bill</strong></td><td class="r"><strong>{{ inr($invoice->raBill->net_payable) }}</strong></td></tr>
</table>

<div class="irn">
    <strong>E-invoice details</strong>
    <div>IRN: {{ $invoice->irn ?? 'Not generated' }}</div>
    <div>Acknowledgement no: {{ $invoice->irn_ack_no ?? '—' }}</div>
    <div>Acknowledgement date: {{ $invoice->irn_ack_date?->format('d M Y H:i') ?? '—' }}</div>
</div>

<div class="sign">For {{ $invoice->supplier_name }}<br><br><br>Authorised signatory</div>
</body>
</html>
