<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    {{-- The description, canonical address, language versions and share tags come from modules/seo/head, inside this include. --}}
    @include('layouts.partials.head', ['siteFonts' => true])
    @include('modules.seo.title')
</head>
<body class="site font-sans text-ink antialiased">
@if (site_setting('announcement'))
    <div class="bg-ink px-5 py-2 text-center text-sm text-paper">
        @if (site_setting('announcement_link'))
            <a href="{{ site_setting('announcement_link') }}" class="underline underline-offset-2">{{ site_setting('announcement') }}</a>
        @else
            {{ site_setting('announcement') }}
        @endif
    </div>
@endif
<header class="sticky top-0 z-40 bg-paper/90 backdrop-blur" x-data="{ open: false }">
    <div class="site-wrap flex h-[72px] items-center justify-between gap-6">
        <a href="{{ route('home') }}" aria-label="{{ config('buildos.brand') }}"><x-brand tone="dark" /></a>
        <nav class="hidden items-center gap-7 text-[15px] text-ink-2 lg:flex">
            <a href="{{ route('home') }}#product" class="hover:text-ink">{{ __('Product') }}</a>
            <a href="{{ route('ai') }}" class="hover:text-ink">{{ __('AI agents') }}</a>
            <a href="{{ route('pricing') }}" class="hover:text-ink">{{ __('Pricing') }}</a>
            <a href="{{ route('calculators') }}" class="hover:text-ink">{{ __('Calculators') }}</a>
            <a href="{{ route('contact') }}" class="hover:text-ink">{{ __('Contact') }}</a>
        </nav>
        <div class="hidden items-center gap-4 lg:flex">
            <x-locale-switch />
            @auth
                <a href="{{ route('dashboard') }}" class="site-btn site-btn-primary py-2">{{ __('Open app') }}</a>
            @else
                <a href="{{ route('login') }}" class="text-[15px] text-ink-2 hover:text-ink">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="site-btn site-btn-primary py-2">{{ __('Start free') }}</a>
            @endauth
        </div>
        <button type="button" class="p-1 lg:hidden" @click="open = !open" aria-label="{{ __('Open menu') }}"><x-icon name="menu" class="size-6" /></button>
    </div>
    <div x-show="open" x-cloak class="border-t border-rule bg-paper lg:hidden">
        <div class="site-wrap space-y-3 py-4 text-[16px]">
            <a href="{{ route('home') }}#product" class="block">{{ __('Product') }}</a>
            <a href="{{ route('ai') }}" class="block">{{ __('AI agents') }}</a>
            <a href="{{ route('pricing') }}" class="block">{{ __('Pricing') }}</a>
            <a href="{{ route('calculators') }}" class="block">{{ __('Calculators') }}</a>
            <a href="{{ route('contact') }}" class="block">{{ __('Contact') }}</a>
            <x-locale-switch align="left" />
            <div class="flex gap-2 pt-1">
                @auth
                    <a href="{{ route('dashboard') }}" class="site-btn site-btn-primary">{{ __('Open app') }}</a>
                @else
                    <a href="{{ route('login') }}" class="site-btn site-btn-outline">{{ __('Sign in') }}</a>
                    <a href="{{ route('register') }}" class="site-btn site-btn-primary">{{ __('Start free') }}</a>
                @endauth
            </div>
        </div>
    </div>
</header>

@yield('content')

<footer class="bg-ink text-paper/65">
    <div class="site-wrap grid gap-10 py-16 md:grid-cols-[1.5fr_1fr_1fr_1fr]">
        <div>
            <x-brand lazy />
            <p class="mt-4 max-w-xs text-[15px] leading-relaxed">{{ __('Material, contractor payment and time, tracked against the BOQ. For builders and contractors in India.') }}</p>
            <ul class="mt-4 space-y-1.5 text-[15px]">
                @if (site_setting('contact_email'))<li class="flex items-center gap-2"><x-icon name="mail" class="size-4" /><a href="mailto:{{ site_setting('contact_email') }}" class="hover:text-paper">{{ site_setting('contact_email') }}</a></li>@endif
                @if (site_setting('info_email'))<li class="flex items-center gap-2"><x-icon name="mail" class="size-4" /><a href="mailto:{{ site_setting('info_email') }}" class="hover:text-paper">{{ site_setting('info_email') }}</a></li>@endif
                @if (site_setting('contact_phone'))<li class="flex items-center gap-2"><x-icon name="call" class="size-4" /><a href="tel:{{ preg_replace('/[^0-9+]/', '', site_setting('contact_phone')) }}" class="hover:text-paper">{{ site_setting('contact_phone') }}</a></li>@endif
                @if (site_setting('contact_address'))<li class="flex items-start gap-2"><x-icon name="pin" class="mt-1 size-4" /><span>{{ site_setting('contact_address') }}</span></li>@endif
            </ul>
            <div class="mt-4 flex gap-3">
                @foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'x' => 'X'] as $network => $label)
                    @if (site_setting('social_'.$network))
                        <a href="{{ site_setting('social_'.$network) }}" target="_blank" rel="noopener" aria-label="{{ $label }}" class="hover:text-paper"><x-icon :name="$network" /></a>
                    @endif
                @endforeach
            </div>
        </div>
        <div>
            <p class="text-sm text-paper">{{ __('Product') }}</p>
            <ul class="mt-3 space-y-2 text-[15px]">
                <li><a href="{{ route('home') }}#product" class="hover:text-paper">{{ __('Features') }}</a></li>
                <li><a href="{{ route('pricing') }}" class="hover:text-paper">{{ __('Pricing') }}</a></li>
                <li><a href="{{ route('ai') }}" class="hover:text-paper">{{ __('AI agents') }}</a></li>
            </ul>
        </div>
        <div>
            <p class="text-sm text-paper">{{ __('Free tools') }}</p>
            <ul class="mt-3 space-y-2 text-[15px]">
                <li><a href="{{ route('calculators') }}" class="hover:text-paper">{{ __('Concrete and steel') }}</a></li>
                <li><a href="{{ route('calculators') }}" class="hover:text-paper">{{ __('Brickwork and plaster') }}</a></li>
                <li><a href="{{ route('calculators') }}" class="hover:text-paper">{{ __('Cut and fill') }}</a></li>
            </ul>
        </div>
        <div>
            <p class="text-sm text-paper">{{ __('Company') }}</p>
            <ul class="mt-3 space-y-2 text-[15px]">
                <li><a href="{{ route('contact') }}" class="hover:text-paper">{{ __('Contact') }}</a></li>
                <li><a href="{{ route('privacy') }}" class="hover:text-paper">{{ __('Privacy') }}</a></li>
                <li><a href="{{ route('terms') }}" class="hover:text-paper">{{ __('Terms') }}</a></li>
                @if (app(\App\Services\Seo\Analytics::class)->measurementId())
                    {{-- Opens the analytics question again, so a choice can be changed at any time. --}}
                    <li><button type="button" data-consent-open class="cursor-pointer hover:text-paper">{{ __('Cookie settings') }}</button></li>
                @endif
            </ul>
        </div>
    </div>
    <div class="border-t border-paper/10">
        <div class="site-wrap flex flex-wrap items-center justify-between gap-3 py-5 text-sm">
            <p>© {{ now()->year }} {{ config('buildos.brand') }}</p>
            <p lang="hi">निर्माण का पूरा हिसाब, एक जगह।</p>
        </div>
    </div>
</footer>
</body>
</html>
