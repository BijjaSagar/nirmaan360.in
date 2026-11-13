{{--
    One fact of the tender sheet with its page. Needs $fact (a row of TenderSheet::build) and $beside:
    whether the page is drawn to the right of the fact on a wide screen (a contradiction shows its
    facts side by side instead, each with its page below it).

    Everything the reader returned is shown as text. A link read from a document is never made a link.
    Confirm is sent with viewed = 1 only once the page has been drawn here: a PDF page drawn by pdf.js,
    or a picture document shown as it is.

    A confirmed fact lists the uses it can be put to (into the tender's field, a follow-up, a planned
    payment), each with what it will do or why it cannot be done yet.
--}}
@php
    $statusTone = [
        'proposed' => ['border-ink-3 text-ink-3', __('Not checked yet')],
        'confirmed' => ['border-moss text-moss', __('Confirmed against its page')],
        'rejected' => ['border-laterite text-laterite', __('Rejected')],
        'superseded' => ['border-ink-3 text-ink-3', __('Replaced')],
    ][$fact['status']] ?? ['border-ink-3 text-ink-3', $fact['status']];
@endphp
<article id="fact-{{ $fact['id'] }}" class="rounded-[3px] border border-rule bg-sheet p-3" data-pdf="{{ $fact['pdf_url'] }}" data-picture="{{ $fact['picture_url'] }}" data-page="{{ (int) $fact['page'] }}"
         x-data="{
             open: false, drawing: false, src: null, problem: null,
             async show() {
                 this.open = true;
                 if (this.src || this.drawing) return;
                 this.drawing = true;
                 this.problem = null;
                 try {
                     const picture = this.$root.dataset.picture;
                     if (picture) {
                         const shown = new Image();
                         shown.src = picture;
                         await shown.decode();
                         this.src = picture;
                     } else {
                         this.src = await window.TenderPages.draw(this.$root.dataset.pdf, Number(this.$root.dataset.page));
                     }
                 }
                 catch (error) { this.problem = @js(__('This page could not be drawn in this browser. Open the document and turn to the page yourself.')); }
                 finally { this.drawing = false; }
             },
         }">
    <div class="grid gap-3 {{ $beside ? 'xl:grid-cols-2' : '' }}">
        <div class="min-w-0 space-y-1.5 text-sm">
            <div class="flex flex-wrap items-center gap-2 text-xs text-ink-3">
                <span class="stamp {{ $statusTone[0] }}">{{ $statusTone[1] }}</span>
                <span>{{ __('Page :page', ['page' => $fact['page']]) }}</span>
                @if ($fact['document'])<span class="break-words">· {{ $fact['document'] }}</span>@endif
            </div>
            @if ($fact['printed_label'])
                <p class="text-xs text-ink-2 break-words">{{ $fact['printed_label'] }}</p>
            @endif
            <p class="break-words font-semibold">{{ $fact['value'] }}</p>
            @if ($fact['shown'])
                <p class="num text-xs text-ink-2">{{ __('Read by the app as :value', ['value' => $fact['shown']]) }}</p>
            @endif

            <p class="text-xs {{ $fact['source_ok'] ? 'text-moss' : 'font-medium text-[#8a6208]' }}">{{ $fact['source'] }}</p>
            @if (filled($fact['line']))
                <blockquote class="border-l-2 border-rule pl-2 text-xs whitespace-pre-line break-words text-ink-2">{{ $fact['line'] }}</blockquote>
            @endif
            @unless ($fact['value_in_line'])
                <p class="text-xs font-medium text-[#8a6208]">{{ __('The value is not in the quoted line. Check it against the page.') }}</p>
            @endunless
            @if ($fact['edited'])
                <p class="text-xs text-ink-3">{{ __('The value was corrected by hand.') }}</p>
            @endif
            @if ($fact['status'] === 'confirmed' && $fact['confirmed_by'])
                <p class="text-xs text-moss">{{ __('Confirmed by :name on :date', ['name' => $fact['confirmed_by'], 'date' => $fact['confirmed_at']?->format('d M Y')]) }}</p>
            @endif
            @foreach ($fact['replaces'] as $old)
                <p class="text-xs font-medium text-[#8a6208]">{{ $old['document']
                    ? __('Confirming this replaces the confirmed value from page :page of :document.', ['page' => $old['page'], 'document' => $old['document']])
                    : __('Confirming this replaces the confirmed value from page :page.', ['page' => $old['page']]) }}</p>
            @endforeach
            @if ($fact['status'] === 'superseded' && $fact['applied'])
                <p class="text-xs font-medium text-[#8a6208]">{{ __('This value was put on the tender. Put the new value on the tender to change it there.') }}</p>
            @endif

            <div class="flex flex-wrap items-center gap-2 pt-1">
                @if ($fact['pdf_url'] || $fact['picture_url'])
                    <button type="button" class="btn btn-ghost min-h-11 py-1 text-xs" @click="show()">{{ __('Show page :page', ['page' => $fact['page']]) }}</button>
                @else
                    <span class="text-xs text-ink-3">{{ __('The document has been removed from the tender, so its page cannot be drawn.') }}</span>
                @endif

                @if ($fact['can_confirm'])
                    <form method="POST" action="{{ route('tender-facts.confirm', $fact['id']) }}">
                        @csrf
                        <input type="hidden" name="viewed" value="0" :value="src ? 1 : 0">
                        <button class="btn btn-primary min-h-11 py-1 text-xs" :disabled="!src" :title="src ? '' : @js(__('Show the page first'))">{{ __('Confirm against the page') }}</button>
                    </form>
                @endif
                @if ($fact['can_reject'])
                    <form method="POST" action="{{ route('tender-facts.reject', $fact['id']) }}">
                        @csrf
                        <button class="btn btn-ghost min-h-11 py-1 text-xs text-laterite">{{ __('Reject') }}</button>
                    </form>
                @endif
            </div>

            @if ($fact['uses'] !== [])
                <div class="space-y-2 border-t border-rule pt-2">
                    @foreach ($fact['uses'] as $use)
                        <div class="space-y-1">
                            <p class="text-xs text-ink-2">{{ $use['preview'] }}</p>
                            @if ($use['done'])
                                <p class="text-xs font-medium text-moss">{{ match ($use['target']) { 'field' => __('Put on the tender.'), 'task' => __('Follow-up added.'), default => __('Planned payment added.') } }}</p>
                            @elseif ($use['blocked'])
                                <p class="text-xs font-medium text-[#8a6208]">{{ $use['blocked'] }}</p>
                            @else
                                <form method="POST" action="{{ route('tender-facts.apply', $fact['id']) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="target" value="{{ $use['target'] }}">
                                    <input type="hidden" name="client_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    @if ($use['target'] === 'payment')
                                        <select name="mode" required class="input min-h-11 w-auto" aria-label="{{ __('Form of payment') }}">
                                            <option value="">{{ __('Form of payment') }}</option>
                                            @foreach (\App\Services\Crm\Vocab::paymentModes() as $mode => $modeLabel)
                                                <option value="{{ $mode }}">{{ $modeLabel }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <button class="btn btn-ghost min-h-11 py-1 text-xs">{{ match ($use['target']) { 'field' => __('Put on the tender'), 'task' => __('Add a follow-up'), default => __('Add as a planned payment') } }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($fact['can_edit'])
                <details class="pt-1">
                    <summary class="link cursor-pointer text-xs">{{ __('Correct the value') }}</summary>
                    <form method="POST" action="{{ route('tender-facts.update', $fact['id']) }}" class="mt-2 flex flex-wrap items-center gap-2">
                        @csrf @method('PATCH')
                        <input name="value" value="{{ $fact['value'] }}" class="input min-h-11 min-w-0 flex-1" maxlength="500" required aria-label="{{ __('Value as printed') }}">
                        <button class="btn btn-ghost min-h-11">{{ __('Save') }}</button>
                    </form>
                </details>
            @endif
        </div>

        <div x-show="open" x-cloak class="min-w-0">
            <p x-show="drawing" class="text-xs text-ink-3">{{ __('Drawing page :page…', ['page' => $fact['page']]) }}</p>
            <p x-show="problem" x-text="problem" class="text-xs font-medium text-laterite"></p>
            <template x-if="src">
                <img :src="src" alt="{{ __('Page :page of the document', ['page' => $fact['page']]) }}" class="w-full rounded-[3px] border border-rule bg-white">
            </template>
        </div>
    </div>
</article>
