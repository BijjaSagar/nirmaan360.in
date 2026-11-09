@extends('layouts.app')
@section('title', __('Contacts'))
@section('content')
    <x-page-header :title="__('Contacts')" :subtitle="__('Clients, consultants, subcontractors and vendors')" />

    @can('party.manage')
        <form method="POST" action="{{ route('parties.store') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-6">
            @csrf
            <x-field :label="__('Type')" name="type">
                <select id="type" name="type" class="input">
                    @foreach (config('buildos.party_types') as $option)
                        <option value="{{ $option }}" @selected(old('type', $type) === $option)>{{ __(ucfirst($option)) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Name')" name="name" class="sm:col-span-2"><input id="name" name="name" value="{{ old('name') }}" class="input" required></x-field>
            <x-field :label="__('Contact person')" name="contact_person"><input id="contact_person" name="contact_person" value="{{ old('contact_person') }}" class="input"></x-field>
            <x-field :label="__('Mobile')" name="phone"><input id="phone" name="phone" value="{{ old('phone') }}" class="input" inputmode="tel"></x-field>
            <x-field :label="__('GSTIN')" name="gstin"><input id="gstin" name="gstin" value="{{ old('gstin') }}" maxlength="15" class="input num uppercase"></x-field>
            <x-field :label="__('Email')" name="email" class="sm:col-span-2"><input id="email" name="email" type="email" value="{{ old('email') }}" class="input"></x-field>
            <x-field :label="__('Address')" name="address" class="sm:col-span-3"><input id="address" name="address" value="{{ old('address') }}" class="input"></x-field>
            <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Add contact') }}</button></div>
        </form>
    @endcan

    <div class="mb-3 flex flex-wrap gap-1.5">
        <a href="{{ route('parties.index') }}" class="btn {{ $type ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
        @foreach (config('buildos.party_types') as $option)
            <a href="{{ route('parties.index', ['type' => $option]) }}" class="btn {{ $type === $option ? 'btn-ink' : 'btn-ghost' }}">{{ __(ucfirst($option)) }}</a>
        @endforeach
    </div>

    <div class="card overflow-x-auto">
        @if ($parties->isEmpty())
            <x-empty :title="__('No contacts yet')" />
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Type') }}</th><th>{{ __('Contact person') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('GSTIN') }}</th></tr></thead>
                <tbody>
                @foreach ($parties as $party)
                    <tr>
                        <td class="font-semibold">{{ $party->name }}</td>
                        <td>{{ __(ucfirst($party->type)) }}</td>
                        <td>{{ $party->contact_person ?? '—' }}</td>
                        <td class="num text-xs">{{ $party->phone ?? '—' }}</td>
                        <td class="num text-xs">{{ $party->gstin ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $parties->links() }}</div>
@endsection
