@extends('layouts.app')
@section('title', __('Quality tests'))
@section('content')
    <div x-data="{ add: {{ $errors->any() && old('element') !== null ? 'true' : 'false' }}, type: @js(old('test_type', 'cube')) }">
        <x-page-header :title="__('Quality test register')" :subtitle="$project->name">
            @can('qhs.manage')<button type="button" class="btn btn-primary" @click="add = !add">{{ __('Register test') }}</button>@endcan
        </x-page-header>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-stat :label="__('Specimens due for testing')" :value="$dueCount" :accent="$dueCount > 0" :note="__('Due today or earlier, no result yet')" />
        </div>

        @can('qhs.manage')
            <form x-show="add" x-cloak method="POST" action="{{ route('quality-tests.store', $project) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <x-field :label="__('Test')" name="test_type">
                    <select id="test_type" name="test_type" class="input" x-model="type">
                        @foreach (config('modules.qhs.test_types') as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Sample no.')" name="sample_ref"><input id="sample_ref" name="sample_ref" value="{{ old('sample_ref') }}" class="input num" maxlength="60"></x-field>
                <x-field :label="__('Element or location')" name="element" class="sm:col-span-2"><input id="element" name="element" value="{{ old('element') }}" class="input" maxlength="160" required placeholder="{{ __('For example slab, third floor') }}"></x-field>
                <x-field :label="__('Grade')" name="grade"><input id="grade" name="grade" value="{{ old('grade') }}" class="input" maxlength="20" placeholder="M25"></x-field>
                <x-field :label="__('Required strength (N/mm²)')" name="required_strength" :hint="__('Taken from the grade when empty.')"><input id="required_strength" name="required_strength" type="number" step="0.01" min="0" value="{{ old('required_strength') }}" class="input num"></x-field>
                <x-field :label="__('Cast on')" name="cast_date"><input id="cast_date" name="cast_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('cast_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
                <x-field :label="__('Concrete poured (m³)')" name="quantity_cum"><input id="quantity_cum" name="quantity_cum" type="number" step="0.001" min="0" value="{{ old('quantity_cum') }}" class="input num"></x-field>
                <x-field :label="__('Supplier or batching plant')" name="supplier"><input id="supplier" name="supplier" value="{{ old('supplier') }}" class="input" maxlength="120"></x-field>
                <x-field :label="__('Cast by')" name="cast_by"><input id="cast_by" name="cast_by" value="{{ old('cast_by') }}" class="input" maxlength="80"></x-field>
                <x-field :label="__('Specimens at 7 days')" name="specimens_7"><input id="specimens_7" name="specimens_7" type="number" min="0" max="12" value="{{ old('specimens_7', 3) }}" class="input num"></x-field>
                <x-field :label="__('Specimens at 28 days')" name="specimens_28"><input id="specimens_28" name="specimens_28" type="number" min="0" max="12" value="{{ old('specimens_28', 3) }}" class="input num"></x-field>
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-3"><input id="notes" name="notes" value="{{ old('notes') }}" class="input" maxlength="2000"></x-field>
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Save test') }}</button></div>
            </form>
        @endcan

        <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
            <a href="{{ route('quality-tests.index', $project) }}" class="btn {{ $type ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
            @foreach (config('modules.qhs.test_types') as $value => $label)
                <a href="{{ route('quality-tests.index', [$project, 'type' => $value]) }}" class="btn {{ $type === $value ? 'btn-ink' : 'btn-ghost' }}">{{ __($label) }}</a>
            @endforeach
        </div>

        <div class="card overflow-x-auto">
            @if ($tests->isEmpty())
                <x-empty :title="__('No tests registered')" :note="__('Register each pour or sample, then enter the lab results as they come in.')" />
            @else
                <table class="ledger min-w-[860px]">
                    <thead><tr><th>{{ __('Sample') }}</th><th>{{ __('Grade') }}</th><th>{{ __('Cast on') }}</th><th>{{ __('7 days') }}</th><th>{{ __('28 days') }}</th><th>{{ __('Next due') }}</th></tr></thead>
                    <tbody>
                    @foreach ($tests as $test)
                        @php($byAge = $evaluations[$test->id]->keyBy('age'))
                        @php($next = $test->results->whereNull('strength')->sortBy('due_on')->first())
                        <tr>
                            <td>
                                <a href="{{ route('quality-tests.show', $test) }}" class="link">{{ $test->sample_ref ?: __(config('modules.qhs.test_types')[$test->test_type] ?? $test->test_type) }}</a>
                                <p class="text-xs text-ink-3">{{ $test->element }}</p>
                            </td>
                            <td>{{ $test->grade ?? '—' }}@if ($test->required_strength !== null)<span class="block text-xs text-ink-3 num">{{ $test->required_strength }} N/mm²</span>@endif</td>
                            <td class="num">{{ $test->cast_date->format('d M Y') }}</td>
                            @foreach ([7, 28] as $age)
                                @php($row = $byAge->get($age))
                                <td>
                                    @if ($row)
                                        <span class="num">{{ $row['mean'] ?? '—' }}</span>
                                        <span class="text-xs text-ink-3">({{ $row['tested'] }}/{{ $row['scheduled'] }})</span>
                                        @if ($row['verdict']) <div class="mt-0.5">@include('modules.field_ops._chip', ['set' => 'verdict', 'value' => $row['verdict']])</div> @endif
                                    @else
                                        —
                                    @endif
                                </td>
                            @endforeach
                            <td class="num text-xs {{ $next && $next->isDue() ? 'font-medium text-laterite' : '' }}">{{ $next ? $next->due_on->format('d M Y').' · '.$next->age_days.' '.__('days') : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $tests->links() }}</div>
    </div>
@endsection
