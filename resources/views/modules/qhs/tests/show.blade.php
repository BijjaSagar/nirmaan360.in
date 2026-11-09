@extends('layouts.app')
@section('title', $test->sample_ref ?: __('Quality test'))
@section('content')
    <x-page-header :title="($test->sample_ref ?: __(config('modules.qhs.test_types')[$test->test_type] ?? $test->test_type)).' · '.$test->element" :subtitle="$project->name.($test->site ? ' · '.$test->site->name : '')">
        @if (($overall = \App\Services\Qhs\ConcreteStrength::overall($test)) !== null) @include('modules.field_ops._chip', ['set' => 'verdict', 'value' => $overall]) @endif
        <a href="{{ route('quality-tests.index', $project) }}" class="btn btn-ghost">{{ __('All tests') }}</a>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-4">
                <dl class="grid gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="eyebrow text-ink-3">{{ __('Test') }}</dt><dd>{{ __(config('modules.qhs.test_types')[$test->test_type] ?? $test->test_type) }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Grade') }}</dt><dd>{{ $test->grade ?? '—' }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Required strength (N/mm²)') }}</dt><dd class="num">{{ $test->required_strength ?? '—' }}</dd></div>
                    <div><dt class="eyebrow text-ink-3">{{ __('Cast on') }}</dt><dd class="num">{{ $test->cast_date->format('d M Y') }}</dd></div>
                    @if ($test->quantity_cum !== null)<div><dt class="eyebrow text-ink-3">{{ __('Concrete poured (m³)') }}</dt><dd class="num">{{ fmt_qty($test->quantity_cum) }}</dd></div>@endif
                    @if ($test->supplier)<div><dt class="eyebrow text-ink-3">{{ __('Supplier or batching plant') }}</dt><dd>{{ $test->supplier }}</dd></div>@endif
                    @if ($test->cast_by)<div><dt class="eyebrow text-ink-3">{{ __('Cast by') }}</dt><dd>{{ $test->cast_by }}</dd></div>@endif
                </dl>
                @if ($test->notes)<p class="mt-3 whitespace-pre-line text-sm text-ink-2">{{ $test->notes }}</p>@endif
            </section>

            @if ($evaluation->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Results by age') }}</h2></div>
                    <div class="grid gap-3 p-4 sm:grid-cols-3">
                        @foreach ($evaluation as $row)
                            <div class="rounded-[3px] border border-rule p-3">
                                <p class="eyebrow text-ink-3">{{ __(':days days', ['days' => $row['age']]) }}</p>
                                <p class="num mt-1 text-2xl font-medium">{{ $row['mean'] ?? '—' }} <span class="text-xs text-ink-3">N/mm²</span></p>
                                <p class="text-xs text-ink-3">{{ __(':tested of :scheduled tested', ['tested' => $row['tested'], 'scheduled' => $row['scheduled']]) }}@if ($row['lowest'] !== null) · {{ __('lowest') }} {{ $row['lowest'] }}@endif</p>
                                @if ($row['verdict'])<div class="mt-1.5">@include('modules.field_ops._chip', ['set' => 'verdict', 'value' => $row['verdict']])</div>@endif
                            </div>
                        @endforeach
                    </div>
                    <p class="border-t border-rule px-4 py-2.5 text-xs text-ink-3">{{ __('At 28 days the mean must reach the grade and no specimen may be more than :tolerance N/mm² below it. The 7-day figure is an early indicator only (about :pct% of the grade). This register flags results; it is not a test certificate.', ['tolerance' => config('modules.qhs.individual_tolerance'), 'pct' => config('modules.qhs.early_strength_pct')]) }}</p>
                </section>
            @endif

            <section class="card overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Specimens') }}</h2></div>
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>#</th><th>{{ __('Age') }}</th><th>{{ __('Due on') }}</th><th>{{ __('Tested on') }}</th><th class="r">{{ __('Load (kN)') }}</th><th class="r">{{ __('Strength (N/mm²)') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($test->results as $result)
                        <tr>
                            <td class="num">{{ $result->specimen_no }}</td>
                            <td>{{ __(':days days', ['days' => $result->age_days]) }}</td>
                            <td class="num {{ $result->isDue() ? 'font-medium text-laterite' : '' }}">{{ $result->due_on->format('d M Y') }}</td>
                            @can('qhs.manage')
                                <td colspan="4">
                                    <form method="POST" action="{{ route('quality-tests.results.update', $result) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf @method('PATCH')
                                        <input name="tested_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('tested_on', $result->tested_on?->format('Y-m-d') ?? ($result->due_on->lte(today()) ? $result->due_on->format('Y-m-d') : now()->format('Y-m-d'))) }}" class="input w-36 py-1 text-xs" aria-label="{{ __('Tested on') }}" required>
                                        @if ($test->test_type === 'cube')<input name="load_kn" type="number" step="0.01" min="0" value="{{ $result->load_kn }}" class="input num w-24 py-1 text-xs" placeholder="{{ __('Load (kN)') }}" aria-label="{{ __('Load (kN)') }}">@endif
                                        <input name="strength" type="number" step="0.01" min="0" value="{{ $test->test_type === 'cube' ? '' : $result->strength }}" class="input num w-24 py-1 text-xs" placeholder="N/mm²" aria-label="{{ __('Strength (N/mm²)') }}">
                                        <button class="btn btn-ghost py-1 text-xs">{{ $result->isTested() ? __('Correct') : __('Save') }}</button>
                                        @if ($result->isTested())<span class="num text-sm font-semibold">{{ $result->strength }} N/mm²</span>@endif
                                    </form>
                                </td>
                            @else
                                <td class="num">{{ $result->tested_on?->format('d M Y') ?? '—' }}</td>
                                <td class="r">{{ $result->load_kn ?? '—' }}</td>
                                <td class="r">{{ $result->strength ?? '—' }}</td>
                                <td></td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @can('qhs.manage')
                    <form method="POST" action="{{ route('quality-tests.specimens.store', $test) }}" class="flex flex-wrap items-end gap-3 border-t border-rule p-4">
                        @csrf
                        <x-field :label="__('Add a specimen at age (days)')" name="age_days"><input id="age_days" name="age_days" type="number" min="0" max="365" value="{{ old('age_days', 56) }}" class="input num w-32" required></x-field>
                        <button class="btn btn-ghost">{{ __('Add specimen') }}</button>
                    </form>
                @endcan
            </section>
        </div>

        <div class="space-y-5">
            @include('modules.field_ops._attachments', ['owner' => $test, 'type' => 'quality-tests', 'ability' => 'qhs', 'locked' => false])
        </div>
    </div>
@endsection
