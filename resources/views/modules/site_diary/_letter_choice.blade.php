{{--
    What a letter cites: entries of the register, or unpaid client bills. $kind is the letter's type when
    it is fixed (a draft being changed). When it is null, the "type" chosen on the form decides which list
    shows, and a box that does not belong to that type is switched off so it is not sent.
    Nothing is ticked for the user. Amounts are listed only when $withBills is true, which the controller
    sets for someone who may see money.
--}}
@php
    $payment = \App\Models\SiteLetter::PAYMENT_FOLLOWUP;
    $showsEntries = $kind === null || $kind !== $payment;
    $showsBills = $withBills && ($kind === null || $kind === $payment);
    $periodOf = fn ($entry) => $entry->isOngoing()
        ? __('From :from, still going on', ['from' => $entry->started_on->format('d M Y')])
        : __(':from to :to', ['from' => $entry->started_on->format('d M Y'), 'to' => $entry->ended_on->format('d M Y')]);
@endphp

@if ($showsEntries)
    <fieldset class="border-t border-rule" @if ($kind === null) x-show="type === 'eot_request' || type === 'delay_notice'" x-cloak @endif>
        <legend class="label px-4 pt-3">{{ __('Register entries this letter cites') }}</legend>
        <p class="hint px-4">{{ __('Tick one or several. Only confirmed entries that count are listed, and a notice of delay cites entries that are still going on.') }}</p>
        @foreach (['hindrance_ids', 'hindrance_ids.*'] as $field)
            @error($field)
                <p class="error px-4">{{ $message }}</p>
            @enderror
        @endforeach

        @php
            // On the new-letter form the list holds every entry that counts; a notice of delay shows only those still going on.
            $noneOngoing = $kind === null && ! $entries->contains(fn ($entry) => $entry->isOngoing());
        @endphp
        @if ($entries->isEmpty())
            <p class="px-4 py-3 text-sm text-ink-3">{{ __('The register has no confirmed entry that this letter can cite.') }}</p>
        @else
            @if ($noneOngoing)
                <p class="px-4 py-3 text-sm text-ink-3" x-show="type === 'delay_notice'" x-cloak>{{ __('No confirmed entry of the register is still going on, so a notice of delay has nothing to cite. A request for extension of time can cite entries that have ended.') }}</p>
            @endif
            <div class="mt-2 max-h-[70vh] overflow-y-auto border-t border-rule" @if ($noneOngoing) x-show="type !== 'delay_notice'" @endif>
                @foreach ($entries as $entry)
                    <label class="flex min-h-11 cursor-pointer gap-3 border-b border-rule px-4 py-2 text-sm last:border-b-0" @if ($kind === null && ! $entry->isOngoing()) x-show="type !== 'delay_notice'" @endif>
                        <input type="checkbox" name="hindrance_ids[]" value="{{ $entry->id }}" class="mt-0.5 size-5 shrink-0" @checked(in_array($entry->id, $tickedEntries, true))
                            @if ($kind === null) :disabled="{{ $entry->isOngoing() ? "type !== 'eot_request' && type !== 'delay_notice'" : "type !== 'eot_request'" }}" @endif>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="num font-semibold">{{ $periodOf($entry) }}</span>
                                <span class="text-ink-3">{{ __($categories[$entry->category] ?? $entry->category) }}</span>
                                @if ($entry->isLocked())
                                    <span class="text-xs text-ink-3">{{ __('Cited in a letter') }}</span>
                                @endif
                            </span>
                            <span class="mt-1 block whitespace-pre-line text-ink-2">{{ $entry->description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif
    </fieldset>
@endif

@if ($showsBills)
    <fieldset class="border-t border-rule" @if ($kind === null) x-show="type === 'payment_followup'" x-cloak @endif>
        <legend class="label px-4 pt-3">{{ __('Client bills this letter follows up') }}</legend>
        <p class="hint px-4">{{ __('Approved client bills of this project with an amount outstanding, going by the receipts recorded in the app. A payment that is not recorded here is not known to the app.') }}</p>
        @foreach (['bill_ids', 'bill_ids.*'] as $field)
            @error($field)
                <p class="error px-4">{{ $message }}</p>
            @enderror
        @endforeach

        @if ($bills->isEmpty())
            <p class="px-4 py-3 text-sm text-ink-3">{{ __('No approved client bill of this project has an amount outstanding.') }}</p>
        @else
            <div class="mt-2 max-h-[70vh] overflow-y-auto border-t border-rule">
                @foreach ($bills as $billId => $row)
                    <label class="flex min-h-11 cursor-pointer gap-3 border-b border-rule px-4 py-2 text-sm last:border-b-0">
                        <input type="checkbox" name="bill_ids[]" value="{{ $billId }}" class="mt-0.5 size-5 shrink-0" @checked(in_array($billId, $tickedBills, true))
                            @if ($kind === null) :disabled="type !== 'payment_followup'" @endif>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="num font-semibold">{{ $row['bill']->number }}</span>
                                <span class="num text-ink-3">{{ \App\Services\SiteDiary\LetterFacts::billDate($row['bill'])->format('d M Y') }}</span>
                            </span>
                            <span class="num mt-1 block text-ink-2">{{ __('Outstanding: :amount', ['amount' => inr($row['outstanding'])]) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endif
    </fieldset>
@endif
