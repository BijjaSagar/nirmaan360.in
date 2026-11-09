@extends('layouts.app')
@section('title', $asset->name)
@php
    $unit = $asset->meter_unit === 'km' ? 'km' : ($asset->meter_unit === 'hours' ? 'h' : null);
    $canLog = auth()->user()->can('asset.log') && ! $asset->isRetired();
    $canManage = auth()->user()->can('asset.manage');
    $tabs = ['log' => __('Machine log'), 'fuel' => __('Fuel'), 'breakdowns' => __('Breakdowns'), 'maintenance' => __('Maintenance'), 'transfers' => __('Location'), ...($asset->isHired() && auth()->user()->can('asset.bill') ? ['bills' => __('Hire bills')] : [])];
    $uuid = fn () => (string) \Illuminate\Support\Str::uuid();
@endphp
@section('content')
    <x-page-header :title="$asset->name" :subtitle="$asset->code.collect([$asset->make, $asset->model, $asset->registration_no])->filter()->map(fn ($part) => ' · '.$part)->join('')">
        @include('modules.field_ops._chip', ['set' => 'asset_status', 'value' => $asset->status])
        @include('modules.field_ops._chip', ['set' => 'ownership', 'value' => $asset->ownership])
        <a href="{{ route('assets.tag', $asset) }}" target="_blank" rel="noopener" class="btn btn-ghost"><x-icon name="qr" class="size-4" />{{ __('Print tag') }}</a>
        @if ($canManage)<a href="{{ route('assets.edit', $asset) }}" class="btn btn-ghost">{{ __('Edit') }}</a>@endif
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @php($where = collect([$asset->project?->name, $asset->site?->name, $asset->location_note])->filter()->values())
        <div class="card px-4 py-3">
            <p class="eyebrow text-ink-3">{{ __('Now at') }}</p>
            <p class="mt-1.5 font-display text-xl leading-tight font-semibold">{{ $where->first() ?? '—' }}</p>
            @if ($where->count() > 1)<p class="mt-0.5 text-xs text-ink-3">{{ $where->skip(1)->join(' · ') }}</p>@endif
        </div>
        <x-stat :label="__('Worked')" :value="fmt_qty($asset->meter_unit === 'km' ? $summary['km'] : $summary['hours']).' '.($unit ?? 'h')" :note="$asset->meter_unit ? null : __('No meter set')" />
        <x-stat :label="__('Fuel used')" :value="fmt_qty($summary['fuel_litres']).' L'" :note="$summary['consumption'] ? $summary['consumption'].' '.$summary['consumption_unit'] : inr($summary['fuel_cost'])" />
        <x-stat :label="__('Repairs and services')" :value="inr($summary['repair_cost'])" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_16rem]" x-data="{ tab: @js(old('_tab', request('tab', 'log'))) }">
        <div class="min-w-0">
            <div class="mb-3 flex flex-wrap gap-1.5 text-sm" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" @click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'" :class="tab === '{{ $key }}' ? 'btn btn-ink' : 'btn btn-ghost'">{{ $label }}</button>
                @endforeach
            </div>

            {{-- Machine log --}}
            <section x-show="tab === 'log'" class="card">
                @if ($canLog)
                    <form method="POST" action="{{ route('machine-logs.store') }}" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-4">
                        @csrf
                        <input type="hidden" name="_tab" value="log"><input type="hidden" name="asset_id" value="{{ $asset->id }}"><input type="hidden" name="client_uuid" value="{{ $uuid() }}">
                        <x-field :label="__('Date')" name="log_date"><input id="log_date" name="log_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('log_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
                        <x-field :label="__('Project')" name="project_id">
                            <select id="log_project" name="project_id" class="input">
                                <option value="">—</option>
                                @foreach ($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id', $asset->project_id) === (string) $project->id)>{{ $project->name }}</option>@endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Hours worked')" name="hours"><input id="hours" name="hours" type="number" step="0.5" min="0" max="24" inputmode="decimal" value="{{ old('hours') }}" class="input num"></x-field>
                        <x-field :label="__('Km run')" name="km"><input id="km" name="km" type="number" step="0.1" min="0" inputmode="decimal" value="{{ old('km') }}" class="input num"></x-field>
                        <x-field :label="__('Opening reading')" name="opening_reading"><input id="opening_reading" name="opening_reading" type="number" step="0.1" min="0" value="{{ old('opening_reading') }}" class="input num"></x-field>
                        <x-field :label="__('Closing reading')" name="closing_reading"><input id="closing_reading" name="closing_reading" type="number" step="0.1" min="0" value="{{ old('closing_reading') }}" class="input num"></x-field>
                        <x-field :label="__('Hours lost to breakdown')" name="breakdown_hours"><input id="breakdown_hours" name="breakdown_hours" type="number" step="0.5" min="0" max="24" value="{{ old('breakdown_hours') }}" class="input num"></x-field>
                        <x-field :label="__('Operator')" name="operator"><input id="operator" name="operator" value="{{ old('operator') }}" class="input" maxlength="80"></x-field>
                        <x-field :label="__('Remarks')" name="remarks" class="sm:col-span-3"><input id="remarks" name="remarks" value="{{ old('remarks') }}" class="input" maxlength="255"></x-field>
                        <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Save log') }}</button></div>
                    </form>
                @endif
                <div class="overflow-x-auto">
                    @if ($logs->isEmpty())
                        <x-empty :title="__('No work logged yet')" :note="__('Log the hours or km worked each day.')" />
                    @else
                        <table class="ledger min-w-[640px]">
                            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Project') }}</th><th class="r">{{ __('Hours') }}</th><th class="r">{{ __('Km') }}</th><th class="r">{{ __('Lost') }}</th><th>{{ __('Operator') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($logs as $log)
                                <tr>
                                    <td class="num">{{ $log->log_date->format('d M Y') }}</td>
                                    <td>{{ $log->project?->name ?? '—' }}</td>
                                    <td class="r">{{ fmt_qty($log->hours) }}</td>
                                    <td class="r">{{ fmt_qty($log->km) }}</td>
                                    <td class="r">{{ fmt_qty($log->breakdown_hours) }}</td>
                                    <td>{{ $log->operator ?? '—' }}</td>
                                    <td class="text-right">
                                        @if ($log->isBilled())
                                            <span class="text-xs text-ink-3">{{ __('Billed') }}</span>
                                        @elseif ($canManage)
                                            <form method="POST" action="{{ route('machine-logs.destroy', $log) }}" onsubmit="return confirm('{{ __('Delete this log?') }}')">@csrf @method('DELETE')<input type="hidden" name="_tab" value="log"><button class="link text-xs text-laterite">{{ __('Delete') }}</button></form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>

            {{-- Fuel --}}
            <section x-show="tab === 'fuel'" x-cloak class="card">
                @if ($canLog)
                    <form method="POST" action="{{ route('assets.fuel.store') }}" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-4">
                        @csrf
                        <input type="hidden" name="_tab" value="fuel"><input type="hidden" name="asset_id" value="{{ $asset->id }}"><input type="hidden" name="client_uuid" value="{{ $uuid() }}">
                        <x-field :label="__('Date')" name="issue_date"><input id="issue_date" name="issue_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('issue_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
                        <x-field :label="__('Litres')" name="litres"><input id="litres" name="litres" type="number" step="0.001" min="0" inputmode="decimal" value="{{ old('litres') }}" class="input num" required></x-field>
                        <x-field :label="__('Rate per litre (₹)')" name="rate"><input id="rate" name="rate" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('rate') }}" class="input num" required></x-field>
                        <x-field :label="__('Meter reading')" name="reading"><input id="reading" name="reading" type="number" step="0.1" min="0" value="{{ old('reading') }}" class="input num"></x-field>
                        <x-field :label="__('Taken from')" name="source"><input id="source" name="source" value="{{ old('source') }}" class="input" maxlength="80" placeholder="{{ __('Site tank, pump') }}"></x-field>
                        <x-field :label="__('Slip no.')" name="slip_no"><input id="slip_no" name="slip_no" value="{{ old('slip_no') }}" class="input" maxlength="40"></x-field>
                        <x-field :label="__('Project')" name="project_id">
                            <select id="fuel_project" name="project_id" class="input">
                                <option value="">—</option>
                                @foreach ($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id', $asset->project_id) === (string) $project->id)>{{ $project->name }}</option>@endforeach
                            </select>
                        </x-field>
                        <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Issue fuel') }}</button></div>
                    </form>
                @endif
                <div class="overflow-x-auto">
                    @if ($fuelIssues->isEmpty())
                        <x-empty :title="__('No fuel issued yet')" />
                    @else
                        <table class="ledger min-w-[560px]">
                            <thead><tr><th>{{ __('Date') }}</th><th class="r">{{ __('Litres') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Amount') }}</th><th>{{ __('Taken from') }}</th><th>{{ __('Slip no.') }}</th></tr></thead>
                            <tbody>
                            @foreach ($fuelIssues as $fuel)
                                <tr>
                                    <td class="num">{{ $fuel->issue_date->format('d M Y') }}</td>
                                    <td class="r">{{ fmt_qty($fuel->litres) }}</td>
                                    <td class="r">{{ inr($fuel->rate) }}</td>
                                    <td class="r">{{ inr($fuel->amount) }}</td>
                                    <td>{{ $fuel->source ?? '—' }}</td>
                                    <td class="num">{{ $fuel->slip_no ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </section>

            {{-- Breakdowns --}}
            <section x-show="tab === 'breakdowns'" x-cloak class="card">
                @if ($canLog)
                    <form method="POST" action="{{ route('assets.breakdowns.store') }}" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-3">
                        @csrf
                        <input type="hidden" name="_tab" value="breakdowns"><input type="hidden" name="asset_id" value="{{ $asset->id }}"><input type="hidden" name="client_uuid" value="{{ $uuid() }}">
                        <x-field :label="__('When it stopped')" name="reported_at"><input id="reported_at" name="reported_at" type="datetime-local" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('reported_at', now()->format('Y-m-d\TH:i')) }}" class="input" required></x-field>
                        <x-field :label="__('What happened')" name="description" class="sm:col-span-2"><input id="description" name="description" value="{{ old('description') }}" class="input" maxlength="2000" required></x-field>
                        <x-field :label="__('Cause, if known')" name="cause" class="sm:col-span-2"><input id="cause" name="cause" value="{{ old('cause') }}" class="input" maxlength="255"></x-field>
                        <div class="flex items-end"><button class="btn btn-danger w-full">{{ __('Report breakdown') }}</button></div>
                    </form>
                @endif
                @forelse ($breakdowns as $breakdown)
                    <div class="border-b border-rule px-4 py-3 last:border-b-0">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold">{{ $breakdown->description }}</p>
                            @include('modules.field_ops._chip', ['set' => 'breakdown', 'value' => $breakdown->status])
                        </div>
                        <p class="mt-0.5 text-xs text-ink-3">
                            <span class="num">{{ $breakdown->reported_at->format('d M Y H:i') }}</span>
                            @if ($breakdown->cause) · {{ $breakdown->cause }} @endif
                            @if ($breakdown->resolved_at) · {{ __('Down for :hours h', ['hours' => $breakdown->downtimeHours()]) }} · {{ inr($breakdown->repair_cost) }} @endif
                        </p>
                        @if ($breakdown->resolution)<p class="mt-1 text-sm text-ink-2">{{ $breakdown->resolution }}</p>@endif
                        @if ($breakdown->isOpen() && $canManage)
                            <form method="POST" action="{{ route('assets.breakdowns.update', $breakdown) }}" class="mt-3 grid gap-3 sm:grid-cols-4">
                                @csrf @method('PATCH')
                                <input type="hidden" name="_tab" value="breakdowns">
                                <x-field :label="__('Back at work')" name="resolved_at"><input name="resolved_at" type="datetime-local" max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ now()->format('Y-m-d\TH:i') }}" class="input" required></x-field>
                                <x-field :label="__('Repair cost (₹)')" name="repair_cost"><input name="repair_cost" type="number" step="0.01" min="0" class="input num"></x-field>
                                <x-field :label="__('What was done')" name="resolution"><input name="resolution" class="input" maxlength="2000"></x-field>
                                <div class="flex items-end"><button class="btn btn-ghost w-full">{{ __('Mark resolved') }}</button></div>
                            </form>
                        @endif
                    </div>
                @empty
                    <x-empty :title="__('No breakdowns')" />
                @endforelse
            </section>

            {{-- Maintenance --}}
            <section x-show="tab === 'maintenance'" x-cloak class="card">
                @if ($canManage)
                    <form method="POST" action="{{ route('asset-maintenance.store') }}" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-4">
                        @csrf
                        <input type="hidden" name="_tab" value="maintenance"><input type="hidden" name="asset_id" value="{{ $asset->id }}"><input type="hidden" name="client_uuid" value="{{ $uuid() }}">
                        <x-field :label="__('Service')" name="title" class="sm:col-span-2"><input id="m_title" name="title" value="{{ old('title') }}" class="input" maxlength="120" required placeholder="{{ __('Engine oil and filters') }}"></x-field>
                        <x-field :label="__('Every (:unit)', ['unit' => $unit ?? '—'])" name="interval_usage"><input id="interval_usage" name="interval_usage" type="number" step="0.001" min="0" value="{{ old('interval_usage') }}" class="input num" @disabled(! $asset->meter_unit)></x-field>
                        <x-field :label="__('Every (days)')" name="interval_days"><input id="interval_days" name="interval_days" type="number" min="1" value="{{ old('interval_days') }}" class="input num"></x-field>
                        <x-field :label="__('Last done on')" name="last_done_on"><input id="last_done_on" name="last_done_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('last_done_on') }}" class="input"></x-field>
                        <x-field :label="__('Next due on')" name="next_due_on" :hint="__('Leave empty to work it out from the interval.')"><input id="next_due_on" name="next_due_on" type="date" value="{{ old('next_due_on') }}" class="input"></x-field>
                        <div class="flex items-end sm:col-start-4"><button class="btn btn-primary w-full">{{ __('Schedule service') }}</button></div>
                    </form>
                @endif
                @forelse ($maintenance as $row)
                    @php($item = $row['item'])
                    @php($status = $row['status'])
                    <div class="border-b border-rule px-4 py-3 last:border-b-0 {{ $item->is_active ? '' : 'opacity-60' }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold">{{ $item->title }}</p>
                            @if ($item->is_active) @include('modules.field_ops._chip', ['set' => 'maintenance', 'value' => $status['state']]) @else <span class="stamp border-ink-3 text-ink-3">{{ __('Paused') }}</span> @endif
                        </div>
                        <p class="mt-0.5 text-xs text-ink-3">
                            {{ __('Last done') }}: <span class="num">{{ $item->last_done_on?->format('d M Y') ?? '—' }}</span>
                            @if ($item->next_due_on) · {{ __('Next due') }}: <span class="num">{{ $item->next_due_on->format('d M Y') }}</span> @endif
                            @if ($item->interval_usage !== null) · {{ __('Since last service') }}: <span class="num">{{ fmt_qty($status['usage_since']) }} / {{ fmt_qty($item->interval_usage) }} {{ $unit }}</span> @endif
                        </p>
                        @if ($canManage)
                            <div class="mt-2 flex flex-wrap items-end gap-3">
                                <form method="POST" action="{{ route('asset-maintenance.done', $item) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="_tab" value="maintenance"><input type="hidden" name="client_uuid" value="{{ $uuid() }}">
                                    <x-field :label="__('Done on')" name="done_on"><input name="done_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" class="input py-1" required></x-field>
                                    <x-field :label="__('Cost (₹)')" name="cost"><input name="cost" type="number" step="0.01" min="0" class="input num w-28 py-1"></x-field>
                                    <x-field :label="__('Done by')" name="done_by"><input name="done_by" class="input w-36 py-1" maxlength="120"></x-field>
                                    <button class="btn btn-ghost"><x-icon name="wrench" class="size-4" />{{ __('Service done') }}</button>
                                </form>
                                <form method="POST" action="{{ route('asset-maintenance.toggle', $item) }}">@csrf<input type="hidden" name="_tab" value="maintenance"><button class="link text-xs">{{ $item->is_active ? __('Pause') : __('Resume') }}</button></form>
                            </div>
                        @endif
                    </div>
                @empty
                    <x-empty :title="__('No services scheduled')" :note="__('Add oil changes, greasing and inspections so they never get missed.')" />
                @endforelse
                @if ($serviceRecords->isNotEmpty())
                    <div class="border-t border-rule bg-paper-2/40 px-4 py-2 eyebrow text-ink-3">{{ __('Service history') }}</div>
                    @foreach ($serviceRecords as $record)
                        <div class="flex flex-wrap justify-between gap-2 border-b border-rule px-4 py-2 text-sm last:border-b-0">
                            <span><span class="num">{{ $record->done_on->format('d M Y') }}</span> · {{ $record->description }}@if ($record->done_by) <span class="text-ink-3">· {{ $record->done_by }}</span>@endif</span>
                            <span class="num">{{ inr($record->cost) }}</span>
                        </div>
                    @endforeach
                @endif
            </section>

            {{-- Location --}}
            <section x-show="tab === 'transfers'" x-cloak class="card" x-data="{ project: @js((string) old('to_project_id', '')), sites: @js($sites) }">
                @if ($canManage && ! $asset->isRetired())
                    <form method="POST" action="{{ route('assets.transfers.store') }}" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-4">
                        @csrf
                        <input type="hidden" name="_tab" value="transfers"><input type="hidden" name="asset_id" value="{{ $asset->id }}"><input type="hidden" name="client_uuid" value="{{ $uuid() }}">
                        <x-field :label="__('Move to project')" name="to_project_id">
                            <select id="to_project_id" name="to_project_id" class="input" x-model="project">
                                <option value="">—</option>
                                @foreach ($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Site')" name="to_site_id">
                            <select id="to_site_id" name="to_site_id" class="input">
                                <option value="">—</option>
                                <template x-for="site in sites.filter(s => String(s.project_id) === project)" :key="site.id"><option :value="site.id" x-text="site.name"></option></template>
                            </select>
                        </x-field>
                        <x-field :label="__('Or yard, store or other place')" name="to_location"><input id="to_location" name="to_location" value="{{ old('to_location') }}" class="input" maxlength="160"></x-field>
                        <x-field :label="__('Moved on')" name="moved_on"><input id="moved_on" name="moved_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('moved_on', now()->format('Y-m-d')) }}" class="input" required></x-field>
                        <x-field :label="__('Reason')" name="reason" class="sm:col-span-3"><input id="reason" name="reason" value="{{ old('reason') }}" class="input" maxlength="255"></x-field>
                        <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Move asset') }}</button></div>
                    </form>
                @endif
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[560px]">
                        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Reason') }}</th><th>{{ __('By') }}</th></tr></thead>
                        <tbody>
                        @forelse ($movements as $movement)
                            <tr>
                                <td class="num">{{ $movement->moved_on->format('d M Y') }}</td>
                                <td>{{ $movement->origin() }}</td>
                                <td>{{ $movement->destination() }}</td>
                                <td>{{ $movement->reason ?? '—' }}</td>
                                <td>{{ $movement->mover?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-ink-3">{{ __('No moves recorded yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Hire bills --}}
            @if (isset($tabs['bills']))
                <section x-show="tab === 'bills'" x-cloak class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Hire bills') }}</h2><a href="{{ route('hire-bills.create', ['asset_id' => $asset->id]) }}" class="btn btn-primary">{{ __('New hire bill') }}</a></div>
                    @forelse ($hireBills as $bill)
                        <a href="{{ route('hire-bills.show', $bill) }}" class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                            <span><span class="num">{{ $bill->number }}</span> <span class="text-ink-3">· {{ $bill->period_from->format('d M') }} – {{ $bill->period_to->format('d M Y') }}</span></span>
                            <span class="flex items-center gap-3"><span class="num">{{ inr($bill->net_payable) }}</span>@include('modules.field_ops._chip', ['set' => 'bill', 'value' => $bill->status])</span>
                        </a>
                    @empty
                        <x-empty :title="__('No hire bills yet')" />
                    @endforelse
                </section>
            @endif
        </div>

        <aside class="space-y-5">
            <section class="card p-4 text-center">
                <div class="mx-auto w-40 max-w-full">{!! \App\Services\Assets\QrCode::encode($asset->tagUrl())->svg(2) !!}</div>
                <p class="num mt-2 text-sm font-semibold">{{ $asset->code }}</p>
                <a href="{{ route('assets.tag', $asset) }}" target="_blank" rel="noopener" class="link text-xs">{{ __('Print tag') }}</a>
            </section>
            <section class="card p-4 text-sm">
                <dl class="space-y-2">
                    <div><dt class="eyebrow text-ink-3">{{ __('Category') }}</dt><dd>{{ __(config('modules.assets.categories')[$asset->category] ?? $asset->category) }}</dd></div>
                    @if ($asset->serial_no)<div><dt class="eyebrow text-ink-3">{{ __('Serial / chassis no.') }}</dt><dd class="num">{{ $asset->serial_no }}</dd></div>@endif
                    @if ($asset->isHired())
                        <div><dt class="eyebrow text-ink-3">{{ __('Owner of the machine') }}</dt><dd>{{ $asset->vendor?->name ?? '—' }}</dd></div>
                        <div><dt class="eyebrow text-ink-3">{{ __('Hire rate (₹)') }}</dt><dd class="num">{{ inr($asset->hire_rate) }} · {{ __(config('modules.assets.hire_bases')[$asset->hire_basis] ?? '') }}</dd></div>
                        <div><dt class="eyebrow text-ink-3">{{ __('Fuel') }}</dt><dd>{{ __(config('modules.assets.hire_types')[$asset->hire_type] ?? '') }}</dd></div>
                        @if ($asset->min_hours_per_month !== null)<div><dt class="eyebrow text-ink-3">{{ __('Minimum hours per month') }}</dt><dd class="num">{{ fmt_qty($asset->min_hours_per_month) }}</dd></div>@endif
                    @elseif ($asset->purchase_cost !== null)
                        <div><dt class="eyebrow text-ink-3">{{ __('Purchase cost (₹)') }}</dt><dd class="num">{{ inr($asset->purchase_cost) }}@if ($asset->purchase_date) <span class="text-ink-3">· {{ $asset->purchase_date->format('d M Y') }}</span>@endif</dd></div>
                    @endif
                    @if ($asset->notes)<div><dt class="eyebrow text-ink-3">{{ __('Notes') }}</dt><dd class="whitespace-pre-line">{{ $asset->notes }}</dd></div>@endif
                </dl>
            </section>
        </aside>
    </div>
@endsection
