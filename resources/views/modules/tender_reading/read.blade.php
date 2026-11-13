@extends('layouts.app')
@section('title', __('Reading a tender document'))
@section('content')
    @php
        $fileUrl = $document ? route('crm.documents.download', $document) : null;
        $pdfUrl = $source === 'image' ? null : $fileUrl;
        $canAct = auth()->user()->can('crm.manage') && auth()->user()->can('agents.use');
        $languages = ['en' => __('English'), 'mr' => __('Marathi'), 'hi' => __('Hindi')];
        $picturePages = max(1, (int) config('modules.tender_reading.picture_pages', 50));
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
                @if ($offerPictures && $canAct)
                    <form method="POST" action="{{ route('tender-reads.store', ['tender' => $tender, 'document' => $document]) }}" class="mt-3 space-y-2 border-t border-rule pt-3">
                        @csrf
                        <input type="hidden" name="client_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="as" value="pictures">
                        <p>{{ __('Its pages can be read as page pictures instead, up to :pages pages at a time. You choose the pages and see the price before anything is read.', ['pages' => $picturePages]) }}</p>
                        <button class="btn btn-primary">{{ __('Read pages as pictures') }}</button>
                    </form>
                @endif
            </section>
        @elseif ($state === 'cancelled')
            <section class="card p-4 text-sm">
                <p>{{ __('This reading was cancelled. Nothing more is sent.') }}</p>
                @if (filled(\App\Services\DocumentReading\ReadWords::error($read->error)))
                    <p class="mt-1 text-ink-3">{{ \App\Services\DocumentReading\ReadWords::error($read->error) }}</p>
                @endif
            </section>
        @elseif ($state === 'questions' && $source !== 'text')
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Before anything is read') }}</h2></div>
                <div class="space-y-3 p-4 text-sm">
                    @if ($source === 'image')
                        <p>{{ __('This document is a picture. It is read as one page picture.') }}</p>
                        @if ($fileUrl)
                            <img src="{{ $fileUrl }}" alt="{{ __('Page :page of the document', ['page' => 1]) }}" class="max-h-[480px] rounded-[3px] border border-rule bg-white">
                        @endif
                    @elseif ($read->options['too_large'] ?? false)
                        <p>{{ __('Only a PDF of up to :mb MB can be read as text, so its pages are read as page pictures.', ['mb' => intdiv((int) config('modules.tender_reading.max_bytes', 10 * 1024 * 1024), 1024 * 1024)]) }}</p>
                    @else
                        <p>{{ __('The pages of this PDF are read as page pictures, up to :pages pages at a time.', ['pages' => $picturePages]) }}</p>
                    @endif
                </div>
                @if ($canAct)
                    @include('modules.tender_reading._questions', ['read' => $read, 'languages' => $languages, 'source' => $source, 'picturePages' => $picturePages])
                @endif
            </section>
        @elseif ($state === 'questions')
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Before anything is read') }}</h2></div>
                @if ($samples === [])
                    <p class="px-4 py-3 text-sm">{{ __('No page of this PDF has text that can be read. Its pages can only be read as page pictures.') }}</p>
                    @if ($canAct)
                        @include('modules.tender_reading._questions', ['read' => $read, 'languages' => $languages, 'source' => $source, 'picturePages' => $picturePages])
                    @endif
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
                        @include('modules.tender_reading._questions', ['read' => $read, 'languages' => $languages, 'source' => $source, 'picturePages' => $picturePages])
                    @endif
                @endif
            </section>
        @elseif ($state === 'quote')
            @include('modules.tender_reading._start')
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
                <p class="mt-1 num" x-show="now" x-text="now ? @js(__('Pages read: :read of :total')).replace(':read', now.pages_read).replace(':total', Math.max(now.pages_total, {{ (int) $readTotal }})) : ''"></p>
                <p class="mt-1 text-[#8a6208]" x-show="now && now.status === 'paused'" x-text="now ? now.message : ''"></p>
                <p class="mt-1 text-ink-3">{{ __('You can close this page; you are told when it is done.') }}</p>
            </section>
        @else
            <section class="card p-4 text-sm">
                <p class="font-medium">{{ __('Reading finished.') }}</p>
                <p class="mt-1 num">{{ __('Pages read: :read of :total', ['read' => (int) $read->pages_read, 'total' => (int) $readTotal]) }}</p>
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
