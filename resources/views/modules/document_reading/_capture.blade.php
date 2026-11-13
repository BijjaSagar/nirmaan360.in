{{--
    Include this once on any screen that draws PDF pages or photos in the browser and uploads them
    to a read. It carries what the capture script needs and draws nothing itself:

        @include('modules.document_reading._capture')

    The script then offers window.ReaderCapture (upload, quote, start, watch, halves, self-check)
    and announces it with the event "reader-capture:ready" on document. Addresses hold __READ__ and
    __PAGE__ where the id of the read and of a page row go, so the screen does not need a read yet.
--}}
@php
    $readerCaptureUrls = [
        'status' => route('document-reads.status', ['read' => '__READ__'], false),
        'pages' => route('document-reads.pages.store', ['read' => '__READ__'], false),
        'source' => route('document-reads.source', ['read' => '__READ__'], false),
        'quote' => route('document-reads.quote', ['read' => '__READ__'], false),
        'start' => route('document-reads.start', ['read' => '__READ__'], false),
        'cancel' => route('document-reads.cancel', ['read' => '__READ__'], false),
        'resume' => route('document-reads.resume', ['read' => '__READ__'], false),
        'retry' => route('document-reads.pages.retry', ['read' => '__READ__', 'page' => '__PAGE__'], false),
        'split' => route('document-reads.pages.split', ['read' => '__READ__', 'page' => '__PAGE__'], false),
        'image' => route('document-reads.pages.image', ['read' => '__READ__', 'page' => '__PAGE__'], false),
    ];

    $readerCaptureWords = [
        'not_decoded' => __('Page :n could not be drawn in this browser. Export the scan at 300 dpi or take photos of the pages.'),
        'too_big' => __('Page :n is larger than A3 and cannot be read. Take photos of its parts instead.'),
        'blank' => __('Page :n looks blank and is left out.'),
        'pdf_failed' => __('This PDF could not be opened. If it has a password, remove the password and try again.'),
        'image_failed' => __('This picture could not be opened. Use a JPG, PNG or WebP picture.'),
        'draw_failed' => __('Page :n could not be turned into a picture in this browser.'),
        'upload_failed' => __('Page :n could not be uploaded. Check your connection and try again.'),
        'upload_size' => __('About :mb MB will be uploaded.'),
        // For a request that never reached the server: no connection, or the address could not be reached.
        'network' => __('Your request did not reach the server. Check your connection and try again.'),
        // For a failure that comes without a sentence the app wrote: a proxy's error page, an expired session, a throttle, a plain refusal.
        'signed_out' => __('You have been signed out. Sign in again, then try once more.'),
        'not_allowed' => __('You are not allowed to do this.'),
        'not_found' => __('This document is no longer here. Reload the page.'),
        'too_large' => __('This file is too large to upload.'),
        'too_many' => __('Too many tries in a short time. Wait a minute and try again.'),
        'server_failed' => __('Something went wrong on our side. Try again in a few minutes.'),
    ];
@endphp
<div hidden data-reader-capture
     data-assets="{{ asset(config('modules.document_reading.pdfjs_assets')) }}"
     data-half-share="{{ (int) config('modules.document_reading.half_page_share') }}"
     data-urls="{{ json_encode($readerCaptureUrls) }}"
     data-words="{{ json_encode($readerCaptureWords, JSON_UNESCAPED_UNICODE) }}"></div>
<noscript>
    <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ __('This screen needs JavaScript.') }}</p>
</noscript>
@vite('resources/js/reader/capture.js')
