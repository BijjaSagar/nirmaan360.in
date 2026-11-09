@extends('layouts.app')
@section('title', __('Admins'))
@section('content')
    <x-page-header :title="__('Admins')" :subtitle="__('People who can open this admin area')" />
    @include('admin._tabs')

    <form method="POST" action="{{ route('admin.admins.store') }}" class="card mb-5 flex max-w-2xl flex-wrap items-end gap-3 p-4">
        @csrf
        <x-field :label="__('Email of a registered account')" name="email" class="min-w-64 flex-1"><input id="email" name="email" type="email" value="{{ old('email') }}" class="input" required autocomplete="off"></x-field>
        <button class="btn btn-primary">{{ __('Add admin') }}</button>
    </form>

    <div class="card max-w-2xl">
        @foreach ($admins as $admin)
            <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                <span class="min-w-0"><span class="font-semibold">{{ $admin->name }}</span> <span class="text-ink-3">· {{ $admin->email }} · {{ $admin->organization?->name }}</span></span>
                @unless ($admin->is(auth()->user()))
                    <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}" onsubmit="return confirm(@js(__('Remove this admin?')))">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                @endunless
            </div>
        @endforeach
    </div>
@endsection
