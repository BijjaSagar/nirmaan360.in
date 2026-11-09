@extends('layouts.app')
@section('title', __('Stage tracker'))
@section('content')
    @php
        $show = \App\Services\SiteProgress\ProgressReport::show(...);
        $stages = $trades[$trade]['stages'];
        $canEdit = auth()->user()->can('progress.create');
        $tone = [
            'not_started' => ['border-rule bg-sheet text-ink-3', '·'],
            'in_progress' => ['border-neel bg-neel/15 text-neel', '◐'],
            'done' => ['border-moss bg-moss text-white', '✓'],
            'pending' => ['border-laterite bg-laterite text-white', '!'],
            'na' => ['border-rule bg-paper-2 text-ink-3', '–'],
        ];
    @endphp
    <x-page-header :title="__('Stage tracker')" :subtitle="$project->name" />
    @include('modules.site_progress._tabs', ['active' => 'site-stages.index'])

    <div x-data="{
            open: false, cell: {}, status: '', uuid: '', busy: false,
            newUuid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }); },
            pick(el) { const d = el.dataset; this.cell = { ...d, photos: d.photos ? d.photos.split(',') : [] }; this.status = d.status; this.uuid = this.newUuid(); this.busy = false; this.open = true; },
        }" @keydown.escape.window="open = false">

        <div class="mb-4 flex flex-wrap items-center gap-2">
            @foreach ($trades as $key => $item)
                <a href="{{ route('site-stages.index', [$project, 'trade' => $key]) }}" class="btn min-h-11 px-5 text-base {{ $trade === $key ? 'btn-ink' : 'btn-ghost' }}" @if ($trade === $key) aria-current="page" @endif>{{ __($item['label']) }}</a>
            @endforeach
        </div>

        <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-ink-2" aria-label="{{ __('Colour key') }}">
            @foreach ($statuses as $key => $label)
                <span class="flex items-center gap-1.5"><span class="grid size-6 place-items-center rounded-[3px] border text-xs font-bold {{ $tone[$key][0] }}">{{ $tone[$key][1] }}</span>{{ __($label) }}</span>
            @endforeach
            <span class="flex items-center gap-1.5"><span class="grid size-6 place-items-center rounded-[3px] border border-moss bg-moss text-xs font-bold text-white ring-2 ring-haldi">✓</span>{{ __('Done while an earlier stage is pending') }}</span>
        </div>

        @if (count($matrix['rows']) === 0)
            <div class="card">
                <x-empty :title="__('No floors yet')" :note="__('Add the floors and flats first. Each one becomes a row in this tracker.')">
                    @can('progress.manage')<a href="{{ route('site-progress.floors.index', $project) }}" class="btn btn-primary min-h-11">{{ __('Add floors') }}</a>@endcan
                </x-empty>
            </div>
        @else
            <div class="card overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-ink/70 bg-paper-2/60">
                            <th class="sticky left-0 z-10 min-w-28 bg-paper-2 px-3 py-2 text-left font-mono text-[11px] font-medium tracking-wider text-ink-2 uppercase">{{ __('Floor') }}</th>
                            @foreach ($stages as $stageKey => $stage)
                                <th class="px-1 py-2 text-center font-mono text-[11px] font-medium text-ink-2" title="{{ __($stage['label']) }}">{{ $loop->iteration }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right font-mono text-[11px] font-medium text-ink-2">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix['rows'] as $row)
                            <tr class="border-b border-rule" id="row-{{ $row['id'] }}">
                                <th scope="row" class="sticky left-0 z-10 min-w-28 max-w-40 bg-sheet px-3 py-2 text-left align-middle font-semibold {{ $row['level'] ? 'pl-6 font-normal' : '' }}">
                                    <span class="block break-words">{{ $row['name'] }}</span>
                                    @if ($row['pending'] > 0)<span class="stamp border-laterite text-laterite">{{ __(':count pending', ['count' => $row['pending']]) }}</span>@endif
                                    @if ($row['level'] === 0)<a href="{{ route('site-stages.checklist', $row['id']) }}" class="link block text-xs font-normal">{{ __('Before finishing') }}</a>@endif
                                </th>
                                @foreach ($stages as $stageKey => $stage)
                                    @php($cell = $row['cells'][$trade][$stageKey])
                                    <td class="px-1 py-1.5 text-center">
                                        <button type="button" @click="pick($el)"
                                                class="relative grid size-11 place-items-center rounded-[3px] border text-base font-bold {{ $tone[$cell['status']][0] }} {{ $cell['warning'] ? 'ring-2 ring-haldi' : '' }}"
                                                data-floor="{{ $row['id'] }}" data-trade="{{ $trade }}" data-stage="{{ $stageKey }}"
                                                data-row="{{ $row['label'] }}" data-label="{{ __($stage['label']) }}" data-hint="{{ isset($stage['hint']) ? __($stage['hint']) : '' }}"
                                                data-optional="{{ ($stage['optional'] ?? false) ? 1 : 0 }}" data-status="{{ $cell['status'] }}" data-remark="{{ $cell['remark'] }}"
                                                data-photos="{{ implode(',', $cell['photos']) }}" data-warning="{{ $cell['warning'] ? 1 : 0 }}"
                                                aria-label="{{ $row['label'] }}, {{ __($stage['label']) }}: {{ __($statuses[$cell['status']]) }}">
                                            {{ $tone[$cell['status']][1] }}
                                            @if (count($cell['photos']) > 0)<span class="absolute -right-1 -bottom-1 grid size-4 place-items-center rounded-full bg-ink text-[9px] font-medium text-paper">{{ count($cell['photos']) }}</span>@endif
                                        </button>
                                    </td>
                                @endforeach
                                <td class="num whitespace-nowrap px-3 py-2 text-right font-medium">{{ $show($row['percent'][$trade]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ol class="card mt-4 divide-y divide-rule text-sm">
                @foreach ($stages as $stageKey => $stage)
                    <li class="flex gap-3 px-4 py-2.5">
                        <span class="num w-5 shrink-0 text-ink-3">{{ $loop->iteration }}</span>
                        <span>{{ __($stage['label']) }}@if (isset($stage['hint']))<span class="block text-xs text-ink-3">{{ __($stage['hint']) }}</span>@endif</span>
                    </li>
                @endforeach
            </ol>
        @endif

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" :aria-label="cell.label">
            <div class="absolute inset-0 bg-ink/50" @click="open = false"></div>
            <div class="relative max-h-[92vh] w-full overflow-y-auto rounded-t-[10px] bg-sheet p-4 shadow-xl sm:max-w-lg sm:rounded-[6px]">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="eyebrow text-ink-3" x-text="cell.row"></p>
                        <h2 class="text-xl" x-text="cell.label"></h2>
                        <p class="mt-0.5 text-xs text-ink-3" x-show="cell.hint" x-text="cell.hint"></p>
                    </div>
                    <button type="button" class="btn btn-ghost min-h-11 min-w-11" @click="open = false" aria-label="{{ __('Close') }}">✕</button>
                </div>

                <p class="mb-3 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" x-show="cell.warning === '1'">{{ __('This stage is marked done, but an earlier stage on this row is still pending.') }}</p>
                <p class="mb-3 text-sm text-ink-2" x-show="cell.remark"><span class="font-semibold">{{ __('Current note') }}:</span> <span x-text="cell.remark"></span></p>
                <div class="mb-3 flex flex-wrap gap-1.5" x-show="cell.photos && cell.photos.length">
                    <template x-for="id in cell.photos" :key="id">
                        <a :href="'{{ url('/progress/photos') }}/' + id" target="_blank" rel="noopener"><img :src="'{{ url('/progress/photos') }}/' + id" alt="{{ __('Site photo') }}" loading="lazy" class="size-16 rounded-[3px] border border-rule object-cover"></a>
                    </template>
                </div>

                @if ($canEdit)
                    <form method="POST" action="{{ route('site-stages.update', $project) }}" enctype="multipart/form-data" class="space-y-4" @submit="busy = true">
                        @csrf
                        <input type="hidden" name="project_floor_id" :value="cell.floor">
                        <input type="hidden" name="trade" :value="cell.trade">
                        <input type="hidden" name="stage" :value="cell.stage">
                        <input type="hidden" name="client_uuid" :value="uuid">

                        <fieldset>
                            <legend class="label">{{ __('Status') }}</legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($statuses as $key => $label)
                                    <label class="flex min-h-12 cursor-pointer items-center gap-2 rounded-[3px] border px-3 text-[15px] font-semibold"
                                           :class="status === '{{ $key }}' ? 'border-ink bg-paper-2' : 'border-rule'"
                                           @if ($key === 'na') x-show="cell.optional === '1'" @endif>
                                        <input type="radio" name="status" value="{{ $key }}" x-model="status" class="size-5 accent-[#16201f]" required>
                                        <span class="grid size-6 place-items-center rounded-[3px] border text-xs font-bold {{ $tone[$key][0] }}">{{ $tone[$key][1] }}</span>
                                        {{ __($label) }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        <x-field :label="__('Remark')">
                            <textarea name="remark" rows="2" maxlength="1000" class="input text-base" placeholder="{{ __('For example: outside passage wiring not done') }}" :required="status === 'pending'"></textarea>
                            <p class="hint" x-show="status === 'pending'">{{ __('Say what is pending.') }}</p>
                        </x-field>

                        <x-field :label="__('Photos')">
                            <input name="photos[]" type="file" accept="image/*" multiple class="input min-h-12 text-base">
                        </x-field>

                        <button class="btn btn-primary min-h-12 w-full text-base" :disabled="busy || status === ''">{{ __('Save') }}</button>
                    </form>
                @else
                    <p class="text-sm text-ink-3">{{ __('You can view this stage but not change it.') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection
