<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Daily progress report') }} {{ $dpr->report_date->format('d M Y') }}</title>
    <style>
        @page { margin: 28px 30px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #16201f; }
        h1 { font-size: 15px; margin: 0; }
        h2 { font-size: 10px; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: 0.6px; border-bottom: 1.5px solid #16201f; padding-bottom: 2px; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .head td { vertical-align: top; }
        .grid td { border-bottom: 1px solid #b9b2a2; padding: 4px 5px; vertical-align: top; }
        .grid th { background: #ebe5d7; border-top: 1px solid #16201f; border-bottom: 1px solid #16201f; padding: 4px 5px; text-align: left; font-size: 8px; }
        .r, .grid th.r { text-align: right; white-space: nowrap; }
        .meta td { padding: 2px 8px 2px 0; vertical-align: top; }
        .meta .k { color: #6b7773; width: 70px; }
        .text { white-space: pre-line; }
        .photos td { width: 33.33%; padding: 3px; vertical-align: top; }
        .photos img { width: 100%; }
        .cap { font-size: 7.5px; color: #4d5a56; margin-top: 2px; }
        .sign { margin-top: 38px; }
        .sign td { width: 31%; text-align: center; border-top: 1px solid #16201f; padding-top: 4px; }
        .gap { width: 3.5%; border: 0 !important; }
        .status { border: 1px solid #16201f; padding: 1px 5px; font-size: 8px; font-weight: bold; }
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
            <h1>{{ __('Daily progress report') }}</h1>
            <div>{{ $dpr->report_date->format('d M Y') }}</div>
            <div><span class="status">{{ strtoupper(str_replace('_', ' ', $dpr->status)) }}</span></div>
        </td>
    </tr>
</table>

<table class="meta" style="margin-top: 10px;">
    <tr><td class="k">{{ __('Project') }}</td><td><strong>{{ $project?->name }}</strong> ({{ $project?->code }})</td><td class="k">{{ __('Weather') }}</td><td>{{ $dpr->weather ?: '—' }}</td></tr>
    <tr><td class="k">{{ __('Site') }}</td><td>{{ $site?->name ?: '—' }}</td><td class="k">{{ __('Prepared by') }}</td><td>{{ $author ?: '—' }}</td></tr>
</table>

<h2>{{ __('Summary of work') }}</h2>
<div class="text">{{ $dpr->summary ?: '—' }}</div>

<h2>{{ __('Work done') }}</h2>
@if ($work->isEmpty())
    <div class="muted">{{ __('No work items recorded.') }}</div>
@else
    <table class="grid">
        <thead><tr><th style="width: 12%">{{ __('BOQ item') }}</th><th>{{ __('Description') }}</th><th class="r" style="width: 18%">{{ __('Quantity') }}</th></tr></thead>
        <tbody>
        @foreach ($work as $row)
            <tr><td>{{ $row['code'] ?: '—' }}</td><td>{{ $row['description'] }}</td><td class="r">{{ fmt_qty($row['qty']) }} {{ $row['unit'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

<table style="margin-top: 0;">
    <tr>
        <td style="width: 49%; vertical-align: top; padding-right: 8px;">
            <h2>{{ __('Manpower') }} ({{ $manpower->sum('count') }})</h2>
            @if ($manpower->isEmpty())
                <div class="muted">{{ __('None recorded.') }}</div>
            @else
                <table class="grid">
                    <thead><tr><th>{{ __('Trade') }}</th><th class="r" style="width: 22%">{{ __('Count') }}</th></tr></thead>
                    <tbody>
                    @foreach ($manpower as $row)<tr><td>{{ __($row->trade) }}</td><td class="r">{{ $row->count }}</td></tr>@endforeach
                    <tr><td><strong>{{ __('Total') }}</strong></td><td class="r"><strong>{{ $manpower->sum('count') }}</strong></td></tr>
                    </tbody>
                </table>
            @endif
        </td>
        <td style="width: 49%; vertical-align: top; padding-left: 8px;">
            <h2>{{ __('Machinery') }}</h2>
            @if ($machinery->isEmpty())
                <div class="muted">{{ __('None recorded.') }}</div>
            @else
                <table class="grid">
                    <thead><tr><th>{{ __('Machine') }}</th><th class="r" style="width: 28%">{{ __('Hours') }}</th></tr></thead>
                    <tbody>
                    @foreach ($machinery as $row)<tr><td>{{ $row->name }}</td><td class="r">{{ $row->hours }}</td></tr>@endforeach
                    </tbody>
                </table>
            @endif
        </td>
    </tr>
</table>

<h2>{{ __('Hindrances') }}</h2>
<div class="text">{{ $dpr->hindrances ?: '—' }}</div>

@if ($photos->isNotEmpty())
    <h2>{{ __('Photos') }}</h2>
    <table class="photos">
        @foreach ($photos->chunk(3) as $chunk)
            <tr>
                @foreach ($chunk as $photo)
                    <td>
                        <img src="{{ $photo['src'] }}" alt="">
                        @if ($photo['caption'] || $photo['taken_at'])
                            <div class="cap">{{ $photo['caption'] }}@if ($photo['caption'] && $photo['taken_at']) · @endif{{ $photo['taken_at']?->format('d M Y H:i') }}</div>
                        @endif
                    </td>
                @endforeach
                @for ($fill = $chunk->count(); $fill < 3; $fill++)<td></td>@endfor
            </tr>
        @endforeach
    </table>
    @if ($morePhotos > 0)
        <div class="muted" style="margin-top: 4px;">{{ __(':count more photos are in the app.', ['count' => $morePhotos]) }}</div>
    @endif
@endif

@if ($steps->isNotEmpty())
    <h2>{{ __('Approval trail') }}</h2>
    <table class="grid">
        <thead><tr><th>{{ __('Level') }}</th><th>{{ __('By') }}</th><th>{{ __('Action') }}</th><th>{{ __('When') }}</th><th>{{ __('Remark') }}</th></tr></thead>
        <tbody>
        @foreach ($steps as $step)
            <tr>
                <td>{{ __($step['role']) }}</td>
                <td>{{ $step['actor'] }}</td>
                <td>{{ __(\Illuminate\Support\Str::headline((string) $step['action'])) }}</td>
                <td>{{ $step['at']?->format('d M Y H:i') }}</td>
                <td>{{ $step['remark'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<table class="sign">
    <tr>
        <td>{{ __('Prepared by') }}</td><td class="gap"></td>
        <td>{{ __('Checked by') }}</td><td class="gap"></td>
        <td>{{ __('Approved by') }}</td>
    </tr>
</table>
</body>
</html>
