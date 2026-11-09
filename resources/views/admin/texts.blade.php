@extends('layouts.app')
@section('title', __('Website text'))
@section('content')
    <x-page-header :title="__('Website text')" :subtitle="__('Change any wording on the website, in any language')" />
    @include('admin._tabs')

    <div x-data="{ search: '' }">
        <form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
            <x-field :label="__('Page')" name="page">
                <select id="page" name="page" class="input" onchange="this.form.submit()">@foreach ($pages as $key => $label)<option value="{{ $key }}" @selected($page === $key)>{{ __($label) }}</option>@endforeach</select>
            </x-field>
            <x-field :label="__('Language')" name="locale">
                <select id="locale" name="locale" class="input" onchange="this.form.submit()">@foreach (config('buildos.locales') as $code => $label)<option value="{{ $code }}" @selected($locale === $code)>{{ $label }}</option>@endforeach</select>
            </x-field>
            <x-field :label="__('Find')" name="find" class="min-w-56 flex-1"><input id="find" x-model="search" type="search" class="input" placeholder="{{ __('Type a few words') }}"></x-field>
        </form>

        <form method="POST" action="{{ route('admin.texts.update') }}">
            @csrf @method('PATCH')
            <input type="hidden" name="page" value="{{ $page }}">
            <input type="hidden" name="locale" value="{{ $locale }}">
            <div class="card">
                @foreach ($rows as $row)
                    <div class="grid gap-2 border-b border-rule p-3 last:border-b-0 md:grid-cols-2" data-text="{{ mb_strtolower($row['key'].' '.$row['default'].' '.$row['saved']) }}" x-show="!search || $el.dataset.text.includes(search.toLowerCase())">
                        <p class="text-sm text-ink-2">{{ $row['default'] }}</p>
                        <textarea name="texts[{{ $row['hash'] }}]" rows="{{ mb_strlen($row['default']) > 70 ? 3 : 1 }}" class="input" placeholder="{{ __('Keep the current wording') }}" aria-label="{{ $row['default'] }}">{{ old('texts.'.$row['hash'], $row['saved']) }}</textarea>
                    </div>
                @endforeach
            </div>
            <p class="hint mt-2">{{ __('Leave a box empty to go back to the original wording. Keep words starting with a colon, like :count, exactly as they are.') }}</p>
            <button class="btn btn-primary mt-3">{{ __('Save text') }}</button>
        </form>
    </div>
@endsection
