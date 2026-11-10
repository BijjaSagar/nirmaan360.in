{{-- A contractor login's own notification settings, drawn in the portal's frame. It is told by push only. --}}
@extends('modules.contractor_portal.layout')
@section('title', __('Notification settings'))
@section('content')
    <x-page-header :title="__('Notification settings')" :subtitle="__('Choose what the mobile app tells you about')" />

    <form method="POST" action="{{ route('contractor-portal.notifications.update') }}" class="max-w-2xl space-y-5">
        @csrf @method('PATCH')
        @include('modules.notify._push-choices', ['push' => $channels['push']])
        <p class="text-sm text-ink-3">{{ __('Phones signed in: :count', ['count' => $deviceCount]) }}</p>
        <button class="btn btn-primary" @disabled(! $channels['push']['connected'])>{{ __('Save') }}</button>
    </form>
@endsection
