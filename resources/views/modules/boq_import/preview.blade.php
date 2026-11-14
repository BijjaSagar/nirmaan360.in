@extends('layouts.app')
@section('title', __('Check the columns before importing'))
@section('content')
    @php
        $canConfirm = $preview['found'] && $preview['errors'] === [] && $preview['rows'] > 0;
        $kinds = [
            'line' => __('Line'), 'rate_only' => __('Rate only'), 'section' => __('Section'), 'subheading' => __('Sub-heading'),
            'spec' => __('Specification'), 'part' => __('Break-up'), 'subtotal' => __('Subtotal'), 'total' => __('Total'),
            'carried' => __('Carried forward'), 'say' => __('Say'), 'words' => __('In words'), 'adjustment' => __('Adjustment'),
            'note' => __('Note'), 'skipped' => __('Left out'), 'after_total' => __('After the total'),
            'column_numbers' => __('Column numbers'), 'heading_repeat' => __('Heading again'),
            'error' => __('Problem'), 'excluded' => __('Left out by you'), 'furniture' => __('Page or signature line'),
        ];
        $hows = ['heading' => __('From the heading'), 'values' => __('From the values'), 'saved' => __('Saved layout'), 'chosen' => __('Your choice')];
        $errorFields = $fields;
    @endphp
    <x-page-header :title="__('Check the columns before importing')" :subtitle="$project->name">
        <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-ghost">{{ __('Back to import') }}</a>
    </x-page-header>

    <p class="mb-4 text-sm text-ink-3">{{ __('This is how :file was read, by its own headings. Nothing has been saved yet. If a column was read wrongly, change it below and the lines are read again.', ['file' => $preview['file']]) }}</p>

    @if ($preview['saved'])
        <div class="mb-4 rounded-[3px] border border-moss/40 bg-moss/5 px-3 py-2 text-sm" role="status">
            {{ __('This file is laid out like one imported before (:file), so its columns were set the same way. Check the lines and confirm.', ['file' => $preview['saved']['file'] ?? '—']) }}
        </div>
    @endif

    <form method="POST" action="{{ route('boq.sheet.choose', $project) }}" class="card mb-5" x-data @change="if ($event.target.tagName === 'SELECT') $el.requestSubmit()">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="card-head"><h2 class="card-title">{{ __('How the file is read') }}</h2></div>
        <div class="space-y-4 p-4">
            <div class="grid gap-4 sm:grid-cols-3">
                @if (count($preview['sheets']) > 1)
                    <x-field :label="__('Sheet')" name="sheet" :hint="$preview['picked'] === 'priced' ? __('Picked because it has the most priced rows.') : null">
                        <select id="sheet" name="sheet" class="input">
                            @foreach ($preview['sheets'] as $sheet)
                                <option value="{{ $sheet['index'] }}" @selected($sheet['index'] === $preview['sheet'])>{{ $sheet['name'] }} — {{ __(':count priced rows', ['count' => $sheet['priced']]) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                @endif
                <x-field :label="__('Heading row')" name="row" :hint="$preview['found'] ? __('Found on row :row.', ['row' => $preview['heading_row']]) : __('No heading row was recognised. Choose it.')">
                    <select id="row" name="row" class="input">
                        @unless ($preview['found'])
                            <option value="">{{ __('Choose the heading row') }}</option>
                        @endunless
                        @foreach ($preview['candidates'] as $number => $text)
                            <option value="{{ $number }}" @selected($preview['found'] && $number === $preview['heading_row'])>{{ __('Row :row', ['row' => $number]) }}: {{ $text }}</option>
                        @endforeach
                    </select>
                    <label for="row_typed" class="mt-2 block text-xs text-ink-3">{{ __('Or type the row number') }}</label>
                    <input id="row_typed" name="row_typed" type="number" min="1" max="100000" inputmode="numeric" class="input mt-1 w-28">
                </x-field>
                <x-field :label="__('The heading takes')" name="span">
                    <select id="span" name="span" class="input">
                        @foreach ([1 => __('One row'), 2 => __('Two rows'), 3 => __('Three rows')] as $span => $label)
                            <option value="{{ $span }}" @selected($span === $preview['span'])>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            @if ($preview['columns'] !== [])
                <div class="overflow-x-auto">
                    <table class="ledger">
                        <thead>
                        <tr>
                            <th class="w-12">{{ __('Column') }}</th>
                            <th>{{ __('Heading in the file') }}</th>
                            <th>{{ __('Values under it') }}</th>
                            <th class="w-56">{{ __('Read as') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($preview['columns'] as $column)
                            <tr>
                                <td class="num">{{ $column['letter'] }}</td>
                                <td>
                                    {{ $column['heading'] !== '' ? $column['heading'] : '—' }}
                                    @if ($column['unit'])
                                        <span class="block text-xs text-ink-3">{{ __('Unit in the heading: :unit', ['unit' => $column['unit']]) }}</span>
                                    @endif
                                </td>
                                <td class="text-xs text-ink-3">{{ implode(' · ', $column['samples']) }}</td>
                                <td>
                                    <select name="fields[{{ $column['column'] }}]" class="input" aria-label="{{ __('Read column :column as', ['column' => $column['letter']]) }}">
                                        <option value="">{{ __('Leave out') }}</option>
                                        @foreach ($fields as $key => $label)
                                            <option value="{{ $key }}" @selected($column['field'] === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if ($column['field'] && $column['how'])
                                        <span class="mt-1 block text-xs text-ink-3">{{ $hows[$column['how']] ?? '' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Not "required": an empty box must not stop a column, the sheet or the heading row being changed. Confirming checks it. --}}
            <x-field :label="__('Unit for lines with none')" name="unit" class="max-w-sm" :hint="$preview['heading_unit'] ? __('The heading gives :unit for these lines.', ['unit' => $preview['heading_unit']]) : ($preview['suggested_unit'] && ! $preview['unit'] ? __('The last file in this layout used :unit. Type it again to use it.', ['unit' => $preview['suggested_unit']]) : __('Used for every line whose row has no unit, such as cum, sqm, nos or LS.'))">
                <input id="unit" name="unit" value="{{ $preview['unit'] }}" maxlength="16" list="boq-units" class="input" @if ($preview['needs_unit']) aria-invalid="true" @endif>
                <datalist id="boq-units">@foreach (config('buildos.units', []) as $unit)<option value="{{ $unit }}">@endforeach</datalist>
            </x-field>

            <div class="flex justify-end">
                <button class="btn btn-ghost">{{ __('Read again with these choices') }}</button>
            </div>
        </div>
    </form>

    @if ($preview['errors'] !== [])
        <section class="card mb-5 border-laterite" role="alert">
            <div class="card-head">
                <h2 class="card-title text-laterite-dark">{{ __('This file cannot be imported as it is') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['errors']) }} {{ __('problems') }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-2">{{ __('Rows are numbered as in the sheet. Change how the file is read above, or fix the file and upload it again.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-20">{{ __('Row') }}</th><th class="w-40">{{ __('Column') }}</th><th>{{ __('Problem') }}</th><th class="w-40"></th></tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['errors'], 0, $shownErrors) as $error)
                        <tr>
                            <td class="num">{{ $error['row'] > 0 ? $error['row'] : '—' }}</td>
                            <td>{{ $error['field'] ? ($errorFields[$error['field']] ?? $error['field']) : __('File') }}</td>
                            <td>{{ $error['message'] }}</td>
                            <td>
                                @if ($error['row'] > 0)
                                    <form method="POST" action="{{ route('boq.sheet.choose', $project) }}">
                                        @csrf
                                        <input type="hidden" name="token" value="{{ $token }}">
                                        <input type="hidden" name="skip" value="{{ $error['row'] }}">
                                        <button class="btn btn-ghost px-2 py-1 text-xs">{{ __('Leave this row out') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($preview['errors']) > $shownErrors)
                    <p class="mt-2 text-sm text-ink-3">{{ __('and :count more problems.', ['count' => count($preview['errors']) - $shownErrors]) }}</p>
                @endif
            </div>
        </section>
    @endif

    @if ($preview['found'])
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title">{{ __('What would be imported') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat :label="__('Lines to import')" :value="$preview['line_count']" />
                <x-stat :label="__('Rate-only lines')" :value="count($preview['rate_only'])" />
                <x-stat :label="__('Adjustments, not imported')" :value="count($preview['adjustments'])" />
                <x-stat :label="__('Rows left out')" :value="$preview['left_out']" />
                @if ($preview['plan']['update'] > 0)
                    <x-stat :label="__('Will update items already in the project')" :value="$preview['plan']['update']" :note="__('Will add :count new items.', ['count' => $preview['plan']['add']])" />
                @endif
                @if ($money)
                    <x-stat :label="__('The lines add up to')" :value="inr($preview['lines_total'])" />
                    @if ($preview['file_subtotal'] !== null)
                        <x-stat :label="__('Subtotal written in the sheet')" :value="inr($preview['file_subtotal'])" :note="$preview['subtotal_matches'] ? __('Matches the lines.') : __('Does not match the lines. Check the rows below.')" :accent="! $preview['subtotal_matches']" />
                    @endif
                    @if ($preview['adjustments'] !== [])
                        <x-stat :label="__('With the adjustments')" :value="inr($preview['with_adjustments'])" />
                    @endif
                    @if ($preview['file_total'] !== null)
                        <x-stat :label="__('Total written in the sheet')" :value="inr($preview['file_total'])" :note="$preview['total_matches'] ? __('Matches.') : __('Does not match. Check the totals below.')" :accent="! $preview['total_matches']" />
                    @endif
                @endif
            </div>
        </section>
    @endif

    @if ($preview['notes'] !== [])
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title">{{ __('Please check these') }}</h2></div>
            <ul class="space-y-1 p-4 text-sm text-ink-2">
                @foreach ($preview['notes'] as $note)
                    <li>@if ($note['row'] > 0)<span class="num text-ink-3">{{ __('Row') }} {{ $note['row'] }}:</span> @endif{{ $note['message'] }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($preview['lines'] !== [])
        <section class="card mb-5">
            <div class="card-head">
                <h2 class="card-title">{{ __('The first lines as they would come in') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['lines']) }} / {{ $preview['line_count'] }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('A line whose item code already exists in the project updates that item. Other lines are added.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead>
                    <tr>
                        <th class="w-14">{{ __('Row') }}</th>
                        <th>{{ __('Kind') }}</th>
                        <th>{{ __('Section') }}</th>
                        <th>{{ __('Item code') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th>{{ __('Unit') }}</th>
                        <th class="r">{{ __('Quantity') }}</th>
                        @if ($money)
                            <th class="r">{{ __('Rate') }}</th>
                            <th class="r">{{ __('Amount in the sheet') }}</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($preview['lines'] as $line)
                        <tr>
                            <td class="num">{{ $line['row'] }}</td>
                            <td>{{ $kinds[$line['kind']] ?? $line['kind'] }}</td>
                            <td class="text-ink-3">{{ $line['section'] ?? '' }}</td>
                            <td class="num">{{ $line['code'] }}</td>
                            <td>
                                {{ \Illuminate\Support\Str::limit((string) $line['description'], 220) }}
                                @if (! empty($line['details']))
                                    <span class="block text-xs text-ink-3">{{ \Illuminate\Support\Str::limit(implode(' · ', (array) $line['details']), 160) }}</span>
                                @endif
                            </td>
                            <td>{{ $line['unit'] }}</td>
                            <td class="num r">{{ fmt_qty($line['qty']) }}</td>
                            @if ($money)
                                <td class="num r">
                                    {{ inr($line['rate']) }}
                                    @if (($line['per'] ?? null) !== null)
                                        <span class="block text-xs text-ink-3">{{ __(':rate per :per in the sheet, so this is the rate per :unit.', ['rate' => inr($line['file_rate']), 'per' => $line['per'], 'unit' => $line['unit']]) }}</span>
                                    @endif
                                </td>
                                <td class="num r">{{ $line['amount'] === null ? '—' : inr($line['amount']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($preview['renumbered'] !== [])
        <section class="card mb-5 border-laterite">
            <div class="card-head">
                <h2 class="card-title">{{ __('Lines given a new item code') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['renumbered']) }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('These lines have no item code of their own in the file. The number they would get is already used in the project by a different item, so they are added under a new code and that item is not changed.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-14">{{ __('Row') }}</th><th>{{ __('Item code') }}</th><th>{{ __('New item code') }}</th><th>{{ __('Item already in the project') }}</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['renumbered'], 0, 200) as $line)
                        <tr>
                            <td class="num">{{ $line['row'] }}</td>
                            <td class="num">{{ $line['from'] }}</td>
                            <td class="num">{{ $line['to'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($line['existing'], 120) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($preview['updates'] !== [])
        <section class="card mb-5 border-laterite">
            <div class="card-head">
                <h2 class="card-title">{{ __('Lines that change an item already in the project') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['updates']) }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('These lines have an item code, or a serial number with the same unit and much the same words, that an item in the project already has, so importing changes that item in place. Check that each is the same item.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-14">{{ __('Row') }}</th><th>{{ __('Item code') }}</th><th>{{ __('In the project now') }}</th><th>{{ __('After the import') }}</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['updates'], 0, 200) as $line)
                        <tr>
                            <td class="num">{{ $line['row'] }}</td>
                            <td class="num">{{ $line['code'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($line['now'], 120) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($line['after'], 120) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($preview['adjustments'] !== [])
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title">{{ __('Adjustments in the sheet') }}</h2></div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('Percentages or amounts added to or taken off the work, such as contingencies, a rebate on the schedule rates, tax or a round-off. They are not BOQ lines, so they are not imported. Add a lump-sum line by hand if the contract needs one.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead>
                    <tr>
                        <th class="w-14">{{ __('Row') }}</th>
                        <th>{{ __('In the sheet') }}</th>
                        <th class="r">%</th>
                        @if ($money)
                            <th class="r">{{ __('Comes to') }}</th>
                            <th class="r">{{ __('Figure in the sheet') }}</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($preview['adjustments'] as $adjustment)
                        <tr>
                            <td class="num">{{ $adjustment['row'] }}</td>
                            <td>{{ $adjustment['label'] }}</td>
                            <td class="num r">{{ $adjustment['percent'] ?? '—' }}</td>
                            @if ($money)
                                <td class="num r">{{ inr($adjustment['effect']) }}</td>
                                <td class="num r">
                                    {{ $adjustment['amount'] === null ? '—' : inr($adjustment['amount']) }}
                                    @if ($adjustment['shows'] === 'total')
                                        <span class="block text-xs text-ink-3">{{ __('The total after this adjustment') }}</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($preview['rate_only'] !== [])
        <section class="card mb-5">
            <div class="card-head">
                <h2 class="card-title">{{ __('Rate-only lines') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['rate_only']) }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('The sheet gives no quantity for these lines (QRO, rate only, a blank quantity, or an item to be priced with neither quantity nor rate). They are imported with quantity 0; no quantity or rate is made up. Measurements against them go for approval as beyond the BOQ.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-14">{{ __('Row') }}</th><th>{{ __('Item code') }}</th><th>{{ __('Description') }}</th><th>{{ __('Unit') }}</th>@if ($money)<th class="r">{{ __('Rate') }}</th>@endif</tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['rate_only'], 0, 100) as $line)
                        <tr>
                            <td class="num">{{ $line['row'] }}</td>
                            <td class="num">{{ $line['code'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit((string) $line['description'], 160) }}</td>
                            <td>{{ $line['unit'] }}</td>
                            @if ($money)<td class="num r">{{ inr($line['rate']) }}</td>@endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($money && $preview['mismatches'] !== [])
        <section class="card mb-5 border-laterite">
            <div class="card-head"><h2 class="card-title">{{ __('Amounts that are not quantity times rate') }}</h2></div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('The quantity and the rate are imported as the sheet gives them. Nothing here was corrected.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-14">{{ __('Row') }}</th><th>{{ __('Description') }}</th><th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Quantity × rate') }}</th><th class="r">{{ __('Amount in the sheet') }}</th></tr></thead>
                    <tbody>
                    @foreach ($preview['mismatches'] as $mismatch)
                        <tr>
                            <td class="num">{{ $mismatch['row'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit((string) $mismatch['description'], 120) }}</td>
                            <td class="num r">{{ fmt_qty($mismatch['qty']) }}</td>
                            <td class="num r">{{ inr($mismatch['rate']) }}</td>
                            <td class="num r">{{ inr($mismatch['computed']) }}</td>
                            <td class="num r">{{ inr($mismatch['amount']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($money && $preview['totals'] !== [])
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title">{{ __('Totals in the sheet') }}</h2></div>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-14">{{ __('Row') }}</th><th>{{ __('In the sheet') }}</th><th class="r">{{ __('Figure in the sheet') }}</th><th class="r">{{ __('The lines above come to') }}</th><th>{{ __('Check') }}</th></tr></thead>
                    <tbody>
                    @foreach ($preview['totals'] as $total)
                        <tr>
                            <td class="num">{{ $total['row'] }}</td>
                            <td>{{ $total['label'] }}</td>
                            <td class="num r">{{ $total['amount'] === null ? '—' : inr($total['amount']) }}</td>
                            <td class="num r">{{ inr($total['adjusted'] ? $total['with_adjustments'] : $total['lines']) }}</td>
                            <td>{{ $total['matches'] === null ? '—' : ($total['matches'] ? __('Matches') : __('Does not match')) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($preview['others'] !== [])
        <section class="card mb-5">
            <div class="card-head">
                <h2 class="card-title">{{ __('Rows that are not lines') }}</h2>
                <span class="num text-sm text-ink-3">{{ $preview['other_count'] }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('Sections, specifications, totals and other rows. Sections and specifications are carried into the lines under them; the rest are not imported.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-14">{{ __('Row') }}</th><th class="w-36">{{ __('Read as') }}</th><th>{{ __('In the sheet') }}</th><th>{{ __('Why') }}</th></tr></thead>
                    <tbody>
                    @foreach ($preview['others'] as $other)
                        <tr>
                            <td class="num">{{ $other['row'] }}</td>
                            <td>{{ $kinds[$other['kind']] ?? $other['kind'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($other['text'], 140) }}</td>
                            <td class="text-ink-3">
                                {{ $other['reason'] }}
                                @if ($other['kind'] === 'excluded')
                                    <form method="POST" action="{{ route('boq.sheet.choose', $project) }}">
                                        @csrf
                                        <input type="hidden" name="token" value="{{ $token }}">
                                        <input type="hidden" name="keep" value="{{ $other['row'] }}">
                                        <button class="btn btn-ghost px-2 py-1 text-xs">{{ __('Put it back') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if ($preview['other_count'] > count($preview['others']))
                    <p class="mt-2 text-sm text-ink-3">{{ __('and :count more rows.', ['count' => $preview['other_count'] - count($preview['others'])]) }}</p>
                @endif
            </div>
        </section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="POST" action="{{ route('boq.sheet.discard', $project) }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button class="btn btn-ghost">{{ __('Discard this file') }}</button>
        </form>
        @if ($canConfirm)
            <form id="boq-sheet-confirm" method="POST" action="{{ route('boq.sheet.confirm', $project) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="version" value="{{ $preview['version'] }}">
                @error('version')
                    <p class="error mb-2">{{ $message }}</p>
                @enderror
                <button class="btn btn-primary">{{ __('Import these :count lines', ['count' => $preview['rows']]) }}</button>
            </form>
        @else
            <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-primary">{{ __('Upload another file') }}</a>
        @endif
    </div>
@endsection
