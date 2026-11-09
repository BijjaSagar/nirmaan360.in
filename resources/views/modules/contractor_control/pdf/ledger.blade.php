<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $party->name }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        h2 { font-size: 11px; margin: 14px 0 4px; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .items th { background: #ebe5d7; border: 1px solid #16201f; padding: 4px; font-size: 8px; text-align: left; }
        .items td { border: 1px solid #b9b2a2; padding: 4px; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .summary { width: 45%; margin-top: 10px; margin-left: auto; }
        .summary td { border-bottom: 1px solid #b9b2a2; padding: 4px; }
        .net td { font-weight: bold; font-size: 11px; border-top: 1.5px solid #16201f; border-bottom: 1.5px solid #16201f; }
        .red { color: #b4451f; }
    </style>
</head>
<body>
@php($money = fn ($value) => \App\Support\Dec::indian($value))
<table class="head">
    <tr>
        <td>
            <h1>{{ $organization->name }}</h1>
            @if ($organization->address)<div class="muted">{{ $organization->address }}</div>@endif
            @if ($organization->gstin)<div class="muted">GSTIN: {{ $organization->gstin }}</div>@endif
        </td>
        <td class="r">
            <h1>Contractor ledger</h1>
            <div>{{ $party->name }}</div>
            <div class="muted">As on {{ today()->format('d M Y') }}</div>
        </td>
    </tr>
</table>

<table class="summary">
    <tr><td>Total contract</td><td class="r">{{ $money($summary['contract_value']) }}</td></tr>
    <tr><td>Paid till date</td><td class="r">{{ $money($summary['paid']) }}</td></tr>
    <tr><td>Of which advance</td><td class="r">{{ $money($summary['advance']) }}</td></tr>
    <tr class="net"><td>Balance</td><td class="r">{{ $money($summary['balance_money']) }}</td></tr>
    <tr><td colspan="2" class="r {{ $summary['status'] === 'red' ? 'red' : '' }}">{{ $summary['label'] }}</td></tr>
</table>

<h2>Contracts</h2>
<table class="items">
    <thead><tr><th>Work order</th><th>Project</th><th>Title</th><th class="r">Contract</th><th class="r">Paid</th><th class="r">Balance</th><th class="r">Paid %</th><th class="r">Work %</th></tr></thead>
    <tbody>
    @foreach ($contracts as $row)
        <tr>
            <td>{{ $row['work_order']->number }}</td>
            <td>{{ $row['project']->name }}</td>
            <td>{{ $row['work_order']->title }}</td>
            <td class="r">{{ $money($row['contract_value']) }}</td>
            <td class="r">{{ $money($row['paid']) }}</td>
            <td class="r">{{ $money($row['balance_money']) }}</td>
            <td class="r {{ $row['status'] === 'red' ? 'red' : '' }}">{{ $row['paid_pct'] }}</td>
            <td class="r">{{ $row['work_pct'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Payment history (approved only)</h2>
<table class="items">
    <thead><tr><th>Date</th><th>Voucher</th><th>Work order</th><th>Paid to</th><th>Mode</th><th>Against</th><th class="r">Amount</th><th class="r">Balance</th></tr></thead>
    <tbody>
    @forelse ($history as $entry)
        <tr>
            <td>{{ $entry['payment']->paid_on->format('d M Y') }}</td>
            <td>{{ $entry['payment']->number }}@if ($entry['payment']->isReversal()) (reversal)@endif</td>
            <td>{{ $entry['payment']->workOrder?->number }}</td>
            <td>{{ $entry['payment']->paid_to }}</td>
            <td>{{ config('modules.contractor_control.payment_modes.'.$entry['payment']->mode, $entry['payment']->mode) }}</td>
            <td>{{ $entry['payment']->boqItem?->code ?? $entry['payment']->milestone?->title ?? 'Contract' }}</td>
            <td class="r">{{ $money($entry['payment']->amount) }}</td>
            <td class="r">{{ $money($entry['balance']) }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">No approved payments.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
