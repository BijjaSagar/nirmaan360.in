<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $run->number }}</title>
    <style>
        @page { margin: 24px 26px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #16201f; }
        h1 { font-size: 14px; margin: 0; }
        h2 { font-size: 11px; margin: 0 0 4px; letter-spacing: 1px; text-transform: uppercase; }
        .muted { color: #6b7773; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 5px; vertical-align: top; }
        .head td { padding: 0 0 2px; }
        .box { border: 1px solid #16201f; margin-top: 8px; }
        .box th { background: #ebe5d7; text-align: left; font-size: 8px; border-bottom: 1px solid #16201f; }
        .box td { border-bottom: 1px solid #d8d2c4; }
        .r { text-align: right; white-space: nowrap; }
        .net td { font-weight: bold; font-size: 11px; border-top: 1.5px solid #16201f; border-bottom: 0; background: #f4f0e6; }
        .words { margin-top: 8px; }
        .sign { margin-top: 38px; }
        .sign td { width: 45%; text-align: center; border-top: 1px solid #16201f; padding-top: 3px; }
        .gap { width: 10%; border: 0 !important; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
    </style>
</head>
<body>
@php $plain = \App\Support\Figure::trim(...); @endphp
@foreach ($lines as $line)
    <div class="page">
        <table class="head">
            <tr>
                <td>
                    <h1>{{ $run->organization->name }}</h1>
                    @if ($run->organization->address)<div class="muted">{{ $run->organization->address }}</div>@endif
                </td>
                <td class="r"><h2>Payslip</h2><div>{{ $run->number }}</div></td>
            </tr>
        </table>

        <table class="box">
            <tr>
                <td><span class="muted">Worker</span><br><strong>{{ $line->worker_name }}</strong></td>
                <td><span class="muted">Trade</span><br>{{ $line->trade }}</td>
                <td><span class="muted">Worker no.</span><br>{{ $line->worker_id }}</td>
            </tr>
            <tr>
                <td><span class="muted">Pay period</span><br>{{ $run->period_from->format('d M Y') }} to {{ $run->period_to->format('d M Y') }}</td>
                <td><span class="muted">Site</span><br>{{ $run->project?->name ?? 'All sites' }}</td>
                <td><span class="muted">Daily wage</span><br>{{ inr($line->daily_wage) }}</td>
            </tr>
        </table>

        <table class="box">
            <thead><tr><th>Attendance</th><th class="r">Days / hours</th></tr></thead>
            <tbody>
            <tr><td>Full days (present)</td><td class="r">{{ $plain($line->present_days) }}</td></tr>
            <tr><td>Half days</td><td class="r">{{ $plain($line->half_days) }}</td></tr>
            <tr><td>Absent</td><td class="r">{{ $plain($line->absent_days) }}</td></tr>
            <tr><td><strong>Payable days</strong></td><td class="r"><strong>{{ $plain($line->payable_days) }}</strong></td></tr>
            <tr><td>Overtime hours</td><td class="r">{{ $plain($line->ot_hours) }}</td></tr>
            </tbody>
        </table>

        <table class="box">
            <thead><tr><th>Earnings and deductions</th><th class="r">Amount</th></tr></thead>
            <tbody>
            <tr><td>Wages for {{ $plain($line->payable_days) }} days at {{ inr($line->daily_wage) }}</td><td class="r">{{ inr($line->day_pay) }}</td></tr>
            <tr><td>Overtime, {{ $plain($line->ot_hours) }} hours at {{ $plain($run->terms['ot_multiplier'] ?? 2) }} times the hourly rate</td><td class="r">{{ inr($line->ot_pay) }}</td></tr>
            <tr><td><strong>Gross wages</strong></td><td class="r"><strong>{{ inr($line->gross) }}</strong></td></tr>
            <tr><td>Less: advance taken back</td><td class="r">{{ inr($line->advance_recovery) }}</td></tr>
            <tr class="net"><td>Net pay</td><td class="r">{{ inr($line->net) }}</td></tr>
            </tbody>
        </table>

        <p class="words"><span class="muted">In words:</span> {{ \App\Support\RupeeWords::of($line->net) }}</p>

        <table class="sign">
            <tr><td>Worker's signature</td><td class="gap"></td><td>Authorised signatory</td></tr>
        </table>
        <p class="muted" style="margin-top:10px;font-size:7px">Approved {{ $run->approved_at?->format('d M Y') }}. This payslip was produced from attendance records and cannot be changed after approval.</p>
    </div>
@endforeach
</body>
</html>
