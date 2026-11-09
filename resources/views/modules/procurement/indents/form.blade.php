@extends('layouts.app')
@section('title', $indent ? $indent->number : __('Raise indent'))
@section('content')
    <x-page-header :title="$indent ? $indent->number : __('Raise indent')" :subtitle="$project->name" />

    @if ($stores->isEmpty())
        <div class="card"><x-empty :title="__('Add a store first')" :note="__('An indent needs a store to deliver to.')"><a href="{{ route('procurement.stock.index', $project) }}" class="btn btn-ghost">{{ __('Go to stores') }}</a></x-empty></div>
    @else
        <form method="POST" action="{{ $indent ? route('procurement.indents.update', $indent) : route('procurement.indents.store', $project) }}" class="space-y-5">
            @csrf
            @if ($indent)
                @method('PATCH')
            @else
                <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            @endif

            <section class="card grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Deliver to store')" name="store_id">
                    <select id="store_id" name="store_id" class="input" required>
                        @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id', $indent?->store_id) === (string) $store->id)>{{ $store->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Required by')" name="required_by"><input id="required_by" name="required_by" type="date" value="{{ old('required_by', $indent?->required_by?->format('Y-m-d')) }}" class="input"></x-field>
                <x-field :label="__('Remarks')" name="remarks" class="sm:col-span-2"><textarea id="remarks" name="remarks" rows="2" class="input">{{ old('remarks', $indent?->remarks) }}</textarea></x-field>
            </section>

            <section class="card p-4">
                <h2 class="card-title mb-3">{{ __('Materials needed') }}</h2>
                @if (empty($materialOptions))
                    <p class="text-sm text-ink-3">{{ __('No materials yet') }}. <a href="{{ route('procurement.materials.index') }}" class="link">{{ __('Add materials') }}</a></p>
                @else
                    @include('modules.procurement.partials.lines', [
                        'lines' => $indent?->items->map(fn ($line) => ['material_id' => $line->material_id, 'qty' => $line->qty, 'unit_id' => $line->unit_id, 'boq_item_id' => $line->boq_item_id ?? '', 'activity' => '', 'remarks' => $line->remarks ?? ''])->all() ?: null,
                    ])
                @endif
            </section>

            <button class="btn btn-primary">{{ __('Save draft') }}</button>
        </form>
    @endif
@endsection
