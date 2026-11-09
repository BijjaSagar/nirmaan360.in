@extends('modules.contractor_portal.layout')
@section('title', __('My bills'))
@section('content')
    <x-page-header :title="__('Bills')" :subtitle="__('Bills raised on your work orders and approved by :company', ['company' => auth()->user()->organization->name])" />

    <section class="card">
        @include('modules.contractor_portal._bills-table', ['bills' => $bills])
    </section>
@endsection
