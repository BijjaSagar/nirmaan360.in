@extends('layouts.app')
@section('title', __('New transmittal'))
@section('content')
    <x-page-header :title="__('New transmittal')" :subtitle="$project->name">
        <a href="{{ route('drawings.transmittals.index', $project) }}" class="btn btn-ghost">{{ __('Back') }}</a>
    </x-page-header>

    @if ($versions->isEmpty())
        <div class="card"><x-empty :title="__('Nothing to issue yet')" :note="__('Add drawings or documents to the register first.')" /></div>
    @else
        <form method="POST" action="{{ route('drawings.transmittals.store', $project) }}" class="space-y-5">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">

            <div class="card grid gap-3 p-4 sm:grid-cols-4">
                <x-field :label="__('Contact')" name="party_id" class="sm:col-span-2" :hint="__('Pick a contact, or type a name below.')">
                    <select id="party_id" name="party_id" class="input">
                        <option value="">—</option>
                        @foreach ($parties as $party)<option value="{{ $party->id }}" @selected((int) old('party_id') === $party->id)>{{ $party->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Recipient name')" name="recipient_name" class="sm:col-span-2"><input id="recipient_name" name="recipient_name" value="{{ old('recipient_name') }}" maxlength="160" class="input"></x-field>
                <x-field :label="__('Company')" name="recipient_company" class="sm:col-span-2"><input id="recipient_company" name="recipient_company" value="{{ old('recipient_company') }}" maxlength="160" class="input"></x-field>
                <x-field :label="__('Purpose')" name="purpose" class="sm:col-span-2">
                    <select id="purpose" name="purpose" class="input">
                        @foreach (config('modules.drawings.transmittal_purposes') as $purpose)
                            <option value="{{ $purpose }}" @selected(old('purpose', 'for_information') === $purpose)>{{ __(\Illuminate\Support\Str::headline($purpose)) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Subject')" name="subject" class="sm:col-span-4"><input id="subject" name="subject" value="{{ old('subject') }}" maxlength="200" class="input" required></x-field>
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-4"><textarea id="notes" name="notes" rows="2" maxlength="2000" class="input">{{ old('notes') }}</textarea></x-field>
            </div>

            <div class="card overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Current revisions to issue') }}</h2></div>
                @error('versions')<p class="error px-4 pt-2">{{ $message }}</p>@enderror
                <table class="ledger min-w-[620px]">
                    <thead><tr><th></th><th>{{ __('Number') }}</th><th class="w-[46%]">{{ __('Title') }}</th><th>{{ __('Rev') }}</th><th class="r">{{ __('Copies') }}</th></tr></thead>
                    <tbody>
                    @foreach ($versions as $version)
                        <tr>
                            <td><input type="checkbox" name="versions[]" value="{{ $version->id }}" aria-label="{{ $version->drawing->number }}" @checked(in_array($version->id, array_map('intval', (array) old('versions', [])), true))></td>
                            <td class="num text-xs">{{ $version->drawing->number }}</td>
                            <td>{{ $version->drawing->title }}</td>
                            <td class="num text-xs">{{ $version->revision }}</td>
                            <td class="text-right"><input type="number" name="copies[{{ $version->id }}]" value="{{ old('copies.'.$version->id, 1) }}" min="1" max="999" class="input num ml-auto w-20" aria-label="{{ __('Copies') }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div>
                <button class="btn btn-primary">{{ __('Issue transmittal') }}</button>
                <p class="hint">{{ __('An issued transmittal cannot be edited. It can only be acknowledged or cancelled.') }}</p>
            </div>
        </form>
    @endif
@endsection
