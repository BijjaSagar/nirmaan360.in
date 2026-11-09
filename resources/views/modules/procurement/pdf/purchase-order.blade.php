<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $order->number }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .parties { margin-top: 12px; }
        .parties td { width: 50%; vertical-align: top; padding: 6px; border: 1px solid #b9b2a2; }
        .items { margin-top: 12px; }
        .items th { background: #ebe5d7; border: 1px solid #16201f; padding: 4px; font-size: 8px; text-align: left; }
        .items td { border: 1px solid #b9b2a2; padding: 4px; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .summary { width: 42%; margin-top: 12px; margin-left: auto; }
        .summary td { border-bottom: 1px solid #b9b2a2; padding: 4px; }
        .net td { font-weight: bold; font-size: 11px; border-top: 1.5px solid #16201f; border-bottom: 1.5px solid #16201f; }
        .terms { margin-top: 12px; white-space: pre-line; }
        .sign { margin-top: 46px; }
        .sign td { width: 33%; text-align: center; border-top: 1px solid #16201f; padding-top: 4px; }
        .gap { width: 3%; border: 0 !important; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $order->organization->name }}</h1>
            @if ($order->organization->address)<div class="muted">{{ $order->organization->address }}</div>@endif
            @if ($order->organization->gstin)<div class="muted">GSTIN: {{ $order->organization->gstin }}</div>@endif
        </td>
        <td class="r">
            <h1>Purchase Order</h1>
            <div>{{ $order->number }}</div>
            <div class="muted">Date: {{ $order->po_date->format('d M Y') }}</div>
            @if ($order->delivery_by)<div class="muted">Delivery by: {{ $order->delivery_by->format('d M Y') }}</div>@endif
            <div class="muted">Status: {{ str_replace('_', ' ', $order->status) }}</div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <strong>Vendor</strong><br>
            {{ $order->vendor->name }}<br>
            @if ($order->vendor->address){{ $order->vendor->address }}<br>@endif
            @if ($order->vendor->gstin)GSTIN: {{ $order->vendor->gstin }}<br>@endif
            @if ($order->vendor->phone)Phone: {{ $order->vendor->phone }}@endif
        </td>
        <td>
            <strong>Deliver to</strong><br>
            {{ $order->project->name }} ({{ $order->project->code }})<br>
            {{ $order->store->name }}@if ($order->store->site) · {{ $order->store->site->name }}@endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
    <tr>
        <th>#</th><th style="width:34%">Material</th><th>HSN</th><th class="r">Quantity</th><th>Unit</th>
        <th class="r">Rate</th><th class="r">Amount</th><th class="r">GST %</th><th class="r">GST</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->items as $line)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>{{ $line->material->name }}</td>
            <td>{{ $line->material->hsn_code ?? '—' }}</td>
            <td class="r">{{ fmt_qty($line->qty) }}</td>
            <td>{{ $line->unit->code }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->rate) }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->amount) }}</td>
            <td class="r">{{ $line->gst_pct }}</td>
            <td class="r">{{ \App\Support\Dec::indian($line->gst_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="summary">
    <tr><td>Subtotal</td><td class="r">{{ inr($order->subtotal) }}</td></tr>
    <tr><td>GST</td><td class="r">{{ inr($order->gst_amount) }}</td></tr>
    <tr class="net"><td>Total</td><td class="r">{{ inr($order->total) }}</td></tr>
</table>

@if ($order->terms)
    <div class="terms"><strong>Terms</strong><br>{{ $order->terms }}</div>
@endif

<table class="sign">
    <tr>
        <td>Prepared by</td><td class="gap"></td>
        <td>Checked by</td><td class="gap"></td>
        <td>Authorised signatory</td>
    </tr>
</table>
</body>
</html>
