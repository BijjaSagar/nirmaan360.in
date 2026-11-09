@extends('layouts.app')
@section('title', $activity->name)
@section('content')
    <x-page-header :title="$activity->name" :subtitle="$activity->code.($activity->wbsNode ? ' · '.$activity->wbsNode->name : '')">
        @include('modules.planning.partials.state')
        <a href="{{ route('planning.schedule', $project) }}" class="btn btn-ghost">{{ __('Back to schedule') }}</a>
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat :label="__('Progress')" :value="$progress->percent === null ? '—' : $progress->percent.'%'" :note="$progress->expected === null ? __('Link a BOQ item to track this') : __('Plan says :percent% by today', ['percent' => $progress->expected])" />
        <x-stat :label="__('Planned value')" :value="inr($progress->value)" :note="inr($progress->earned).' '.__('earned')" />
        <x-stat :label="__('Actual start')" :value="$progress->actualStart ? \Illuminate\Support\Carbon::parse($progress->actualStart)->format('d M Y') : '—'" :note="__('Planned').' '.$activity->planned_start->format('d M Y')" />
        <x-stat :label="__('Actual finish')" :value="$progress->actualFinish ? \Illuminate\Support\Carbon::parse($progress->actualFinish)->format('d M Y') : '—'" :note="__('Planned').' '.$activity->planned_end->format('d M Y')" :accent="$progress->isDelayed()" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="card lg:col-span-2">
            <div class="card-head">
                <h2 class="card-title">{{ __('Linked BOQ items') }}</h2>
                <span class="text-xs text-ink-3">{{ __('Progress comes from approved measurements.') }}</span>
            </div>
            @if ($activity->boqLinks->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[640px]">
                        <thead><tr><th>{{ __('Code') }}</th><th class="w-[40%]">{{ __('Description') }}</th><th class="r">{{ __('Linked qty') }}</th><th class="r">{{ __('Approved') }}</th><th>{{ __('Unit') }}</th>@can('planning.manage')<th></th>@endcan</tr></thead>
                        <tbody>
                        @foreach ($activity->boqLinks as $link)
                            <tr>
                                <td class="num whitespace-nowrap">{{ $link->boqItem->code }}</td>
                                <td>{{ $link->boqItem->description }}</td>
                                <td class="r">{{ fmt_qty($link->qty) }}</td>
                                <td class="r">{{ fmt_qty($plan->placedQty($link->id)) }}</td>
                                <td>{{ $link->boqItem->unit }}</td>
                                @can('planning.manage')
                                    <td class="text-right"><form method="POST" action="{{ route('planning.boq-links.destroy', $link) }}" onsubmit="return confirm(@js(__('Unlink this BOQ item?')))">@csrf @method('DELETE')<button class="link text-xs">{{ __('Unlink') }}</button></form></td>
                                @endcan
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="px-4 py-4 text-sm text-ink-3">{{ __('No BOQ items linked yet, so progress cannot be measured.') }}</p>
            @endif
            @can('planning.manage')
                <form method="POST" action="{{ route('planning.boq-links.store', $activity) }}" class="grid gap-3 border-t border-rule p-4 sm:grid-cols-6">
                    @csrf
                    <x-field :label="__('BOQ item')" name="boq_item_id" class="sm:col-span-3">
                        <select id="boq_item_id" name="boq_item_id" class="input" required>
                            <option value="">{{ __('Choose an item') }}</option>
                            @foreach ($boqItems as $item)
                                <option value="{{ $item->id }}" @selected(old('boq_item_id') == $item->id)>{{ $item->code }} · {{ \Illuminate\Support\Str::limit($item->description, 60) }} ({{ fmt_qty($item->qty) }} {{ $item->unit }})</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Quantity')" name="qty" :hint="__('Leave blank to take all that is left.')" class="sm:col-span-2"><input id="qty" name="qty" type="number" step="0.001" min="0" value="{{ old('qty') }}" class="input num"></x-field>
                    <div class="flex items-start sm:col-span-1 sm:pt-[22px]"><button class="btn btn-primary w-full">{{ __('Link') }}</button></div>
                </form>
            @endcan
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Depends on') }}</h2></div>
            @forelse ($activity->predecessorLinks as $link)
                <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm">
                    <span class="min-w-0"><a href="{{ route('planning.activities.show', $link->predecessor) }}" class="link">{{ $link->predecessor->code }}</a> <span class="text-ink-2">{{ $link->predecessor->name }}</span>
                        <span class="num block text-xs text-ink-3">{{ $link->type }}{{ $link->lag_days ? ' · '.__('Lag').' '.$link->lag_days : '' }}</span></span>
                    @can('planning.manage')
                        <form method="POST" action="{{ route('planning.dependencies.destroy', $link) }}">@csrf @method('DELETE')<button class="link text-xs">{{ __('Remove') }}</button></form>
                    @endcan
                </div>
            @empty
                <p class="px-4 py-4 text-sm text-ink-3">{{ __('Nothing comes before this activity.') }}</p>
            @endforelse
            @if ($activity->successorLinks->isNotEmpty())
                <p class="eyebrow border-t border-rule px-4 pt-3 text-ink-3">{{ __('Followed by') }}</p>
                <div class="px-4 pt-1 pb-3 text-sm">
                    @foreach ($activity->successorLinks as $link)
                        <a href="{{ route('planning.activities.show', $link->successor) }}" class="link mr-3 inline-block">{{ $link->successor->code }}</a>
                    @endforeach
                </div>
            @endif
            @can('planning.manage')
                <form method="POST" action="{{ route('planning.dependencies.store', $activity) }}" class="grid gap-3 border-t border-rule p-4 sm:grid-cols-6">
                    @csrf
                    <x-field :label="__('Activity')" name="predecessor_id" class="sm:col-span-6">
                        <select id="predecessor_id" name="predecessor_id" class="input" required>
                            <option value="">{{ __('Choose an activity') }}</option>
                            @foreach ($others as $other)
                                <option value="{{ $other->id }}">{{ $other->code }} · {{ \Illuminate\Support\Str::limit($other->name, 60) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Type')" name="type" class="sm:col-span-3">
                        <select id="type" name="type" class="input">
                            <option value="FS">{{ __('Finish to start') }}</option>
                            <option value="SS">{{ __('Start to start') }}</option>
                            <option value="FF">{{ __('Finish to finish') }}</option>
                            <option value="SF">{{ __('Start to finish') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('Lag (days)')" name="lag_days" class="sm:col-span-2"><input id="lag_days" name="lag_days" type="number" step="1" min="-365" max="365" value="0" class="input num"></x-field>
                    <div class="flex items-end sm:col-span-1"><button class="btn btn-ghost w-full">{{ __('Add') }}</button></div>
                    <p class="hint sm:col-span-6">{{ __('If this link needs more time, this activity and anything after it move later. Links that would make a loop are refused.') }}</p>
                </form>
            @endcan
        </section>

        @can('planning.manage')
            <section class="card" x-data="{ milestone: {{ old('is_milestone', $activity->is_milestone) ? 'true' : 'false' }} }">
                <div class="card-head"><h2 class="card-title">{{ __('Dates and details') }}</h2></div>
                <form method="POST" action="{{ route('planning.activities.update', $activity) }}" class="grid gap-3 p-4 sm:grid-cols-2">
                    @csrf @method('PATCH')
                    <x-field :label="__('Activity name')" name="name" class="sm:col-span-2"><input id="name" name="name" value="{{ old('name', $activity->name) }}" class="input" required maxlength="255"></x-field>
                    <x-field :label="__('Code')" name="code"><input id="code" name="code" value="{{ old('code', $activity->code) }}" class="input" maxlength="40"></x-field>
                    <x-field :label="__('WBS')" name="wbs_node_id">
                        <select id="wbs_node_id" name="wbs_node_id" class="input">
                            <option value="">{{ __('None') }}</option>
                            @foreach ($nodes as $node)
                                <option value="{{ $node->id }}" @selected(old('wbs_node_id', $activity->wbs_node_id) == $node->id)>{{ $node->code }} · {{ $node->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Planned start')" name="planned_start"><input id="planned_start" name="planned_start" type="date" value="{{ old('planned_start', $activity->planned_start->toDateString()) }}" class="input num" required></x-field>
                    <x-field :label="__('Planned finish')" name="planned_end" x-show="!milestone"><input id="planned_end" name="planned_end" type="date" value="{{ old('planned_end', $activity->planned_end->toDateString()) }}" class="input num"></x-field>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" name="is_milestone" value="1" class="size-4" x-model="milestone">{{ __('This is a milestone') }}</label>
                    <x-field :label="__('Notes')" name="notes" class="sm:col-span-2"><textarea id="notes" name="notes" rows="2" class="input" maxlength="2000">{{ old('notes', $activity->notes) }}</textarea></x-field>
                    <div class="flex items-center justify-between sm:col-span-2">
                        <button class="btn btn-primary">{{ __('Save') }}</button>
                        @if ($activity->baseline_start)
                            <span class="num text-xs text-ink-3">{{ __('Baseline') }} {{ $activity->baseline_start->format('d M') }} → {{ $activity->baseline_end->format('d M') }}</span>
                        @endif
                    </div>
                </form>
                <form method="POST" action="{{ route('planning.activities.destroy', $activity) }}" class="border-t border-rule p-4" onsubmit="return confirm(@js(__('Delete this activity and its links?')))">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger">{{ __('Delete activity') }}</button>
                </form>
            </section>
        @endcan
    </div>
@endsection
