@extends('layouts.app')
@section('title', __('BOQ assumptions'))
@section('content')
    @php use App\Services\AutoBoq\Assumptions; @endphp

    <x-page-header :title="__('BOQ assumptions')" :subtitle="__('The typical figures the auto BOQ uses for every project of the company')" />

    <form method="POST" action="{{ route('auto-boq.assumptions.save') }}" class="card">
        @csrf @method('PUT')
        <div class="card-head"><h2 class="card-title">{{ __('Typical values') }}</h2></div>
        <div class="space-y-5 p-4">
            <p class="text-sm text-ink-3">{{ __('Typical values. Your engineer should change them to match how your company builds. A change applies the next time the auto BOQ of a project is worked out; lines already in a BOQ do not move.') }}</p>

            @if ($errors->any())
                <div class="rounded-[3px] border border-laterite bg-laterite/5 px-3 py-2 text-sm text-laterite" role="alert">{{ __('Please check the figures.') }}</div>
            @endif

            @foreach (Assumptions::GROUPS as $groupKey => $groupLabel)
                <section>
                    <h3 class="eyebrow text-ink-3">{{ __($groupLabel) }}</h3>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach (Assumptions::CATALOGUE as $key => [$default, $unit, $assumptionGroup, $label, $note])
                            @continue($assumptionGroup !== $groupKey)
                            <x-field :label="__($label)" :name="'values.'.$key" :hint="$note !== '' ? __($note) : null">
                                <div class="flex items-center gap-2">
                                    <input id="values.{{ $key }}" name="values[{{ $key }}]" type="number" min="0" max="{{ Assumptions::MAX }}" step="0.001" value="{{ old('values.'.$key, $values[$key]) }}" class="input num" required>
                                    <span class="text-xs whitespace-nowrap text-ink-3">{{ $unit }}</span>
                                </div>
                                <p class="mt-1 text-xs text-ink-3">
                                    {{ __('Typical :value', ['value' => fmt_qty($default)]) }}
                                    @if (in_array($key, $changed, true))<span class="stamp ml-1 border-neel text-neel">{{ __('Set by you') }}</span>@endif
                                </p>
                            </x-field>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <div class="flex justify-end border-t border-rule pt-4"><button class="btn btn-primary">{{ __('Save assumptions') }}</button></div>
        </div>
    </form>
@endsection
