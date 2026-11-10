{{--
    Search and sharing tags, then analytics. Included by layouts/partials/head.blade.php, so every page
    of the website and the app passes through here. A page is hidden from search engines unless it is a
    listed website page (App\Services\Seo\PublicPages), which keeps every signed-in screen out of search.
--}}
@php
    // An error page is drawn with $exception set. It is never listed, and never counted as the page that failed.
    $seoFailed = isset($exception);
    $seo = app(\App\Services\Seo\Seo::class)->meta(request(), $seoFailed);
    $seoLocales = app(\App\Services\Seo\Seo::class)->shareLocales();
@endphp
<meta name="robots" content="{{ $seo->robots }}">
@if ($seo->listed())
    <meta name="description" content="{{ $seo->description }}">
    <link rel="canonical" href="{{ $seo->canonical }}">
    @foreach ($seo->alternates as $language => $address)
        <link rel="alternate" hreflang="{{ $language }}" href="{{ $address }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('buildos.brand') }}">
    <meta property="og:title" content="{{ $seo->shareTitle }}">
    <meta property="og:description" content="{{ $seo->description }}">
    <meta property="og:url" content="{{ $seo->canonical }}">
    <meta property="og:locale" content="{{ $seoLocales[$seo->locale] ?? $seo->locale }}">
    @foreach ($seo->alternates as $language => $address)
        @if (isset($seoLocales[$language]) && $language !== $seo->locale)
            <meta property="og:locale:alternate" content="{{ $seoLocales[$language] }}">
        @endif
    @endforeach
    <meta property="og:image" content="{{ $seo->image['url'] }}">
    @if ($seo->image['type'])<meta property="og:image:type" content="{{ $seo->image['type'] }}">@endif
    @if ($seo->image['width'] && $seo->image['height'])
        <meta property="og:image:width" content="{{ $seo->image['width'] }}">
        <meta property="og:image:height" content="{{ $seo->image['height'] }}">
    @endif
    <meta property="og:image:alt" content="{{ config('buildos.brand') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo->shareTitle }}">
    <meta name="twitter:description" content="{{ $seo->description }}">
    <meta name="twitter:image" content="{{ $seo->image['url'] }}">
    <meta name="twitter:image:alt" content="{{ config('buildos.brand') }}">
    {{-- Ownership codes for Google, Bing and Yandex, saved in /admin/seo. Website pages only. --}}
    @foreach ($seo->verification as $name => $code)
        <meta name="{{ $name }}" content="{{ $code }}">
    @endforeach
    @foreach ($seo->structuredData as $block)
        <script type="application/ld+json">{!! json_encode($block, \App\Services\Seo\StructuredData::JSON) !!}</script>
    @endforeach
@endif
@include('modules.seo.analytics', ['seoFailed' => $seoFailed])
