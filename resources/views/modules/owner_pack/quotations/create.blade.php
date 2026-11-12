@extends('layouts.app')
@section('title', __('New quotation'))
@section('content')
    <x-page-header :title="__('New quotation')" :subtitle="$project->name" />

    @if ($boqItems->isEmpty())
        <div class="card"><x-empty :title="__('Add BOQ items first')" :note="__('A quotation is made from lines of the BOQ.')"><a href="{{ route('boq.index', $project) }}" class="btn btn-primary">{{ __('Go to BOQ') }}</a></x-empty></div>
    @else
        <form method="POST" action="{{ route('quotations.store', $project) }}" class="space-y-5">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <section class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                @include('modules.owner_pack.quotations._header-fields', ['quotation' => null])
            </section>

            <section class="card">
                @include('modules.owner_pack.quotations._boq-lines', ['ticked' => []])
            </section>

            <button class="btn btn-primary">{{ __('Prepare draft') }}</button>
        </form>
    @endif
@endsection
