{{--
    Google Analytics 4. Draws nothing until a measurement ID is saved in /admin/seo, and even then
    Google's code is only fetched after the visitor accepts. App\Services\Seo\Analytics decides what
    this page may send; the script below sends nothing else.
--}}
@php
    $analyticsPage = app(\App\Services\Seo\Analytics::class)->forPage(request(), $seoFailed ?? false);
@endphp
@if ($analyticsPage)
    <script id="analytics-config" type="application/json">{!! json_encode($analyticsPage, \App\Services\Seo\StructuredData::JSON) !!}</script>
    @if ($analyticsPage['mode'] === 'pageview')
        {{-- Shown by the script only while the visitor has not chosen. Fixed to the bottom, so the page does not move. --}}
        <template id="analytics-consent">
            <div data-consent-banner role="region" aria-label="{{ __('Analytics cookies') }}" class="card fixed inset-x-3 bottom-3 z-50 mx-auto max-w-2xl p-4 text-[14px] leading-snug text-ink shadow-[0_12px_40px_-14px_rgb(20_20_19/0.4)] sm:flex sm:items-center sm:gap-5 sm:p-5">
                <p class="min-w-0 flex-1">{{ __('May we use Google Analytics cookies to count visits to this website? Nothing is set unless you accept.') }} <a href="{{ route('privacy') }}" class="underline underline-offset-2">{{ __('Privacy policy') }}</a></p>
                <div class="mt-3 flex shrink-0 gap-2 sm:mt-0">
                    <button type="button" data-consent="decline" class="btn btn-ghost">{{ __('Decline') }}</button>
                    <button type="button" data-consent="accept" class="btn btn-primary">{{ __('Accept') }}</button>
                </div>
            </div>
        </template>
    @endif
    @include('modules.seo.analytics-script')
@endif
