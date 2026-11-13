{{--
    The pages of a read that failed, each with what can be done about it: read it again, or read it
    as two halves when its answer was cut off. Both send the page to the reader again, so they are
    offered only to a person who may use the AI agents. Used inside an Alpine component that has
    failedPages, canSend, busy, retry(page) and halves(page).
--}}
<ul class="space-y-2 text-sm" x-show="failedPages.length" x-cloak>
    <template x-for="page in failedPages" :key="page.id">
        <li class="flex flex-wrap items-center justify-between gap-2 rounded-[3px] border border-rule px-3 py-2">
            <span>
                <span class="num font-medium" x-text="say(@js(__('Page :n')), { n: page.page_no })"></span>
                <span class="text-ink-3" x-show="page.part !== 'full'" x-text="page.part === 'top' ? @js(__('top half')) : @js(__('bottom half'))"></span>:
                <span class="text-ink-2" x-text="page.reason === 'cut_off' ? @js(__('The page holds more than one answer can carry. Read it as two halves.')) : @js(__('This page could not be read.'))"></span>
            </span>
            <span class="flex flex-wrap gap-2" x-show="canSend">
                <button type="button" class="btn btn-ghost" x-show="page.can_retry" :disabled="busy" @click="retry(page)">{{ __('Read this page again') }}</button>
                <button type="button" class="btn btn-ghost" x-show="page.can_split" :disabled="busy" @click="halves(page)">{{ __('Read as two halves') }}</button>
            </span>
        </li>
    </template>
</ul>
