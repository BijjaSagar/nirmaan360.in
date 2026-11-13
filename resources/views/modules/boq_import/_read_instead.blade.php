{{-- Under the problems of a file in the template's headings: offer to read it by its own layout instead. --}}
<div class="border-t border-line px-4 py-3">
    <p class="text-sm text-ink-2">{{ __('If this file is a BOQ in your own layout, with section rows, part rows or totals, it can be read by its own headings instead. You check every column and line before anything is saved.') }}</p>
    <a href="{{ route('boq.sheet.preview', $project) }}" class="btn btn-ghost mt-2">{{ __('Read it by its own headings') }}</a>
</div>
