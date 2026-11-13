{{--
    The step after the questions: what will be read, the page pictures to draw and upload, the price
    and the start. Needs what the reading screen has: $read, $source, $languages, $textPages,
    $pictures (every page that can only be read as a picture), $toRead (those within the range),
    $stored (pages whose picture is uploaded), $range, $fileUrl, $canAct and $picturePages.

    Pictures are drawn in this browser from the tender's own document and uploaded one page at a time
    through the shared upload route; the server redraws each before it keeps it. A page left without a
    picture is not read, and the tender sheet lists it as not read.

    The price comes from a shared route, which only those who may start the read can call; someone who
    only watches is not shown one.
--}}
@php
    $hasText = $textPages !== [];
@endphp
<section class="card"
         x-data="{
             quote: null, starting: false, uploading: false, leftOut: [],
             stored: @js(array_values($stored)),
             toRead: @js(array_values($toRead)),
             pictures: @js(array_values($pictures)),
             hasText: @js($hasText),
             range: @js((object) $range),
             get missing() { return this.toRead.filter((page) => !this.stored.includes(page) && !this.leftOut.includes(page)); },
             hasWork() { return this.hasText || this.toRead.some((page) => this.stored.includes(page)); },
             ready() { return new Promise((resolve) => window.ReaderCapture ? resolve() : document.addEventListener('reader-capture:ready', resolve, { once: true })); },
             async init() { if (!@js($canAct)) return; await this.ready(); await this.price(); },
             async price() {
                 this.quote = null;
                 if (!this.hasWork()) return;
                 try { this.quote = await window.ReaderCapture.quote({{ $read->id }}, this.range); }
                 catch (error) { this.problem = error.message; }
             },
             async upload() {
                 this.uploading = true;
                 this.problem = null;
                 try {
                     await window.TenderPages.upload({{ $read->id }}, @js($fileUrl), @js($source), this.missing, (page) => {
                         if (page.uploaded) { this.stored.push(page.pageNo); } else { this.leftOut.push(page.pageNo); }
                     });
                 } catch (error) { this.problem = error.message; }
                 finally { this.uploading = false; await this.price(); }
             },
             async start() {
                 this.starting = true;
                 try {
                     const skip = this.pictures.filter((page) => !this.stored.includes(page));
                     await window.ReaderCapture.start({{ $read->id }}, { ...this.range, cap: this.quote.cap, skip_pages: skip });
                     window.location.reload();
                 } catch (error) { this.problem = error.message; this.starting = false; }
             },
         }">
    <div class="card-head"><h2 class="card-title">{{ __('What will be read') }}</h2></div>
    <div class="space-y-2 p-4 text-sm">
        <p>{{ __('Language of the document: :language', ['language' => $languages[$read->options['language']] ?? $read->options['language']]) }}</p>
        @if ($hasText)
            <p>{{ __('Pages to read as text: :count', ['count' => count($textPages)]) }}</p>
        @endif
        @if ($source === 'text' && $pictures !== [])
            <p class="text-[#8a6208]">{{ __('Pages that can only be read as page pictures: :pages', ['pages' => implode(', ', $pictures)]) }}</p>
        @endif
    </div>

    @if ($source === 'text' && ! $hasText)
        <p class="border-t border-rule px-4 py-3 text-sm">{{ __('No page of this document can be read as text.') }}</p>
    @endif

    @if ($toRead !== [])
        <div class="space-y-2 border-t border-rule p-4 text-sm">
            @if ($source === 'image')
                <p class="font-medium">{{ __('The document is read as one page picture.') }}</p>
                <p class="text-xs text-ink-3">{{ __('The picture is made smaller in this browser if it needs to be, and uploaded. Nothing is read until you start.') }}</p>
            @else
                <p class="font-medium">{{ __('Pages to read as page pictures: :pages', ['pages' => implode(', ', $toRead)]) }}</p>
                <p class="text-xs text-ink-3">{{ __('Each page is drawn in this browser from the document and uploaded as a picture. Nothing is read until you start. A page read as a picture uses more credits than a page of text.') }}</p>
            @endif
            <p class="num" x-text="@js(__('Pictures uploaded: :done of :total')).replace(':done', toRead.filter((page) => stored.includes(page)).length).replace(':total', toRead.length)"></p>
            <p x-show="leftOut.length" x-cloak class="text-xs text-[#8a6208]" x-text="@js(__('Pages left out because they are blank or could not be drawn: :pages')).replace(':pages', leftOut.join(', '))"></p>
            @if ($canAct)
                <button type="button" class="btn btn-ghost" x-show="missing.length" :disabled="uploading || starting" @click="upload()">
                    <span x-show="!uploading">{{ $source === 'image' ? __('Upload the picture') : __('Draw and upload the pages') }}</span>
                    <span x-show="uploading" x-cloak>{{ __('Uploading…') }}</span>
                </button>
            @endif
            <p class="text-xs text-ink-3">{{ __('A page without a picture is not read. The tender sheet lists it as not read.') }}</p>
        </div>
    @endif

    @if (! $hasText && $toRead === [])
        <p class="border-t border-rule px-4 py-3 text-sm">{{ __('Nothing in the chosen pages can be read.') }}</p>
    @elseif ($canAct)
        <div class="border-t border-rule p-4 text-sm">
            <p x-show="!hasWork()" class="text-ink-3">{{ __('Upload the pictures to see the price.') }}</p>
            <p x-show="hasWork() && !quote" class="text-ink-3">{{ __('Working out the price…') }}</p>
            <template x-if="quote">
                <div class="space-y-2">
                    <p class="num" x-text="@js(__('About :estimate credits, and never more than :cap. About :minutes minutes.')).replace(':estimate', quote.estimate).replace(':cap', quote.cap).replace(':minutes', quote.minutes)"></p>
                    <p class="text-xs text-ink-3">{{ __('Each run of pages is charged for what the AI service really reads and writes. You can close this page; you are told when it is done.') }}</p>
                    <p x-show="!quote.enough" class="font-medium text-laterite">{{ __('There are not enough credits for the whole document now.') }}</p>
                    <button type="button" class="btn btn-primary" :disabled="starting || uploading || !quote.enough" @click="start()">{{ __('Start reading') }}</button>
                </div>
            </template>
        </div>
    @endif

    @if ($canAct)
        <details class="border-t border-rule px-4 py-3 text-sm">
            <summary class="link cursor-pointer">{{ __('Change the answers') }}</summary>
            @include('modules.tender_reading._questions', ['read' => $read, 'languages' => $languages, 'source' => $source, 'picturePages' => $picturePages])
        </details>
    @endif
</section>
