<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $transmittal->number }}</title>
    <style>
        @page { margin: 30px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .meta { margin-top: 14px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .k { width: 90px; color: #6b7773; }
        .items { margin-top: 14px; }
        .items th { background: #ebe5d7; border: 1px solid #16201f; padding: 4px; font-size: 9px; text-align: left; }
        .items td { border: 1px solid #b9b2a2; padding: 4px; vertical-align: top; }
        .r { text-align: right; }
        .cancelled { margin-top: 12px; padding: 6px; border: 1.5px solid #b4451f; color: #b4451f; font-weight: bold; }
        .sign { margin-top: 56px; }
        .sign td { width: 45%; border-top: 1px solid #16201f; padding-top: 4px; text-align: center; }
        .gap { width: 10%; border: 0 !important; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $transmittal->organization->name }}</h1>
            <div class="muted">{{ $transmittal->project->name }} ({{ $transmittal->project->code }})</div>
        </td>
        <td class="r">
            <h1>Transmittal {{ $transmittal->number }}</h1>
            <div class="muted">{{ $transmittal->issued_at->format('d M Y') }}</div>
        </td>
    </tr>
</table>

@if ($transmittal->status === 'cancelled')
    <div class="cancelled">CANCELLED on {{ $transmittal->cancelled_at->format('d M Y') }}: {{ $transmittal->cancel_reason }}</div>
@endif

<table class="meta">
    <tr><td class="k">To</td><td>{{ $transmittal->recipient_name }}@if ($transmittal->recipient_company), {{ $transmittal->recipient_company }}@endif</td></tr>
    <tr><td class="k">Subject</td><td>{{ $transmittal->subject }}</td></tr>
    <tr><td class="k">Purpose</td><td>{{ \Illuminate\Support\Str::headline($transmittal->purpose) }}</td></tr>
    <tr><td class="k">Issued by</td><td>{{ $transmittal->issuer?->name }}</td></tr>
    @if ($transmittal->notes)<tr><td class="k">Notes</td><td>{{ $transmittal->notes }}</td></tr>@endif
</table>

<table class="items">
    <thead><tr><th style="width: 22%">Number</th><th>Title</th><th style="width: 10%">Rev</th><th style="width: 14%">Status</th><th style="width: 8%" class="r">Copies</th></tr></thead>
    <tbody>
    @foreach ($transmittal->items as $item)
        <tr>
            <td>{{ $item->version->drawing?->number }}</td>
            <td>{{ $item->version->drawing?->title }}</td>
            <td>{{ $item->version->revision }}</td>
            <td>{{ \Illuminate\Support\Str::headline($item->version->status) }}</td>
            <td class="r">{{ $item->copies }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="sign">
    <tr><td>Issued by</td><td class="gap"></td><td>Received by (name, date)</td></tr>
</table>
</body>
</html>
