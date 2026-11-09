<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="margin:0;padding:24px;background:#f5f1e8;font-family:Arial,sans-serif;color:#1c1c1c">
    <div style="max-width:520px;margin:0 auto;background:#ffffff;padding:24px;border:1px solid #ddd5c4">
        <p style="margin:0 0 12px;font-size:13px;color:#6b6b6b">{{ config('buildos.brand') }}</p>
        <h1 style="margin:0 0 12px;font-size:20px">{{ $notice->title }}</h1>
        @if ($notice->body !== '')
            <p style="margin:0 0 16px;font-size:15px;line-height:1.5">{{ $notice->body }}</p>
        @endif
        @if ($notice->url)
            <p style="margin:0 0 16px"><a href="{{ $notice->url }}" style="color:#1f3a68;font-weight:bold">{{ __('Open in :brand', ['brand' => config('buildos.brand')]) }}</a></p>
        @endif
        <p style="margin:16px 0 0;font-size:12px;color:#6b6b6b">{{ __('You can choose how you get these updates under Notification settings.') }}</p>
    </div>
</body>
</html>
