@extends('layouts.app')
@section('title', __('Reports'))
@section('content')
    @php
        $defs = collect($available)->map(fn ($def) => ['project' => $def['project'], 'period' => $def['period'], 'statuses' => $def['statuses']])->all();
        $statusLabels = collect($available)->flatMap(fn ($def) => $def['statuses'])->unique()->mapWithKeys(fn ($status) => [$status => __(\Illuminate\Support\Str::headline(strtolower($status)))])->all();
        $numeric = ['money', 'qty', 'int', 'dec1'];
    @endphp

    <x-page-header :title="__('Reports')" :subtitle="__('Built from your BOQ, measurements, bills, attendance and petty cash.')">
        <a href="{{ route('reports.schedules.index') }}" class="btn btn-ghost">{{ __('Scheduled emails') }}</a>
    </x-page-header>

    @if ($available === [])
        <div class="card"><x-empty :title="__('No reports for your role')" :note="__('Ask an owner or admin to give you access to the data these reports are built from.')" /></div>
    @else
        <form method="GET" action="{{ route('reports.index') }}" x-ref="form" class="card mb-5"
              x-data="{ report: @js($filters['report'] ?? array_key_first($available)), period: @js($filters['period'] ?? 'this_month'), defs: @js($defs), statusLabels: @js($statusLabels), status: @js($filters['status'] ?? '') }">
            <div class="card-head"><h2 class="card-title">{{ __('Choose a report') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-field :label="__('Report')" name="report">
                    <select id="report" name="report" x-model="report" class="input" required>
                        @foreach ($available as $key => $def)
                            <option value="{{ $key }}">{{ __($def['label']) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Project')" name="project_id">
                    <select id="project_id" name="project_id" class="input" :required="defs[report].project">
                        <option value="">{{ __('All projects') }}</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected(($filters['project_id'] ?? null) === $project->id)>{{ $project->code }} · {{ $project->name }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Period')" name="period" x-show="defs[report].period">
                    <select id="period" name="period" x-model="period" class="input" :disabled="! defs[report].period">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Status')" name="status" x-show="defs[report].statuses.length">
                    <select id="status" name="status" class="input" :disabled="! defs[report].statuses.length">
                        <option value="">{{ __('Any status') }}</option>
                        <template x-for="option in defs[report].statuses" :key="option">
                            <option :value="option" x-text="statusLabels[option]" :selected="option === status"></option>
                        </template>
                    </select>
                </x-field>
                <x-field :label="__('From')" name="from" x-show="defs[report].period && period === 'custom'" x-cloak>
                    <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="input" :disabled="! (defs[report].period && period === 'custom')" :required="period === 'custom'">
                </x-field>
                <x-field :label="__('To')" name="to" x-show="defs[report].period && period === 'custom'" x-cloak>
                    <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="input" :disabled="! (defs[report].period && period === 'custom')" :required="period === 'custom'">
                </x-field>
                <div class="flex items-end"><button class="btn btn-primary w-full sm:w-auto">{{ __('Show report') }}</button></div>
            </div>
            <div class="grid gap-2 border-t border-rule px-4 py-3 text-sm text-ink-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($available as $key => $def)
                    <button type="button" class="text-left hover:text-ink" @click="report = '{{ $key }}'; $refs.form.scrollIntoView({ behavior: 'smooth' })" :class="report === '{{ $key }}' ? 'text-ink' : ''">
                        <span class="font-semibold">{{ __($def['label']) }}</span>
                        <span class="block text-xs">{{ __($def['note']) }}</span>
                    </button>
                @endforeach
            </div>
        </form>

        @if ($result)
            <section class="card">
                <div class="card-head flex-wrap">
                    <div class="min-w-0">
                        <h2 class="card-title">{{ $result->title }}</h2>
                        <p class="text-sm text-ink-3">{{ $result->subtitle }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('reports.xlsx', $query) }}" class="btn btn-ghost">{{ __('Excel') }}</a>
                        <a href="{{ route('reports.pdf', $query) }}" class="btn btn-ghost">PDF</a>
                        @can('report.manage')
                            <a href="{{ route('reports.schedules.index', array_filter(['report' => $query['report'], 'project_id' => $query['project_id'] ?? null, 'period' => in_array($query['period'] ?? null, \App\Services\Reports\ReportPeriod::SCHEDULABLE, true) ? $query['period'] : null])) }}#new" class="btn btn-ghost">{{ __('Email on a schedule') }}</a>
                        @endcan
                    </div>
                </div>

                @if ($result->isEmpty())
                    <x-empty :title="__('No data for these choices.')" :note="__('Try another period or project.')" />
                @else
                    <div class="overflow-x-auto">
                        <table class="ledger" style="min-width: {{ max(640, count($result->columns) * 96) }}px">
                            <thead>
                            <tr>
                                @foreach ($result->columns as $column)
                                    <th class="{{ in_array($column['type'], $numeric, true) || $column['type'] === 'date' ? 'r' : '' }}">{{ $column['label'] }}</th>
                                @endforeach
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($result->rows as $row)
                                <tr>
                                    @foreach ($result->columns as $column)
                                        <td class="{{ in_array($column['type'], $numeric, true) || $column['type'] === 'date' ? 'r' : '' }}">{{ \App\Services\Reports\ReportResult::display($column['type'], $row[$column['key']] ?? null) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                            @if ($result->totals !== null)
                                <tfoot>
                                <tr class="font-semibold">
                                    @foreach ($result->columns as $column)
                                        <td class="border-t border-ink/70 bg-paper-2/40 px-3 py-2 {{ in_array($column['type'], $numeric, true) ? 'r num text-right' : '' }}">
                                            @if ($loop->first){{ __('Total') }}@elseif (array_key_exists($column['key'], $result->totals)){{ \App\Services\Reports\ReportResult::display($column['type'], $result->totals[$column['key']]) }}@endif
                                        </td>
                                    @endforeach
                                </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                    <div class="space-y-1 border-t border-rule px-4 py-3 text-xs text-ink-3">
                        @if ($result->truncated)
                            <p class="font-medium text-ink-2">{{ __('Showing the first :shown of :total rows. The Excel export has them all.', ['shown' => $previewRows, 'total' => $total]) }}</p>
                        @endif
                        @foreach ($result->notes as $note)<p>{{ $note }}</p>@endforeach
                    </div>
                @endif
            </section>
        @endif
    @endif
@endsection
