@extends('layouts.auth')
@section('title', __('Invitation closed'))
@section('content')
    <h1 class="text-4xl">{{ __('Invitation closed') }}</h1>
    <p class="mt-3 text-sm text-ink-3">{{ $message }}</p>
    <a href="{{ route('login') }}" class="btn btn-primary mt-6 w-full">{{ __('Go to sign in') }}</a>
@endsection
