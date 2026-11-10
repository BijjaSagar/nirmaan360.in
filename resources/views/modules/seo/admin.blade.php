@extends('layouts.app')
@section('title', __('Search and analytics'))
@section('content')
    <x-page-header :title="__('Search and analytics')" :subtitle="__('How the website shows up in search results, and how visits are counted')" />
    @include('admin._tabs')

    <script>
        // Draws each page's search result as it will look, with the built-in wording wherever a box is empty.
        function seoPreview(row) {
            const fill = (text) => text.split(':brand').join(row.brand).split(':days').join(row.days);

            return {
                title: row.title,
                description: row.description,
                get shownTitle() {
                    const title = fill(this.title.trim() || row.builtInTitle);

                    return title.toLowerCase().includes(row.brand.toLowerCase()) ? title : title + ' · ' + row.brand;
                },
                get shownDescription() {
                    return fill(this.description.trim() || row.builtInDescription);
                },
            };
        }
    </script>

    <form method="POST" action="{{ route('admin.seo.update') }}" enctype="multipart/form-data" class="grid max-w-5xl gap-5 lg:grid-cols-2">
        @csrf @method('PATCH')
        <input type="hidden" name="locale" value="{{ $locale }}">

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Google Analytics 4') }}</h2>
                <span class="stamp {{ $measurementId ? 'border-moss text-moss' : 'border-ink-3 text-ink-3' }}">{{ $measurementId ? __('On') : __('Off') }}</span>
            </div>
            <div class="space-y-3 p-4">
                <x-field :label="__('Measurement ID')" name="ga4_measurement_id" :hint="__('It starts with G-. In Google Analytics it is under Admin, then Data streams. Leave blank to switch analytics off.')">
                    <input id="ga4_measurement_id" name="ga4_measurement_id" value="{{ old('ga4_measurement_id', $measurementId) }}" class="input num" placeholder="G-XXXXXXXXXX" autocomplete="off" spellcheck="false">
                </x-field>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="ga4_include_app" value="1" class="mt-1" @checked(old('ga4_include_app', $includeApp))>
                    <span>{{ __('Also count signed-in app screens') }}<span class="hint block">{{ __('Off unless you tick it: only website pages are counted, plus a finished sign-up and a started plan checkout. Contractor logins are never counted.') }}</span><span class="hint block">{{ __('Before you tick it, open Google Analytics, Admin, Data streams and switch off Enhanced measurement for site search, outbound clicks, file downloads and form interactions. Left on, Google\'s own code could pick up what is typed in a search box or the text of a link on an app screen.') }}</span></span>
                </label>
                <ul class="list-disc space-y-1.5 border-t border-rule pt-3 pl-5 text-sm text-ink-2">
                    <li>{{ __('Visitors are asked first. Google\'s code loads only after they choose Accept.') }}</li>
                    <li>{{ __('This site sends no names, emails, phone numbers, company or project names, or amounts.') }}</li>
                    <li>{{ __('Counted: sign-up started, sign-up completed, plan checkout started, contact form sent and calculator used.') }}</li>
                </ul>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Search engines') }}</h2></div>
            <div class="space-y-3 p-4">
                <p class="text-sm text-ink-2">{{ __('Each search engine gives you a meta tag to prove the website is yours. Paste only the code from inside content="…". All three are optional.') }}</p>
                @foreach (['google_site_verification' => 'Google Search Console code', 'bing_site_verification' => 'Bing Webmaster Tools code', 'yandex_site_verification' => 'Yandex Webmaster code'] as $field => $label)
                    <x-field :label="__($label)" :name="$field">
                        <input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $verification[$field]) }}" class="input num" autocomplete="off" spellcheck="false">
                    </x-field>
                @endforeach
                <div>
                    <p class="label">{{ __('Sitemap to submit in Search Console') }}</p>
                    <p class="num rounded-[3px] bg-paper-2/60 px-2.5 py-1.5 text-xs break-all"><a href="{{ route('seo.sitemap') }}" target="_blank" rel="noopener" class="link">{{ route('seo.sitemap') }}</a></p>
                    <p class="hint">{{ __('It lists every website page in each language and updates by itself.') }} <a href="{{ route('seo.robots') }}" target="_blank" rel="noopener" class="link">robots.txt</a></p>
                </div>
                <p class="border-t border-rule pt-3 text-sm text-ink-2">{{ __('Only the website pages below can appear in search results. Every signed-in screen tells search engines not to list it.') }}</p>
            </div>
        </section>

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Share picture') }}</h2></div>
            <div class="grid gap-4 p-4 sm:grid-cols-[minmax(0,320px)_1fr]">
                <img src="{{ $shareImage['url'] }}" alt="{{ __('Share picture') }}" @if ($shareImage['width'] && $shareImage['height']) width="{{ $shareImage['width'] }}" height="{{ $shareImage['height'] }}" @endif class="h-auto w-full rounded-[3px] border border-rule">
                <div class="space-y-3">
                    <p class="text-sm text-ink-2">{{ __('Shown when a page is shared on WhatsApp, LinkedIn, Facebook or X.') }}</p>
                    <x-field :label="__('Use your own picture')" name="share_image" :hint="__('PNG or JPG, up to 2 MB. 1200 × 630 pixels works best.')">
                        <input id="share_image" name="share_image" type="file" accept="image/png,image/jpeg" class="input">
                    </x-field>
                    @if ($hasOwnShareImage)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_share_image" value="1"> {{ __('Go back to the built-in picture') }}</label>
                    @endif
                </div>
            </div>
        </section>

        <section class="card lg:col-span-2">
            <div class="card-head">
                <h2 class="card-title">{{ __('Page titles and descriptions') }}</h2>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Change language') }}">
                    @foreach (config('buildos.locales') as $code => $label)
                        <a href="{{ route('admin.seo', ['locale' => $code]) }}" lang="{{ $code }}" class="btn {{ $locale === $code ? 'btn-ink' : 'btn-ghost' }} py-0.5 text-xs" @if ($locale === $code) aria-current="true" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="space-y-1 border-b border-rule px-4 py-2.5 text-sm text-ink-3">
                <p>{{ __('The title and description are what people read in search results and when a link is shared. Leave a box empty to use the built-in wording. Save before you switch language.') }}</p>
                <p>{{ __('Search engines cut off anything longer than the counts shown. You can write :brand for the site name and :days for the trial length.') }}</p>
            </div>
            @foreach ($rows as $row)
                <div class="grid gap-4 border-b border-rule p-4 last:border-b-0 lg:grid-cols-2" x-data="seoPreview(@js([
                    'title' => (string) old('pages.'.$row['page'].'.title', $row['title']),
                    'description' => (string) old('pages.'.$row['page'].'.description', $row['description']),
                    'builtInTitle' => $row['builtInTitle'],
                    'builtInDescription' => $row['builtInDescription'],
                    'brand' => config('buildos.brand'),
                    'days' => (string) config('buildos.trial_days'),
                ]))">
                    <div class="space-y-3">
                        <div>
                            <p class="font-semibold">{{ __($row['label']) }}</p>
                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="link text-xs break-all">{{ $row['url'] }}</a>
                            @unless ($row['ownAddress'])
                                <p class="hint">{{ __('This page has one address. These words show to visitors who have chosen this language.') }}</p>
                            @endunless
                        </div>
                        <x-field :label="__('Title')" :name="'pages.'.$row['page'].'.title'">
                            <input name="pages[{{ $row['page'] }}][title]" x-model="title" value="{{ old('pages.'.$row['page'].'.title', $row['title']) }}" maxlength="120" class="input" placeholder="{{ $row['builtInTitle'] }}" aria-label="{{ __('Title') }}: {{ __($row['label']) }}">
                        </x-field>
                        <x-field :label="__('Description')" :name="'pages.'.$row['page'].'.description'">
                            <textarea name="pages[{{ $row['page'] }}][description]" x-model="description" rows="3" maxlength="320" class="input" placeholder="{{ $row['builtInDescription'] }}" aria-label="{{ __('Description') }}: {{ __($row['label']) }}">{{ old('pages.'.$row['page'].'.description', $row['description']) }}</textarea>
                        </x-field>
                    </div>
                    <div>
                        <p class="label">{{ __('In search results') }}</p>
                        <div class="rounded-[3px] border border-rule bg-white p-3">
                            <p class="truncate text-xs text-ink-3">{{ $row['url'] }}</p>
                            <p class="mt-1 text-[17px] leading-snug font-medium text-neel" x-text="shownTitle">{{ $row['builtInTitle'] }}</p>
                            <p class="mt-1 text-[13px] leading-snug text-ink-2" x-text="shownDescription">{{ $row['builtInDescription'] }}</p>
                        </div>
                        <p class="hint num">
                            {{ __('Title') }} <span :class="shownTitle.length > {{ \App\Services\Seo\SeoText::TITLE_LENGTH }} ? 'font-semibold text-laterite' : ''" x-text="shownTitle.length"></span> / {{ \App\Services\Seo\SeoText::TITLE_LENGTH }}
                            · {{ __('Description') }} <span :class="shownDescription.length > {{ \App\Services\Seo\SeoText::DESCRIPTION_LENGTH }} ? 'font-semibold text-laterite' : ''" x-text="shownDescription.length"></span> / {{ \App\Services\Seo\SeoText::DESCRIPTION_LENGTH }}
                        </p>
                    </div>
                </div>
            @endforeach
        </section>

        <div class="lg:col-span-2"><button class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>
@endsection
