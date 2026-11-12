@extends('layouts.app')
@section('title', __('Prepare update'))
@section('content')
    @php $languages = array_intersect_key(config('buildos.locales'), array_flip(\App\Models\OwnerUpdate::LANGUAGES)); @endphp

    <x-page-header :title="__('Prepare update')" :subtitle="$project->name" />

    <form method="POST" action="{{ route('owner-updates.store', $project) }}" class="space-y-5">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
        <section class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field :label="__('First day of the week')" name="week_start" :hint="__('The update covers seven days from this day. Only daily reports that are approved are read.')">
                <input id="week_start" name="week_start" type="date" value="{{ old('week_start', $weekStart) }}" max="{{ today()->toDateString() }}" class="input" required>
            </x-field>
            <x-field :label="__('Language of the update')" name="language">
                <select id="language" name="language" class="input" required>
                    @foreach ($languages as $code => $label)
                        <option value="{{ $code }}" lang="{{ $code }}" @selected(old('language', $language) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>
            @can('money.view')
                @include('modules.owner_pack.updates._money-fields', ['update' => null])
            @endcan
        </section>

        <button class="btn btn-primary">{{ __('Prepare draft') }}</button>
        <p class="hint">{{ __('Nothing is sent to the client from here. You pick the photos on the next screen, a named person approves the update, and you send the link yourself.') }}</p>
    </form>
@endsection
