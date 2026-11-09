@extends('layouts.app')
@section('title', __('Schedule'))
@section('content')
    @php
        $openPanel = match (true) {
            $errors->has('file') => 'import',
            $errors->has('wbs') || $errors->has('parent_id') => 'wbs',
            $errors->any() => 'activity',
            default => null,
        };
    @endphp
    <div x-data="{ panel: @js($openPanel) }">
        <x-page-header :title="__('Schedule')" :subtitle="$project->name">
            @can('planning.manage')
                <button type="button" class="btn btn-ghost" @click="panel = panel === 'import' ? null : 'import'">{{ __('Import') }}</button>
                <button type="button" class="btn btn-ghost" @click="panel = panel === 'wbs' ? null : 'wbs'">{{ __('WBS') }}</button>
                <button type="button" class="btn btn-primary" @click="panel = panel === 'activity' ? null : 'activity'">{{ __('Add activity') }}</button>
            @endcan
        </x-page-header>

        @include('modules.planning.partials.tabs')

        @can('planning.manage')
            <form x-show="panel === 'activity'" x-cloak method="POST" action="{{ route('planning.activities.store', $project) }}" class="card mb-5" x-data="{ milestone: {{ old('is_milestone') ? 'true' : 'false' }} }">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ old('client_uuid', \Illuminate\Support\Str::uuid()) }}">
                <div class="card-head"><h2 class="card-title">{{ __('Add activity') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-6">
                    <x-field :label="__('Activity name')" name="name" class="sm:col-span-4"><input id="name" name="name" value="{{ old('name') }}" class="input" required maxlength="255"></x-field>
                    <x-field :label="__('Code')" name="code" :hint="__('Leave blank to number it automatically.')" class="sm:col-span-2"><input id="code" name="code" value="{{ old('code') }}" class="input" maxlength="40"></x-field>
                    <x-field :label="__('WBS')" name="wbs_node_id" class="sm:col-span-2">
                        <select id="wbs_node_id" name="wbs_node_id" class="input">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($nodes as $node)
                                <option value="{{ $node->id }}" @selected(old('wbs_node_id') == $node->id)>{{ $node->code }} · {{ $node->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Planned start')" name="planned_start" class="sm:col-span-2"><input id="planned_start" name="planned_start" type="date" value="{{ old('planned_start') }}" class="input num" required></x-field>
                    <x-field :label="__('Planned finish')" name="planned_end" class="sm:col-span-2" x-show="!milestone"><input id="planned_end" name="planned_end" type="date" value="{{ old('planned_end') }}" class="input num"></x-field>
                    <label class="flex items-center gap-2 text-sm sm:col-span-4"><input type="checkbox" name="is_milestone" value="1" class="size-4" x-model="milestone">{{ __('This is a milestone') }}</label>
                    <div class="flex items-end sm:col-span-2"><button class="btn btn-primary w-full">{{ __('Add activity') }}</button></div>
                </div>
            </form>

            <section x-show="panel === 'wbs'" x-cloak class="card mb-5">
                <div class="card-head"><h2 class="card-title">{{ __('Work breakdown structure') }}</h2></div>
                <form method="POST" action="{{ route('planning.wbs.store', $project) }}" class="grid gap-3 p-4 sm:grid-cols-6">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <x-field :label="__('Code')" name="wbs_code" class="sm:col-span-1"><input id="wbs_code" name="code" class="input" required maxlength="40"></x-field>
                    <x-field :label="__('Name')" name="wbs_name" class="sm:col-span-3"><input id="wbs_name" name="name" class="input" required maxlength="255"></x-field>
                    <x-field :label="__('Inside')" name="parent_id" class="sm:col-span-2">
                        <select id="parent_id" name="parent_id" class="input">
                            <option value="">{{ __('Top level') }}</option>
                            @foreach ($nodes as $node)
                                <option value="{{ $node->id }}">{{ $node->code }} · {{ $node->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <div class="sm:col-span-6"><button class="btn btn-primary">{{ __('Add WBS node') }}</button></div>
                </form>
                @if ($nodes->isNotEmpty())
                    <div class="overflow-x-auto border-t border-rule">
                        <table class="ledger">
                            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($nodes as $node)
                                <tr x-data="{ edit: false }">
                                    <td class="num whitespace-nowrap">{{ $node->code }}</td>
                                    <td>
                                        <span x-show="!edit">{{ $node->name }}</span>
                                        <form x-show="edit" x-cloak method="POST" action="{{ route('planning.wbs.update', $node) }}" class="flex flex-wrap items-center gap-2">
                                            @csrf @method('PATCH')
                                            <input name="code" value="{{ $node->code }}" class="input w-24" aria-label="{{ __('Code') }}" required maxlength="40">
                                            <input name="name" value="{{ $node->name }}" class="input min-w-40 flex-1" aria-label="{{ __('Name') }}" required maxlength="255">
                                            <select name="parent_id" class="input w-auto" aria-label="{{ __('Inside') }}">
                                                <option value="">{{ __('Top level') }}</option>
                                                @foreach ($nodes as $other)
                                                    @if ($other->id !== $node->id)<option value="{{ $other->id }}" @selected($node->parent_id === $other->id)>{{ $other->code }}</option>@endif
                                                @endforeach
                                            </select>
                                            <button class="btn btn-primary">{{ __('Save') }}</button>
                                        </form>
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <button type="button" class="link text-xs" @click="edit = !edit">{{ __('Edit') }}</button>
                                        <form method="POST" action="{{ route('planning.wbs.destroy', $node) }}" class="ml-2 inline" onsubmit="return confirm(@js(__('Remove this WBS node?')))">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <form x-show="panel === 'import'" x-cloak method="POST" action="{{ route('planning.import.store', $project) }}" enctype="multipart/form-data" class="card mb-5">
                @csrf
                <div class="card-head"><h2 class="card-title">{{ __('Import from Microsoft Project') }}</h2></div>
                <div class="space-y-3 p-4">
                    <p class="text-sm text-ink-3">{{ __('In Microsoft Project choose File, Save As, and the XML format. Summary tasks become WBS nodes. Tasks imported earlier are updated and keep their BOQ links. If any task has a problem, nothing is imported.') }}</p>
                    <x-field :label="__('XML file')" name="file"><input id="file" name="file" type="file" accept=".xml,text/xml,application/xml" class="input" required></x-field>
                    <button class="btn btn-primary">{{ __('Import') }}</button>
                </div>
            </form>
        @endcan

        @if ($plan->activities->isEmpty())
            <div class="card">
                <x-empty :title="__('No activities yet')" :note="__('Add activities with their planned dates, or import them from Microsoft Project.')" />
            </div>
        @else
            @include('modules.planning.partials.gantt')

            <section class="card mt-5">
                <div class="card-head"><h2 class="card-title">{{ __('Activities') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[760px]">
                        <thead>
                        <tr>
                            <th>{{ __('Code') }}</th><th class="w-[34%]">{{ __('Activity') }}</th><th>{{ __('Planned start') }}</th><th>{{ __('Planned finish') }}</th>
                            <th class="r">{{ __('Baseline slip') }}</th><th class="r">{{ __('Progress') }}</th><th>{{ __('Status') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($plan->activities as $activity)
                            @php $progress = $plan->progress[$activity->id]; @endphp
                            <tr>
                                <td class="num whitespace-nowrap">{{ $activity->code }}</td>
                                <td><a href="{{ route('planning.activities.show', $activity) }}" class="link">{{ $activity->name }}</a></td>
                                <td class="num whitespace-nowrap">{{ $activity->planned_start->format('d M Y') }}</td>
                                <td class="num whitespace-nowrap">{{ $activity->planned_end->format('d M Y') }}</td>
                                <td class="r">{{ $progress->baselineSlip === null ? '—' : ($progress->baselineSlip > 0 ? '+' : '').$progress->baselineSlip }}</td>
                                <td class="r">{{ $progress->percent === null ? '—' : $progress->percent.'%' }}</td>
                                <td>@include('modules.planning.partials.state')</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
