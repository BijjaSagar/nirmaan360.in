{{--
    While an AI request is open, the page loads itself again every few seconds so its result shows. It waits while
    the person is in the middle of something in the page itself, so nothing typed is lost: a field has the focus,
    something was typed or chosen since the page opened, a folded part of the page is open, or $hold (a script
    condition of the page, false when not given) is true. Only the page's own content counts, never the menu beside it.
--}}
<div x-data data-look-again x-init="const page = $el.closest('main'); let changed = false; ['input', 'change'].forEach((type) => page?.addEventListener(type, () => changed = true)); const look = () => changed || ({{ $hold ?? 'false' }}) || page?.querySelector('details[open]') || document.activeElement?.matches('input, textarea, select') ? setTimeout(look, 5000) : location.reload(); setTimeout(look, 5000)"></div>
