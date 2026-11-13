{{--
    The form that makes an entry from daily reports, changes one ($editing), or corrects one that a
    letter cites ($correcting). It starts empty: no category, date or name is filled in for the user.
    A blank start or end takes the date of the first or last ticked report, and the hint says so.

    When the form comes back with what the person sent (a refused save, or "Show" for other dates), every
    box shows what they sent, an unticked one included. Only a form opened afresh shows the saved entry.
--}}
@php
    $with = $editing ?? $correcting;
    // The form sends still_going_on every time, ticked or not, so finding it says the person's own input came back.
    $returned = session()->hasOldInput('still_going_on');
    $value = fn (string $field) => old($field, $with?->{$field});
    $date = fn (string $field) => old($field, $with?->{$field}?->format('Y-m-d'));
    $ticked = array_map('intval', $returned ? (array) old('dpr_ids', []) : ($with ? $with->sources->pluck('dpr_id')->all() : []));
    $ongoing = $returned ? (bool) old('still_going_on') : ($with?->isOngoing() ?? false);
    $listed = $candidates['reports']->pluck('id')->all();
    // Reports the entry cites that can no longer be chosen: removed, or with nothing under hindrances any more.
    $dropped = $with ? $with->sources->reject(fn ($source) => in_array((int) $source->dpr_id, $listed, true)) : collect();
    $back = route('site-diary.hindrances.index', $project);
@endphp

{{-- "Show" sends the entry form along, so what was ticked and typed comes back with the reports of those dates. Without scripts it only changes the list. --}}
<form method="GET" action="{{ $back }}" class="mb-2 flex flex-wrap items-end gap-2 text-sm" x-data="{
    keep(event) {
        const entry = document.getElementById('entry-form');
        if (! entry) return;
        event.preventDefault();
        for (const name of ['from', 'to']) entry.elements.namedItem(name).value = event.target.elements.namedItem(name).value;
        for (const name of ['from', 'to', 'show_reports']) entry.elements.namedItem(name).disabled = false;
        entry.submit();
    },
}" x-on:submit="keep($event)">
    @if ($editing)<input type="hidden" name="edit" value="{{ $editing->id }}">@endif
    @if ($correcting)<input type="hidden" name="correct" value="{{ $correcting->id }}">@endif
    <x-field :label="__('Show reports from')">
        <input type="date" name="from" value="{{ $shown['from'] }}" class="input min-h-11" aria-label="{{ __('Show reports from') }}">
    </x-field>
    <x-field :label="__('Up to')">
        <input type="date" name="to" value="{{ $shown['to'] }}" class="input min-h-11" aria-label="{{ __('Up to') }}">
    </x-field>
    <button class="btn btn-ghost min-h-11">{{ __('Show') }}</button>
</form>

<form id="entry-form" method="POST" action="{{ $editing ? route('site-diary.hindrances.update', $editing) : route('site-diary.hindrances.store', $project) }}" class="card mb-6" x-data="{ ongoing: {{ $ongoing ? 'true' : 'false' }} }">
    @csrf
    @if ($editing)
        @method('PATCH')
    @else
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
    @endif
    @if ($correcting)
        <input type="hidden" name="supersedes_id" value="{{ $correcting->id }}">
    @endif
    {{-- Switched on only by "Show" above: then nothing is saved and the form comes back as it is. --}}
    <input type="hidden" name="show_reports" value="1" disabled>
    <input type="hidden" name="from" value="" disabled>
    <input type="hidden" name="to" value="" disabled>

    <div class="card-head flex-wrap gap-2">
        <h2 class="card-title">
            @if ($editing) {{ __('Change the entry') }} @elseif ($correcting) {{ __('Correct an entry that a letter cites') }} @else {{ __('Make an entry') }} @endif
        </h2>
        @if ($with)
            <a href="{{ $back }}" class="link text-sm">{{ __('Cancel') }}</a>
        @endif
    </div>

    @if ($correcting)
        <p class="border-b border-rule px-4 py-3 text-sm text-ink-2">{{ __('The entry that the letter cites stays exactly as it is. The register counts this new entry in its place.') }}</p>
    @endif

    <fieldset class="border-b border-rule">
        <legend class="label px-4 pt-3">{{ __('Daily reports that mention this hindrance') }}</legend>
        <p class="hint px-4">{{ __('Tick one or several. What each report says under hindrances is copied into the entry as it is now.') }}</p>
        @foreach (['dpr_ids', 'dpr_ids.*', 'supersedes_id', 'client_uuid'] as $field)
            @error($field)
                <p class="error px-4">{{ $message }}</p>
            @enderror
        @endforeach

        @if ($candidates['reports']->isEmpty())
            <x-empty :title="__('No daily report says anything under hindrances')" :note="__('Entries are made from what is written under hindrances in the daily reports. Write it there first.')" />
        @else
            <div class="mt-2 max-h-[70vh] overflow-y-auto border-t border-rule">
                @foreach ($candidates['reports'] as $report)
                    <label class="flex min-h-11 cursor-pointer gap-3 border-b border-rule px-4 py-2 text-sm last:border-b-0">
                        <input type="checkbox" name="dpr_ids[]" value="{{ $report->id }}" class="mt-0.5 size-5 shrink-0" @checked(in_array($report->id, $ticked, true))>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="num font-semibold">{{ $report->report_date->format('d M Y') }}</span>
                                @if ($report->site)<span class="text-ink-3">{{ $report->site->name }}</span>@endif
                                <x-stamp :status="$report->status" />
                                @if (array_diff($candidates['used'][$report->id] ?? [], $with ? [$with->id] : []) !== [])
                                    <span class="text-xs text-ink-3">{{ __('Already in the register') }}</span>
                                @endif
                            </span>
                            <span class="mt-1 block whitespace-pre-line text-ink-2">{{ $report->hindrances }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        @if ($candidates['more'])
            <p class="hint px-4 py-2">{{ __('Only the newest reports are listed. Use the two dates above to see older ones.') }}</p>
        @endif
        @if ($dropped->isNotEmpty())
            <p class="px-4 py-2 text-sm text-[#8a6208]">{{ __('Reports this entry cites that can no longer be chosen, because they were removed or say nothing under hindrances now: :dates. They are left out when you save.', ['dates' => $dropped->map(fn ($source) => $source->report_date->format('d M Y'))->implode(', ')]) }}</p>
        @endif
    </fieldset>

    <div class="grid gap-3 p-4 sm:grid-cols-2">
        <x-field :label="__('What held the work up')" name="category">
            <select id="category" name="category" class="input min-h-11" required>
                <option value="">{{ __('Choose') }}</option>
                @foreach ($categories as $key => $label)
                    <option value="{{ $key }}" @selected((string) $value('category') === $key)>{{ __($label) }}</option>
                @endforeach
            </select>
        </x-field>

        <x-field :label="__('Site')" name="site_id">
            <select id="site_id" name="site_id" class="input min-h-11">
                <option value="">{{ __('Whole project') }}</option>
                @foreach ($sites as $site)
                    <option value="{{ $site->id }}" @selected((string) $value('site_id') === (string) $site->id)>{{ $site->name }}</option>
                @endforeach
            </select>
        </x-field>

        <x-field :label="__('Description')" name="description" class="sm:col-span-2">
            <textarea id="description" name="description" rows="3" maxlength="2000" class="input" required>{{ $value('description') }}</textarea>
        </x-field>

        <x-field :label="__('Started on')" name="started_on" :hint="__('Leave blank to use the date of the first ticked report.')">
            <input id="started_on" name="started_on" type="date" max="{{ $today->format('Y-m-d') }}" value="{{ $date('started_on') }}" class="input min-h-11">
        </x-field>

        <x-field :label="__('Ended on')" name="ended_on" :hint="__('Leave blank to use the date of the last ticked report.')">
            <input id="ended_on" name="ended_on" type="date" max="{{ $today->format('Y-m-d') }}" value="{{ $date('ended_on') }}" class="input min-h-11" :disabled="ongoing">
        </x-field>

        <label class="flex min-h-11 items-center gap-2 text-sm font-medium sm:col-span-2">
            <input type="hidden" name="still_going_on" value="0">
            <input type="checkbox" name="still_going_on" value="1" class="size-5" x-model="ongoing" @checked($ongoing)>
            {{ __('Still going on. It has no end date yet and is counted up to today.') }}
        </label>

        <x-field :label="__('Who is responsible, as you see it')" name="responsible" :hint="__('Typed by you, for your own record. The app does not judge who is responsible.')" class="sm:col-span-2">
            <input id="responsible" name="responsible" value="{{ $value('responsible') }}" maxlength="160" class="input min-h-11">
        </x-field>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-rule px-4 py-3">
        <p class="min-w-0 flex-1 text-xs text-ink-3">
            @if ($editing && ! $editing->isConfirmed())
                {{ __('Saving does not confirm this entry. Confirm it afterwards if it stands.') }}
            @else
                {{ __('Saving the entry confirms it in your name.') }}
            @endif
        </p>
        <button class="btn btn-primary min-h-11">{{ $editing ? __('Save changes') : __('Save entry') }}</button>
    </div>
</form>
