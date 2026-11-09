<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #16201f; font-size: 14px; line-height: 1.5;">
<p style="margin: 0 0 4px; font-size: 16px; font-weight: bold;">{{ $title }}</p>
<p style="margin: 0 0 12px; color: #4d5a56;">{{ $subtitle }}</p>
<p>{{ __('The report is attached as :file.', ['file' => $filename]) }}
    @if ($rowCount === 0) {{ __('It has no rows for this period.') }} @else {{ __(':count rows.', ['count' => $rowCount]) }} @endif
</p>
<p style="color: #6b7773; font-size: 12px;">
    {{ __('Sent for :company from a report schedule.', ['company' => $organizationName]) }}
    <a href="{{ $manageUrl }}">{{ __('Manage schedules') }}</a>
</p>
</body>
</html>
