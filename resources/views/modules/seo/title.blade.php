{{-- A listed website page takes its title from /admin/seo. Any other page keeps the title its own view gave. --}}
@php
    $seoTitle = app(\App\Services\Seo\Seo::class)->title(request(), isset($exception));
@endphp
<title>{!! $seoTitle !== null ? e($seoTitle) : $__env->yieldContent('title').' · '.e(config('buildos.brand')) !!}</title>
