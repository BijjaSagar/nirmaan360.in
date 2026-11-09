<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $note->number }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #16201f; }
        h1 { font-size: 16px; margin: 0; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .reason { margin-top: 16px; border: 1px solid #b9b2a2; padding: 8px; }
        .summary { width: 50%; margin-top: 16px; margin-left: auto; }
        .summary td { border-bottom: 1px solid #b9b2a2; padding: 5px; }
        .net td { font-weight: bold; font-size: 12px; border-top: 1.5px solid #16201f; border-bottom: 1.5px solid #16201f; }
        .effect { margin-top: 14px; font-weight: bold; }
        .sign { margin-top: 56px; }
        .sign td { width: 33%; text-align: center; border-top: 1px solid #16201f; padding-top: 4px; }
        .gap { width: 3%; border: 0 !important; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $note->organization->name }}</h1>
            @if ($note->organization->address)<div class="muted">{{ $note->organization->address }}</div>@endif
            @if ($note->organization->gstin)<div class="muted">GSTIN: {{ $note->organization->gstin }}</div>@endif
        </td>
        <td class="r">
            <h1>{{ $note->label() }}</h1>
            <div>{{ $note->number }}</div>
            <div class="muted">Date: {{ $note->note_date->format('d M Y') }}</div>
            <div class="muted">Status: {{ str_replace('_', ' ', $note->status) }}</div>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding-top:10px">
            <strong>Project:</strong> {{ $note->project->name }} ({{ $note->project->code }})<br>
            <strong>{{ $note->bill->type === 'client' ? 'Client' : 'Subcontractor' }}:</strong> {{ $note->party?->name ?? '—' }}
            @if ($note->party?->gstin) · GSTIN {{ $note->party->gstin }}@endif<br>
            <strong>Against bill:</strong> {{ $note->bill->number }} ({{ $note->bill->period_from->format('d M Y') }} to {{ $note->bill->period_to->format('d M Y') }})
        </td>
    </tr>
</table>

<div class="reason"><strong>Reason:</strong> {{ $note->reason }}</div>

<table class="summary">
    <tr><td>Amount before GST</td><td class="r">{{ inr($note->taxable_amount) }}</td></tr>
    <tr><td>GST ({{ (float) $note->gst_pct }}%)</td><td class="r">{{ inr($note->gst_amount) }}</td></tr>
    <tr class="net"><td>Note total</td><td class="r">{{ inr($note->total_amount) }}</td></tr>
</table>

<p class="effect">
    @if ($note->bill->type === 'client')
        {{ $note->lowersPosition() ? 'Reduces the amount due from the client on this bill.' : 'Increases the amount due from the client on this bill.' }}
    @else
        {{ $note->lowersPosition() ? 'Reduces the amount due to the subcontractor on this bill.' : 'Increases the amount due to the subcontractor on this bill.' }}
    @endif
</p>

<table class="sign">
    <tr>
        <td>Prepared by</td><td class="gap"></td>
        <td>Checked by</td><td class="gap"></td>
        <td>Approved by</td>
    </tr>
</table>
</body>
</html>
