@extends('layouts.app')
@section('title', __('Auto BOQ'))
@section('content')
    @php
        use App\Services\AutoBoq\Assumptions;
        use App\Services\AutoBoq\AutoBoqCalculator;

        // The posted values come back after a validation error; otherwise the saved ones.
        $form = old('floors') !== null ? array_merge($form, old()) : $form;

        // Group => [heading, hint, columns]. A column is [field, label, step, takeoff unit it can be filled from].
        $groups = [
            'footings' => [__('Footings'), __('Isolated footings. Depth is the footing thickness; the pit depth comes from the foundation depth above.'), [
                ['length', __('Length (m)'), '0.001', null], ['breadth', __('Breadth (m)'), '0.001', null], ['depth', __('Depth (m)'), '0.001', null], ['nos', __('Nos'), '1', 'nos'],
            ]],
            'columns' => [__('Columns'), __('One row per size and floor range. Leave the height blank to use the floor height.'), [
                ['length', __('Length (m)'), '0.001', null], ['breadth', __('Breadth (m)'), '0.001', null], ['height', __('Height (m)'), '0.001', null], ['nos', __('Nos'), '1', 'nos'], ['floors', __('Floors'), '1', null],
            ]],
            'beams' => [__('Beams'), __('The total length of all beams of one size on one floor. Floors repeats the row.'), [
                ['length', __('Total length (m)'), '0.001', 'rmt'], ['width', __('Width (m)'), '0.001', null], ['depth', __('Depth (m)'), '0.001', null], ['floors', __('Floors'), '1', null],
            ]],
            'slabs' => [__('Slabs'), __('List slabs from the lowest up; the last one is the terrace. Openings are open-to-sky, staircase and duct cut-outs.'), [
                ['area', __('Area (sqm)'), '0.001', 'sqm'], ['openings', __('Openings (sqm)'), '0.001', 'sqm'], ['thickness', __('Thickness (m)'), '0.001', null], ['count', __('Count'), '1', null],
            ]],
            'walls' => [__('Walls'), __('The total length of all walls of one kind and thickness on one floor, with the area of the doors and windows in them. Leave the height blank to use the floor height.'), [
                ['length', __('Total length (m)'), '0.001', 'rmt'], ['height', __('Height (m)'), '0.001', null], ['thickness', __('Thickness (m)'), '0.001', null], ['openings', __('Openings (sqm)'), '0.001', 'sqm'], ['floors', __('Floors'), '1', null],
            ]],
        ];

        $scalars = [
            ['plinth_area', __('Plinth area (sqm)'), '0.001', 'sqm', __('The built-up area at plinth level: the ground floor and its PCC bed.')],
            ['plinth_height', __('Plinth height (m)'), '0.001', null, __('Filled from ground level up to the floor.')],
            ['foundation_depth', __('Foundation depth (m)'), '0.001', null, __('From ground level to the bottom of the PCC under the footings.')],
            ['stair_area', __('Staircase plan area (sqm)'), '0.001', 'sqm', __('One flight set, repeated on every floor.')],
            ['chajja_length', __('Chajja and loft length (m)'), '0.001', 'rmt', __('The total for the whole building.')],
            ['chajja_width', __('Chajja width (m)'), '0.001', null, null],
            ['chajja_thickness', __('Chajja thickness (m)'), '0.001', null, null],
        ];

        $statusWords = ['new' => __('New line'), 'update' => __('Quantity changes'), 'same' => __('No change')];
        $statusTones = ['new' => 'border-moss text-moss', 'update' => 'border-neel text-neel', 'same' => 'border-ink-3 text-ink-3'];
        $hasTakeoff = $takeoffByUnit !== [];
    @endphp

    <x-page-header :title="__('Auto BOQ from dimensions')" :subtitle="$project->name">
        <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost">{{ __('Back to BOQ & ledger') }}</a>
        <a href="{{ route('auto-boq.assumptions') }}" class="btn btn-ghost">{{ __('Assumptions') }}</a>
    </x-page-header>

    <p class="mb-5 rounded-[3px] border border-rule bg-paper/60 px-3 py-2 text-sm text-ink-2">
        {{ __('This works from the dimensions you type in or measure on the drawings with the takeoff. It does not read the drawing itself. Structure and RCC are worked out by formula; tiles detail, doors, electrical and plumbing come out as empty lines for you to fill in, because they need their own drawings.') }}
    </p>

    @if ($errors->any())
        <div class="mb-5 rounded-[3px] border border-laterite bg-laterite/5 px-3 py-2 text-sm text-laterite" role="alert">
            <p class="font-semibold">{{ __('Please check the figures.') }}</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('auto-boq.save', $project) }}" class="card"
          x-data="{
              d: @js($form),
              totals: @js($takeoffTotals),
              blank: @js($blank),
              add(g) { this.d[g].push({ ...this.blank[g] }); },
              remove(g, i) { this.d[g].splice(i, 1); },
              fill(target, field, unit) {
                  const id = target[field + '_layer'];
                  if (id && this.totals[id + '|' + unit] !== undefined) target[field] = this.totals[id + '|' + unit];
              },
          }">
        @csrf @method('PUT')
        <div class="card-head">
            <h2 class="card-title">{{ __('Building dimensions') }}</h2>
            @if ($input)
                <span class="text-xs text-ink-3">{{ __('Saved :when', ['when' => $input->updated_at->diffForHumans()]) }}</span>
            @endif
        </div>

        <div class="space-y-6 p-4">
            <p class="text-sm text-ink-3">
                @if ($hasTakeoff)
                    {{ __('A field with a drawing picker can take the total of a takeoff layer measured on the latest drawings. Saving again after re-measuring refreshes it.') }}
                @elseif (auth()->user()->organization->allowsFeature('drawings'))
                    {{ __('Measure layers on the drawings with the takeoff and their totals can fill these fields.') }}
                @endif
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-field :label="__('Number of floors')" name="floors" :hint="__('The ground floor counts as one. Used for the staircase.')">
                    <input id="floors" name="floors" type="number" min="1" max="60" step="1" x-model="d.floors" class="input num" required>
                </x-field>
                <x-field :label="__('Floor height (m)')" name="floor_height" :hint="__('Used wherever a column or wall height is left blank.')">
                    <input id="floor_height" name="floor_height" type="number" min="0" max="30" step="0.001" x-model="d.floor_height" class="input num" required>
                </x-field>
                @foreach ($scalars as [$field, $label, $step, $unit, $hint])
                    <x-field :label="$label" :name="$field" :hint="$hint">
                        <input id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="{{ $step }}" x-model="d.{{ $field }}" class="input num" :readonly="!! d.{{ $field }}_layer">
                        @if ($unit && ! empty($takeoffByUnit[$unit]))
                            <select name="{{ $field }}_layer" x-model="d.{{ $field }}_layer" @change="fill(d, '{{ $field }}', '{{ $unit }}')" class="input mt-1 text-xs" aria-label="{{ __('From drawing') }}">
                                <option value="">{{ __('Typed in') }}</option>
                                @foreach ($takeoffByUnit[$unit] as $choice)
                                    <option value="{{ $choice['id'] }}">{{ __('From drawing') }}: {{ $choice['layer'] }} · {{ fmt_qty($choice['qty']) }} {{ $choice['unit'] }}</option>
                                @endforeach
                            </select>
                        @endif
                    </x-field>
                @endforeach
            </div>

            @foreach ($groups as $group => [$heading, $hint, $columns])
                <section>
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 class="font-display text-base font-semibold">{{ $heading }}</h3>
                            <p class="text-xs text-ink-3">{{ $hint }}</p>
                        </div>
                        <button type="button" class="btn btn-ghost" @click="add('{{ $group }}')">{{ __('Add row') }}</button>
                    </div>
                    <div class="mt-2 overflow-x-auto">
                        <table class="ledger min-w-[640px]">
                            <thead>
                                <tr>
                                    <th>{{ __('Label') }}</th>
                                    @if ($group === 'walls')<th>{{ __('Kind') }}</th>@endif
                                    @foreach ($columns as $column)<th>{{ $column[1] }}</th>@endforeach
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, i) in d.{{ $group }}" :key="i">
                                    <tr>
                                        <td class="min-w-28"><input class="input" :name="`{{ $group }}[${i}][label]`" x-model="row.label" maxlength="40" placeholder="{{ __('Optional') }}"></td>
                                        @if ($group === 'walls')
                                            <td class="min-w-28">
                                                <select class="input" :name="`{{ $group }}[${i}][kind]`" x-model="row.kind">
                                                    <option value="external">{{ __('External') }}</option>
                                                    <option value="internal">{{ __('Internal') }}</option>
                                                </select>
                                            </td>
                                        @endif
                                        @foreach ($columns as [$field, $label, $step, $unit])
                                            <td class="min-w-28">
                                                <input type="number" min="0" step="{{ $step }}" class="input num" :name="`{{ $group }}[${i}][{{ $field }}]`" x-model="row.{{ $field }}" :readonly="!! row.{{ $field }}_layer" aria-label="{{ $label }}">
                                                @if ($unit && ! empty($takeoffByUnit[$unit]))
                                                    <select class="input mt-1 text-xs" :name="`{{ $group }}[${i}][{{ $field }}_layer]`" x-model="row.{{ $field }}_layer" @change="fill(row, '{{ $field }}', '{{ $unit }}')" aria-label="{{ __('From drawing') }}">
                                                        <option value="">{{ __('Typed in') }}</option>
                                                        @foreach ($takeoffByUnit[$unit] as $choice)
                                                            <option value="{{ $choice['id'] }}">{{ $choice['layer'] }} · {{ fmt_qty($choice['qty']) }} {{ $choice['unit'] }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="text-right"><button type="button" class="btn btn-ghost" @click="remove('{{ $group }}', i)" aria-label="{{ __('Remove row') }}">×</button></td>
                                    </tr>
                                </template>
                                <tr x-show="! d.{{ $group }}.length"><td colspan="{{ count($columns) + ($group === 'walls' ? 3 : 2) }}" class="text-sm text-ink-3">{{ __('No rows yet.') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-rule pt-4">
                <p class="text-sm text-ink-3">{{ __('Saving works the lines out again. Nothing reaches the BOQ until you apply them below.') }}</p>
                <button class="btn btn-primary">{{ __('Save and work out') }}</button>
            </div>
        </div>
    </form>

    @if ($problem)
        <div class="mt-5 rounded-[3px] border border-laterite bg-laterite/5 px-3 py-2 text-sm text-laterite" role="alert">{{ $problem }}</div>
    @elseif ($preview)
        <form method="POST" action="{{ route('auto-boq.apply', $project) }}" class="card mt-5" onsubmit="return confirm(@js(__('Write the ticked lines into the BOQ?')))">
            @csrf
            <div class="card-head">
                <h2 class="card-title">{{ __('Lines worked out') }}</h2>
                <span class="text-xs text-ink-3">
                    @if ($input->applied_at)
                        {{ __('Last applied :when', ['when' => $input->applied_at->diffForHumans()]) }}
                    @else
                        {{ __('Not applied yet') }}
                    @endif
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="ledger min-w-[900px]">
                    <thead>
                        <tr>
                            <th><span class="sr-only">{{ __('Apply') }}</span></th>
                            <th>{{ __('Code') }}</th>
                            <th class="w-[38%]">{{ __('Description and working') }}</th>
                            <th>{{ __('Unit') }}</th>
                            <th class="r">{{ __('Quantity') }}</th>
                            <th class="r">{{ __('In BOQ now') }}</th>
                            <th class="r">{{ __('Approved') }}</th>
                            @if ($rateChoices !== null)<th>{{ __('Rate from library') }}</th>@endif
                            <th>{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (AutoBoqCalculator::GROUPS as $groupKey => $groupLabel)
                            <tr><td colspan="{{ $rateChoices !== null ? 9 : 8 }}" class="eyebrow bg-paper-2/60 text-ink-2">{{ __($groupLabel) }}</td></tr>
                            @foreach ($preview->filter(fn ($row) => $row['line']->group === $groupKey) as $code => $row)
                                @php $line = $row['line']; $blocked = $row['below'] || $row['unit_differs']; @endphp
                                <tr>
                                    <td><input type="checkbox" name="codes[]" value="{{ $code }}" @checked($row['selected']) @disabled($blocked) aria-label="{{ __('Apply :code', ['code' => $code]) }}"></td>
                                    <td class="num text-xs whitespace-nowrap">{{ $code }}</td>
                                    <td>
                                        <span class="font-medium">{{ $line->description }}</span>
                                        @if ($line->working !== [] || $line->note)
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs text-neel">{{ $line->computed ? __('Working') : __('Why zero') }}</summary>
                                                <pre class="mt-1 font-mono text-xs leading-5 whitespace-pre-wrap text-ink-2">{{ $line->details() }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                    <td>{{ $line->unit }}</td>
                                    <td class="r">{{ fmt_qty($line->qty) }}</td>
                                    <td class="r">{{ $row['existing'] ? fmt_qty($row['existing']->qty) : '—' }}</td>
                                    <td class="r">{{ $row['existing'] ? fmt_qty($row['approved']) : '—' }}</td>
                                    @if ($rateChoices !== null)
                                        <td>
                                            @php $choices = $rateChoices[\App\Services\Drawings\TakeoffService::dimensionOfUnit($line->unit) ?? strtolower($line->unit)] ?? []; @endphp
                                            @if ($choices !== [])
                                                <select name="rates[{{ $code }}]" class="input text-xs" aria-label="{{ __('Rate for :code', ['code' => $code]) }}">
                                                    <option value="">{{ $row['existing'] ? __('Keep :rate', ['rate' => inr($row['existing']->rate)]) : __('No rate yet') }}</option>
                                                    @foreach ($choices as $rate)
                                                        <option value="{{ $rate->id }}">{{ $rate->item }} · {{ inr($rate->rate) }} / {{ $rate->unit }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <span class="text-xs text-ink-3">{{ $row['existing'] ? inr($row['existing']->rate) : __('No rate yet') }}</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td>
                                        <span class="stamp {{ $statusTones[$row['status']] }}">{{ $statusWords[$row['status']] }}</span>
                                        @if ($row['below'])<span class="stamp mt-1 border-laterite text-laterite">{{ __('Below approved quantity') }}</span>@endif
                                        @if ($row['unit_differs'])<span class="stamp mt-1 border-laterite text-laterite">{{ __('Unit differs in the BOQ') }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid gap-3 border-t border-rule p-4 sm:grid-cols-3">
                <p class="text-sm text-ink-3 sm:col-span-3">{{ __('Untick a line to leave it out. A line already in the BOQ with the same code gets the new quantity and working; its description, unit and rate stay as they are unless you pick a rate. Every change is kept in the BOQ history.') }}</p>
                <x-field :label="__('Reason (optional)')" name="reason" class="sm:col-span-2"><input id="reason" name="reason" value="{{ old('reason') }}" maxlength="500" class="input" placeholder="{{ __('For example: structural drawings R1') }}"></x-field>
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Apply ticked lines to the BOQ') }}</button></div>
            </div>
        </form>
    @else
        <div class="card mt-5">
            <x-empty :title="__('No lines yet')" :note="__('Save the dimensions above and the lines appear here for you to check before anything reaches the BOQ.')" />
        </div>
    @endif

    <section class="card mt-5">
        <div class="card-head">
            <h2 class="card-title">{{ __('Assumptions used') }}</h2>
            <a href="{{ route('auto-boq.assumptions') }}" class="link text-sm">{{ __('Change for the company') }}</a>
        </div>
        <p class="px-4 pt-3 text-sm text-ink-3">{{ __('Typical values. Your engineer should change them to match how your company builds.') }}</p>
        <div class="grid gap-x-6 p-4 sm:grid-cols-2">
            @foreach (Assumptions::GROUPS as $groupKey => $groupLabel)
                <dl class="text-sm">
                    <dt class="eyebrow mt-2 text-ink-3">{{ __($groupLabel) }}</dt>
                    @foreach (Assumptions::CATALOGUE as $key => [$default, $unit, $assumptionGroup, $label, $note])
                        @continue($assumptionGroup !== $groupKey)
                        <dd class="flex justify-between gap-3 border-b border-rule py-1"><span>{{ __($label) }}</span><span class="num whitespace-nowrap">{{ fmt_qty($assumptions[$key]) }} <span class="text-ink-3">{{ $unit }}</span></span></dd>
                    @endforeach
                </dl>
            @endforeach
        </div>
    </section>
@endsection
