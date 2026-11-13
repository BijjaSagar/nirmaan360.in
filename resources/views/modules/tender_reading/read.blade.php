@extends('layouts.app')
@section('title', __('Reading a tender document'))
@section('content')
    @php
        $pdfUrl = $document ? route('crm.documents.download', $document) : null;
        $canAct = auth()->user()->can('crm.manage') && auth()->user()->can('agents.use');
        $languages = ['en' => __('English'), 'mr' => __('Marathi'), 'hi' => __('Hindi')];
    @endphp

    <x-page-header :title="__('Reading a tender document')" :subtitle="$tender->title.($document ? ' · '.$document->title : '')">
        <a href="{{ route('crm.tenders.show', $tender) }}" class="btn btn-ghost">{{ __('Back to the tender') }}</a>
    </x-page-header>

    @include('modules.tender_reading._pages')

    <div class="space-y-5" x-data="{ problem: null }">
        <p x-show="problem" x-cloak x-text="problem" class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert"></p>

        @if ($state === 'preparing')
            <section class="card p-4 text-sm"
                     x-data="{ async ask() {
                         try {
                             const answer = await fetch(@js(route('document-reads.status', $read)), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                             const now = answer.ok ? await answer.json() : null;
                             if (now && !['draft', 'preparing'].includes(now.status)) { window.location.reload(); return; }
                         } catch (error) {}
                         setTimeout(() => this.ask(), 3000);
                     } }" x-init="setTimeout(() => ask(), 3000)">
                <p class="font-medium">{{ __('Taking the text out of the PDF, page by page.') }}</p>
                <p class="mt-1 text-ink-3">{{ __('Nothing is sent to the AI service in this step. This page moves on by itself when it is done.') }}</p>
            </section>
        @elseif ($state === 'prepare_failed')
            <section class="card p-4 text-sm">
                <p class="font-medium text-laterite">{{ $error }}</p>
            </section>
        @elseif ($state === 'cancelled')
            <section class="card p-4 text-sm">
                <p>{{ __('This reading was cancelled. Nothing more is sent.') }}</p>
                @if (filled(\App\Services\DocumentReading\ReadWords::error($read->error)))
                    <p class="mt-1 text-ink-3">{{ \App\Services\DocumentReading\ReadWords::error($read->error) }}</p>
                @endif
            </section>
        @elseif ($state === 'questions')
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Before anything is read') }}</h2></div>
                @if ($samples === [])
                    <p class="px-4 py-3 text-sm">{{ __('No page of this PDF has text that can be read. Its pages can only be read as page pictures.') }}</p>
                @else
                    <p class="border-b border-rule px-4 py-2 text-sm text-ink-2">{{ __('On the left is the text the app took out of the PDF; on the right, the same page as it prints. Compare them before the text is sent to be read.') }}</p>
                    <div class="space-y-4 p-4">
                        @foreach ($samples as $sample)
                            <div class="grid gap-3 lg:grid-cols-2" data-pdf="{{ $pdfUrl }}" data-page="{{ (int) $sample['page'] }}"
                                 x-data="{ src: null, failed: false }"
                                 x-init="window.TenderPages.draw($el.dataset.pdf, Number($el.dataset.page)).then((url) => src = url).catch(() => failed = true)">
                                <div class="min-w-0">
                                    <p class="eyebrow mb-1 text-ink-3">{{ __('Text of page :page', ['page' => $sample['page']]) }}</p>
                                    <pre class="max-h-[480px] overflow-auto whitespace-pre-wrap break-words rounded-[3px] border border-rule bg-paper-2/40 p-2 text-xs">{{ $sample['text'] }}</pre>
                                </div>
                                <div class="min-w-0">
                                    <p class="eyebrow mb-1 text-ink-3">{{ __('Page :page as it prints', ['page' => $sample['page']]) }}</p>
                                    <p x-show="!src && !failed" class="text-xs text-ink-3">{{ __('Drawing page :page…', ['page' => $sample['page']]) }}</p>
                                    <p x-show="failed" x-cloak class="text-xs font-medium text-laterite">{{ __('This page could not be drawn in this browser. Open the document and turn to the page yourself.') }}</p>
                                    <template x-if="src"><img :src="src" alt="{{ __('Page :page of the document', ['page' => $sample['page']]) }}" class="w-full rounded-[3px] border border-rule bg-white"></template>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($canAct)
                        @include('modules.tender_reading._questions', ['read' => $read, 'languages' => $languages])
                    @endif
                @endif
            </section>
        @elseif ($state === 'quote')
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('What will be read') }}</h2></div>
                <div class="space-y-2 p-4 text-sm">
                    <p>{{ __('Language of the document: :language', ['language' => $languages[$read->options['language']] ?? $read->options['language']]) }}</p>
                    @if ($textPages !== [])
                        <p>{{ __('Pages to read as text: :count', ['count' => count($textPages)]) }}</p>
                    @endif
                    @if ($pictures !== [])
                        <p class="text-[#8a6208]">{{ __('Pages that can only be read as page pictures: :pages', ['pages' => implode(', ', $pictures)]) }}</p>
                        <p class="text-xs text-ink-3">{{ __('They are listed on the tender sheet as not read.') }}</p>
                    @endif
                </div>

                @if ($textPages === [])
                    <p class="border-t border-rule px-4 py-3 text-sm">{{ __('No page of this document can be read as text.') }}</p>
                @elseif ($canAct)
                    <div class="border-t border-rule p-4 text-sm"
                         x-data="{
                             quote: null, starting: false,
                             range: @js((object) $range),
                             async init() {
                                 await new Promise((resolve) => window.ReaderCapture ? resolve() : document.addEventListener('reader-capture:ready', resolve, { once: true }));
                                 try { this.quote = await window.ReaderCapture.quote({{ $read->id }}, this.range); }
                                 catch (error) { this.problem = error.message; }
                             },
                             async start() {
                                 this.starting = true;
                                 try {
                                     await window.ReaderCapture.start({{ $read->id }}, { ...this.range, cap: this.quote.cap, skip_pages: @js($pictures) });
                                     window.location.reload();
                                 } catch (error) { this.problem = error.message; this.starting = false; }
                             },
                         }">
                        <p x-show="!quote" class="text-ink-3">{{ __('Working out the price…') }}</p>
                        <template x-if="quote">
                            <div class="space-y-2">
                                <p class="num" x-text="@js(__('About :estimate credits, and never more than :cap. About :minutes minutes.')).replace(':estimate', quote.estimate).replace(':cap', quote.cap).replace(':minutes', quote.minutes)"></p>
                                <p class="text-xs text-ink-3">{{ __('Each run of pages is charged for what the AI service really reads and writes. You can close this page; you are told when it is done.') }}</p>
                                <p x-show="!quote.enough" class="font-medium text-laterite">{{ __('There are not enough credits for the whole document now.') }}</p>
                                <button type="button" class="btn btn-primary" :disabled="starting || !quote.enough" @click="start()">{{ __('Start reading') }}</button>
                            </div>
                        </template>
                    </div>
                @endif

                @if ($canAct)
                    <details class="border-t border-rule px-4 py-3 text-sm">
                        <summary class="link cursor-pointer">{{ __('Change the answers') }}</summary>
                        @include('modules.tender_reading._questions', ['read' => $read, 'languages' => $languages])
                    </details>
                @endif
            </section>
        @elseif ($state === 'running')
            <section class="card p-4 text-sm"
                     x-data="{ now: null, stop: null,
                         init() {
                             const go = () => { this.stop = window.ReaderCapture.watch({{ $read->id }}, (now) => {
                                 this.now = now;
                                 if (!['running', 'paused'].includes(now.status)) window.location.reload();
                             }, (error) => { this.problem = error.message; }); };
                             window.ReaderCapture ? go() : document.addEventListener('reader-capture:ready', go, { once: true });
                         } }">
                <p class="font-medium">{{ __('The pages are being read.') }}</p>
                <p class="mt-1 num" x-show="now" x-text="now ? @js(__('Pages read: :read of :total')).replace(':read', now.pages_read).replace(':total', now.pages_total) : ''"></p>
                <p class="mt-1 text-[#8a6208]" x-show="now && now.status === 'paused'" x-text="now ? now.message : ''"></p>
                <p class="mt-1 text-ink-3">{{ __('You can close this page; you are told when it is done.') }}</p>
            </section>
        @else
            <section class="card p-4 text-sm">
                <p class="font-medium">{{ __('Reading finished.') }}</p>
                <p class="mt-1 num">{{ __('Pages read: :read of :total', ['read' => (int) $read->pages_read, 'total' => (int) $read->pages_total]) }}</p>
                <a href="{{ route('tender-facts.index', $tender) }}" class="btn btn-primary mt-3">{{ __('Open the tender sheet') }}</a>
            </section>
        @endif

        @if (in_array($state, ['preparing', 'questions', 'quote', 'running'], true) && $canAct)
            <div class="text-right">
                <button type="button" class="link text-xs text-laterite"
                        @click="if (confirm(@js(__('Cancel this reading? Nothing more is sent.')))) { window.ReaderCapture.cancel({{ $read->id }}).then(() => window.location.reload()).catch((error) => problem = error.message); }">{{ __('Cancel this reading') }}</button>
            </div>
        @endif
    </div>
@endsection
