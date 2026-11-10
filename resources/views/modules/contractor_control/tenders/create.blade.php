@extends('layouts.app')
@section('title', __('New tender'))
@section('content')
    <x-page-header :title="__('New tender')" :subtitle="$project->name" />

    @if ($boqItems->isEmpty())
        <div class="card"><x-empty :title="__('Add BOQ items first')" :note="__('A tender is made up of one or more BOQ items.')"><a href="{{ route('boq.index', $project) }}" class="btn btn-primary">{{ __('Go to BOQ') }}</a></x-empty></div>
    @else
        <form method="POST" action="{{ route('contractor-control.tenders.store', $project) }}" class="space-y-5" x-data="{ q: '' }">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <section class="card grid gap-4 p-4 sm:grid-cols-3">
                <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title') }}" maxlength="160" class="input" placeholder="{{ __('For example: Flooring') }}" required></x-field>
                <x-field :label="__('Quotes needed by')" name="due_on"><input id="due_on" name="due_on" type="date" value="{{ old('due_on') }}" class="input"></x-field>
                <x-field :label="__('Scope')" name="scope" class="sm:col-span-3"><textarea id="scope" name="scope" rows="2" class="input">{{ old('scope') }}</textarea></x-field>
            </section>

            <section class="card">
                <div class="card-head flex-wrap"><h2 class="card-title">{{ __('BOQ items') }}</h2><input x-model="q" type="search" class="input sm:w-64" placeholder="{{ __('Search items') }}" aria-label="{{ __('Search items') }}"></div>
                @error('boq_item_ids')<p class="error px-4 pt-3">{{ $message }}</p>@enderror
                @foreach ($boqItems as $item)
                    <label class="flex cursor-pointer items-start gap-3 border-b border-rule px-4 py-2.5 last:border-b-0 hover:bg-paper/70" x-show="!q || @js(mb_strtolower($item->code.' '.$item->description)).includes(q.toLowerCase())">
                        <input type="checkbox" name="boq_item_ids[]" value="{{ $item->id }}" class="mt-1" @checked(in_array($item->id, old('boq_item_ids', $ticked)))>
                        <span class="min-w-0 flex-1 text-sm"><span class="num text-xs text-ink-3">{{ $item->code }}</span> {{ $item->description }}
                            <span class="num mt-0.5 block text-xs text-ink-3">{{ fmt_qty($item->qty) }} {{ $item->unit }} × {{ inr($item->rate) }} = {{ inr($item->amount()) }}</span>
                        </span>
                    </label>
                @endforeach
            </section>

            <button class="btn btn-primary">{{ __('Create tender') }}</button>
        </form>
    @endif
@endsection
