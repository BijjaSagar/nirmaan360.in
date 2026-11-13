{{--
    The questions before a tender read is priced. Needs $read, $languages, $source and $picturePages.
    Nothing is chosen in advance: the person answers every time.

    A text read asks whether the text matches the page, unless no page has text to compare, which is
    the same as "No". A read of pictures only has no text to compare: it asks the language, and for a
    PDF the range of pages, which it must have.
--}}
@php
    $textRead = $source === 'text';
    $noText = $textRead && ($read->options['samples'] ?? []) === [];
    $rangeNeeded = $source === 'pictures';
    $range = (array) ($read->options['range'] ?? []);
@endphp
<form method="POST" action="{{ route('tender-reads.mode', $read) }}" class="space-y-4 border-t border-rule p-4 text-sm">
    @csrf
    <fieldset>
        <legend class="font-medium">{{ __('The document is written in') }}</legend>
        <div class="mt-2 flex flex-wrap gap-4">
            @foreach ($languages as $code => $name)
                <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" name="language" value="{{ $code }}" required> {{ $name }}</label>
            @endforeach
        </div>
    </fieldset>
    @if ($noText)
        <input type="hidden" name="matches" value="no">
    @elseif ($textRead)
        <fieldset>
            <legend class="font-medium">{{ __('Does the text on the left match the page on the right?') }}</legend>
            <div class="mt-2 flex flex-wrap gap-4">
                <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" name="matches" value="yes" required> {{ __('Yes, the words and figures are the same') }}</label>
                <label class="inline-flex min-h-11 items-center gap-2"><input type="radio" name="matches" value="no" required> {{ __('No, they differ or the text is garbled') }}</label>
            </div>
        </fieldset>
    @endif
    @if ($source !== 'image')
        <div class="grid max-w-md grid-cols-2 gap-3">
            <x-field :label="__('From page')" name="page_from"><input id="page_from" name="page_from" type="number" min="1" @if ((int) $read->pages_total > 0) max="{{ (int) $read->pages_total }}" @endif value="{{ $rangeNeeded ? ($range['page_from'] ?? '') : '' }}" inputmode="numeric" class="input num" @required($rangeNeeded)></x-field>
            <x-field :label="__('To page')" name="page_to"><input id="page_to" name="page_to" type="number" min="1" @if ((int) $read->pages_total > 0) max="{{ (int) $read->pages_total }}" @endif value="{{ $rangeNeeded ? ($range['page_to'] ?? '') : '' }}" inputmode="numeric" class="input num" @required($rangeNeeded)></x-field>
        </div>
        <p class="hint">{{ $rangeNeeded
            ? __('Choose up to :pages pages. A longer document is read as several ranges, each with its own price.', ['pages' => $picturePages])
            : __('Leave the pages empty to read the whole document.') }}</p>
    @endif
    <button class="btn btn-primary">{{ __('Continue') }}</button>
</form>
