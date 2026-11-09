@extends('layouts.app')
@section('title', __('My devices'))
@section('content')
    <x-page-header :title="__('My devices')" :subtitle="__('Sign out a phone you have lost or no longer use.')" />

    @include('modules.access._devices', [
        'devices' => $devices,
        'revokeRoute' => fn ($id) => route('devices.destroy', $id),
        'revokeAllUrl' => route('devices.destroy-all'),
    ])
@endsection
