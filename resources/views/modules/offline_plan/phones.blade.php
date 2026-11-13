@extends('layouts.app')
@section('title', __('Licensed phones'))
@section('content')
    {{-- The field app shows this page's address for freeing a phone (manage_url), so it holds only the phones:
         no plans, prices or billing button, which the app may not point people to (App Store guideline 3.1.1). --}}
    <x-page-header :title="__('Offline devices')" />

    @if ($canManage)
        @include('modules.offline_plan._phones')
    @else
        <section class="card p-5">
            <p class="text-sm text-ink-2">{{ __('Ask your company owner to free a phone on this page.') }}</p>
        </section>
    @endif
@endsection
