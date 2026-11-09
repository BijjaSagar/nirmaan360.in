@extends('layouts.app')
@section('title', __('Website'))
@section('content')
    <x-page-header :title="__('Website')" :subtitle="__('Brand, contact details and page sections')" />
    @include('admin._tabs')

    <form method="POST" action="{{ route('admin.website.update') }}" enctype="multipart/form-data" class="grid max-w-5xl gap-5 lg:grid-cols-2">
        @csrf

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Brand') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-3">
                <x-field :label="__('Company name')" name="site_name" class="sm:col-span-3" :hint="__('Leave blank to keep Nirmaan360.')"><input id="site_name" name="site_name" value="{{ old('site_name', $values['site_name']) }}" class="input"></x-field>
                <x-field :label="__('Logo')" name="logo" class="sm:col-span-3" :hint="__('PNG, JPG or WebP, up to 1 MB.')">
                    @if ($hasLogo)<img src="{{ route('brand.logo') }}" alt="" class="mb-2 h-10 w-auto">@endif
                    <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="input">
                    @if ($hasLogo)<label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1"> {{ __('Remove logo') }}</label>@endif
                </x-field>
                @foreach (['color_primary' => ['Main colour', '#b4451f'], 'color_primary_dark' => ['Button hover colour', '#8f3416'], 'color_accent' => ['Accent colour', '#e2a31a']] as $field => [$label, $default])
                    <x-field :label="__($label)" :name="$field">
                        <input name="{{ $field }}" type="color" value="{{ old($field, $values[$field] ?? $default) }}" class="h-9 w-full rounded-[3px] border border-rule bg-sheet p-1" aria-label="{{ __($label) }}">
                    </x-field>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Contact details') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Email')" name="contact_email"><input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $values['contact_email']) }}" class="input"></x-field>
                <x-field :label="__('Second email')" name="info_email"><input id="info_email" name="info_email" type="email" value="{{ old('info_email', $values['info_email']) }}" class="input"></x-field>
                <x-field :label="__('Mobile')" name="contact_phone"><input id="contact_phone" name="contact_phone" value="{{ old('contact_phone', $values['contact_phone']) }}" class="input"></x-field>
                <x-field :label="__('Address')" name="contact_address" class="sm:col-span-2"><input id="contact_address" name="contact_address" value="{{ old('contact_address', $values['contact_address']) }}" class="input"></x-field>
                @foreach (['social_facebook' => 'Facebook', 'social_instagram' => 'Instagram', 'social_linkedin' => 'LinkedIn', 'social_youtube' => 'YouTube', 'social_x' => 'X'] as $field => $label)
                    <x-field :label="$label" :name="$field"><input name="{{ $field }}" type="url" value="{{ old($field, $values[$field]) }}" class="input" placeholder="https://" aria-label="{{ $label }}"></x-field>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Announcement bar') }}</h2></div>
            <div class="space-y-3 p-4">
                <x-field :label="__('Message')" name="announcement" :hint="__('Shown above the menu. Leave blank to hide it.')"><input id="announcement" name="announcement" value="{{ old('announcement', $values['announcement']) }}" class="input"></x-field>
                <x-field :label="__('Link (optional)')" name="announcement_link"><input id="announcement_link" name="announcement_link" type="url" value="{{ old('announcement_link', $values['announcement_link']) }}" class="input" placeholder="https://"></x-field>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Home page sections') }}</h2></div>
            <div class="grid gap-2 p-4 text-sm sm:grid-cols-2">
                @foreach ($sections as $section => $label)
                    <label class="flex items-center gap-2"><input type="checkbox" name="sections[{{ $section }}]" value="1" @checked(site_on($section))> {{ __($label) }}</label>
                @endforeach
            </div>
        </section>

        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Privacy and terms') }}</h2></div>
            <div class="grid gap-3 p-4 lg:grid-cols-2">
                <x-field :label="__('Privacy policy')" name="privacy_text" :hint="__('Leave blank to use the built-in text. Use ## for a heading.')"><textarea id="privacy_text" name="privacy_text" rows="10" class="input">{{ old('privacy_text', $values['privacy_text']) }}</textarea></x-field>
                <x-field :label="__('Terms of service')" name="terms_text" :hint="__('Leave blank to use the built-in text. Use ## for a heading.')"><textarea id="terms_text" name="terms_text" rows="10" class="input">{{ old('terms_text', $values['terms_text']) }}</textarea></x-field>
            </div>
        </section>

        <div class="lg:col-span-2"><button class="btn btn-primary">{{ __('Save website settings') }}</button></div>
    </form>
@endsection
