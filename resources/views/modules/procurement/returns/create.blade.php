@extends('layouts.app')
@section('title', $title)
@section('content')
    <x-page-header :title="$title" :subtitle="$source->number.' · '.$source->store->name" />

    <form method="POST" action="{{ $action }}" class="space-y-5">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">

        <section class="card grid gap-3 p-4 sm:grid-cols-3">
            <x-field :label="__('Date')" name="return_date"><input id="return_date" name="return_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('return_date', today()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Reason')" name="reason" class="sm:col-span-2"><input id="reason" name="reason" value="{{ old('reason') }}" class="input" maxlength="255"></x-field>
        </section>

        <section class="card">
            <div class="overflow-x-auto">
                <table class="ledger min-w-[520px]">
                    <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Can return') }}</th><th>{{ __('Returning') }}</th></tr></thead>
                    <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>{{ $line['item']->material->name }}<input type="hidden" name="items[{{ $loop->index }}][line_id]" value="{{ $line['item']->id }}"></td>
                            <td class="r">{{ fmt_qty($line['can_return']) }} {{ $line['item']->material->baseUnit->code }}</td>
                            <td>
                                <input name="items[{{ $loop->index }}][qty]" value="{{ old('items.'.$loop->index.'.qty') }}" type="number" step="0.001" min="0.001" class="input num w-32" aria-label="{{ __('Returning') }}">
                                <span class="text-xs text-ink-3">{{ $line['item']->unit->code }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="hint px-4 pb-3">{{ __('Leave a line blank if none of it is coming back.') }}</p>
        </section>

        <button class="btn btn-primary">{{ __('Post to stock') }}</button>
    </form>
@endsection
