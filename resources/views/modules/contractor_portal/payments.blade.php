@extends('modules.contractor_portal.layout')
@section('title', __('My payments'))
@section('content')
    <x-page-header :title="__('Payments')" :subtitle="__('Every payment :company has made to you, newest first', ['company' => auth()->user()->organization->name])" />

    <section class="card">
        @forelse ($payments as $payment)
            @include('modules.contractor_portal._payment-row', ['payment' => $payment, 'withContract' => true])
        @empty
            <x-empty :title="__('No payments yet')" :note="__('A payment shows here once it has been approved.')" />
        @endforelse
    </section>
@endsection
