@extends('layouts.app')
@section('title', __('Dry run').' #'.$trial->id)
@section('content')
    @php
        $label = \App\Services\DocumentReading\AiSpend::label((string) ($trial->kind ?? $trial->purpose));
        $what = $trial->isTextCall() ? __('Text call').': '.$label : \Illuminate\Support\Str::ucfirst($label).' / '.$trial->pass;
        $open = $trial->status === \App\Models\ReaderTrial::DRAFT && ! $trial->isPurged();
        $text = $trial->sendsText();
        $waiting = $status['pages']['waiting'] ?? 0;
        $resend = collect($results)->contains(fn ($row) => $row['page']->mayBeSentAgain());
        $canStart = ! $trial->isPurged() && (
            ($open && $waiting > 0)
            || ($trial->status === \App\Models\ReaderTrial::PAUSED && ($waiting > 0 || $resend))
            || ($trial->status === \App\Models\ReaderTrial::DONE && $resend)
        );
        $emptyScore = ['answers_total' => 0, 'answers_wrong' => 0, 'cells_total' => 0, 'cells_wrong' => 0, 'fields' => [], 'scripts' => []];

        // What the script at the foot of this page works with.
        $script = [
            'statusUrl' => route('ai-reader.admin.trials.status', $trial, false),
            'pagesUrl' => route('ai-reader.admin.trials.pages.store', $trial, false),
            'marksUrl' => route('ai-reader.admin.trials.marks', $trial, false),
            'total' => (int) $trial->pages_total,
            'stored' => $status['stored_pages'],
            'moving' => $status['moving'],
            'state' => $status['status'].':'.json_encode($status['pages']),
            'score' => [...$emptyScore, ...($trial->score ?? [])],
            'words' => [
                'uploadFailed' => __('The page could not be uploaded.'),
                'pageLeftOut' => __('Page :n was left out:'),
                'tooManyPages' => __('This dry run takes :n pages. The rest was left out.'),
                'markFailed' => __('The mark could not be saved. Reload the page and try again.'),
            ],
        ];
    @endphp

    <x-page-header :title="__('Dry run').' #'.$trial->id" :subtitle="$what.' · '.$trial->model.' · '.__(':effort effort, up to :tokens tokens, :seconds seconds', ['effort' => $trial->effort, 'tokens' => number_format((int) $trial->max_tokens), 'seconds' => (int) $trial->timeout])">
        <a href="{{ route('ai-reader.admin.index') }}" class="btn btn-ghost">{{ __('Back to AI readers') }}</a>
        <form method="POST" action="{{ route('ai-reader.admin.trials.destroy', $trial) }}">
            @csrf @method('DELETE')
            <button class="btn btn-danger" onclick="return confirm(@js(__('Delete this dry run with its files and answers? Its tokens and cost stay in the totals.')))">{{ __('Delete') }}</button>
        </form>
    </x-page-header>

    <div class="max-w-6xl space-y-5" x-data="readerTrial(@js($script))" x-init="watch()">

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Where it stands') }}</h2>
                @include('modules.document_reading.admin._trial_status', ['status' => $trial->status])
            </div>
            <div class="space-y-3 p-4">
                @if ($status['message'])
                    <p class="rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" role="alert">{{ $status['message'] }}</p>
                @endif
                @error('trial')<p class="error" role="alert">{{ $message }}</p>@enderror

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <x-stat :label="__('Answers')" :value="($status['pages']['read'] ?? 0).' / '.array_sum($status['pages'])" :note="__('Failed: :count', ['count' => $status['pages']['failed'] ?? 0])" />
                    <x-stat :label="__('Cost (US$)')" :value="number_format((float) $trial->cost_usd, 4)" :note="__('Nobody is charged for a dry run')" />
                    <x-stat :label="__('Tokens in')" :value="number_format((int) $trial->input_tokens + (int) $trial->cache_read_tokens + (int) $trial->cache_write_tokens)" />
                    <x-stat :label="__('Tokens out')" :value="number_format((int) $trial->output_tokens)" />
                </div>

                <p class="text-sm text-ink-3">
                    {{ $trial->purged_at !== null
                        ? __('The files and the answers of this dry run have been deleted. The counts, tokens and cost stay.')
                        : __('The files and the answers are deleted on :when. The counts, tokens and cost stay.', ['when' => $trial->purge_after->format('d M Y, H:i')]) }}
                </p>

                @if ($canStart)
                    <form method="POST" action="{{ route('ai-reader.admin.trials.start', $trial) }}">
                        @csrf
                        <button class="btn btn-primary">{{ $open ? __('Start') : __('Start again') }}</button>
                        <span class="hint">{{ $open ? __('Sends each stored page to the AI service, one at a time.') : __('Sends the pages that are still waiting, and again those whose call went wrong. Pages the model answered are not sent again.') }}</span>
                    </form>
                @endif
                <p class="text-sm text-ink-3" x-show="moving" x-cloak>{{ __('This page updates by itself while the dry run is running.') }}</p>
            </div>
        </section>

        @if ($open)
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ $text ? __('Text to send') : __('Pages to send') }}</h2></div>
                <div class="space-y-3 p-4">
                    <p class="text-sm text-ink-3">{{ __('Use only a document you are allowed to test with. It is stored for this dry run alone and deleted with it.') }}</p>

                    @if ($text)
                        <form method="POST" action="{{ route('ai-reader.admin.trials.text', $trial) }}" class="space-y-2">
                            @csrf
                            @if ((int) $trial->pages_total > 1)
                                <x-field :label="__('Page')" name="page_no" class="max-w-32">
                                    <select id="page_no" name="page_no" class="input">
                                        @for ($number = 1; $number <= (int) $trial->pages_total; $number++)
                                            <option value="{{ $number }}" @selected((int) old('page_no', collect(range(1, (int) $trial->pages_total))->diff($status['stored_pages'])->first() ?? 1) === $number)>{{ $number }}</option>
                                        @endfor
                                    </select>
                                </x-field>
                            @endif
                            <x-field :label="$trial->isTextCall() ? __('Transcript, notes or list of facts') : __('Text of the page')" name="text" :hint="__('Up to :max characters. It is sent as it is typed here.', ['max' => number_format($mostTextChars)])">
                                <textarea id="text" name="text" rows="10" class="input font-mono text-sm" required>{{ old('text') }}</textarea>
                            </x-field>
                            <button class="btn btn-ghost">{{ __('Store the text') }}</button>
                        </form>
                    @else
                        <div class="space-y-2">
                            <label class="label" for="trial_files">{{ __('A PDF, or photos of the pages') }}</label>
                            <input id="trial_files" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" multiple class="input" :disabled="busy" @change="upload($event.target.files); $event.target.value = ''">
                            <p class="hint">{{ __('The pages are drawn in this browser and uploaded one by one. The PDF itself is not uploaded.') }}</p>
                            <p class="text-sm" x-show="busy" x-cloak>{{ __('Uploading...') }} <span x-text="progress"></span></p>
                            <p class="error" x-show="problem" x-text="problem" x-cloak role="alert"></p>
                            <template x-for="(note, index) in notes" :key="index"><p class="text-sm text-ink-3" x-text="note"></p></template>
                        </div>
                    @endif

                    <p class="text-sm">{{ __('Stored so far:') }} <span class="num" x-text="stored.length ? stored.join(', ') : '-'"></span> <span class="text-ink-3">{{ __('of :total', ['total' => (int) $trial->pages_total]) }}</span>
                        <a class="underline" href="" x-show="dirty" x-cloak>{{ __('Reload to start') }}</a></p>
                </div>
            </section>
        @endif

        @foreach ($results as $row)
            @php
                $page = $row['page'];
            @endphp
            <section class="card" id="page-{{ $page->id }}">
                <div class="card-head">
                    <h2 class="card-title">{{ $text && $trial->isTextCall() ? __('The answer') : __('Page :number', ['number' => (int) $page->page_no]) }} <span class="text-sm font-normal text-ink-3">{{ $trial->isTextCall() ? '' : $page->pass }} · {{ $page->model }}</span></h2>
                    @include('modules.document_reading.admin._trial_status', ['status' => $page->status])
                </div>
                <div class="space-y-3 p-4">
                    @if ((int) $page->attempts > 0)
                        <p class="text-sm text-ink-3">
                            {{ __('Tokens in :in, out :out. :seconds seconds.', ['in' => number_format((int) $page->input_tokens + (int) $page->cache_read_tokens + (int) $page->cache_write_tokens), 'out' => number_format((int) $page->output_tokens), 'seconds' => number_format((int) $page->latency_ms / 1000, 1)]) }}
                            @if ($page->tokensPerSecond() !== null){{ __(':speed output tokens a second.', ['speed' => $page->tokensPerSecond()]) }}@endif
                            {{ __('Cost :cost US dollars.', ['cost' => number_format((float) $page->cost_usd, 4)]) }}
                            @if ($page->cost_estimated){{ __('Part of this cost is an estimate: a call did not answer.') }}@endif
                            @if ($page->stop_reason){{ __('Stop reason: :reason.', ['reason' => $page->stop_reason]) }}@endif
                            @if ($page->provider_request_id){{ __('Request: :id.', ['id' => $page->provider_request_id]) }}@endif
                            @if ((int) $page->attempts > 1){{ __('Sent :count times.', ['count' => (int) $page->attempts]) }}@endif
                            @if ((int) $page->refused > 0){{ __('Answers refused by the feature\'s checks: :count.', ['count' => (int) $page->refused]) }}@endif
                        </p>
                    @endif

                    @if ($row['failure'])
                        <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm text-laterite-dark" role="alert">
                            {{ $row['failure'] }}
                            @if ($page->error_detail)<span class="num">{{ $page->error_detail }}</span>@endif
                            @if ($row['provider_note'])<span class="block">{{ __('The AI service said:') }} {{ $row['provider_note'] }}</span>@endif
                        </p>
                    @endif

                    @if ($row['blank'])
                        <p class="text-sm text-ink-3">{{ __('The reader reports this page as blank. A company would not be charged for it.') }}</p>
                    @endif

                    @foreach ($row['checks'] as $check)
                        <p class="text-sm {{ $check['ok'] ? 'text-moss' : 'text-laterite' }}">{{ $check['ok'] ? __('Check passed:') : __('Check failed:') }} {{ $check['label'] }}@if ($check['note']) <span class="text-ink-3">{{ $check['note'] }}</span>@endif</p>
                    @endforeach

                    @if ($row['answer'] !== null)
                        <div class="grid gap-4 {{ filled($page->image_path) ? 'lg:grid-cols-2' : '' }}">
                            @if (filled($page->image_path))
                                <div class="max-h-[80vh] overflow-auto border border-rule">
                                    <img src="{{ route('ai-reader.admin.trials.pages.image', [$trial, $page]) }}" alt="{{ __('Page :number', ['number' => (int) $page->page_no]) }}" loading="lazy" class="w-full">
                                </div>
                            @endif
                            <div class="min-w-0 space-y-2">
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" class="mt-0.5" @checked($row['whole_marked']) @change="mark({{ (int) $page->page_no }}, @js((string) $page->pass), '', '*', $event.target)">
                                    <span>{{ __('This whole answer is wrong or unusable') }}</span>
                                </label>
                                @if ($row['cells'] !== [])
                                    <div class="max-h-[72vh] overflow-auto">
                                        <table class="ledger">
                                            <thead><tr><th>{{ __('Row') }}</th><th>{{ __('Field') }}</th><th>{{ __('As printed') }}</th><th>{{ __('Remark') }}</th><th>{{ __('Digits') }}</th><th>{{ __('Read by the code as') }}</th><th>{{ __('Wrong') }}</th></tr></thead>
                                            <tbody>
                                            @foreach ($row['cells'] as $cell)
                                                <tr>
                                                    <td class="num">{{ $cell['row'] === '' ? '-' : $cell['row'] }}</td>
                                                    <td class="num">{{ $cell['field'] }}</td>
                                                    <td class="whitespace-pre-wrap break-words">{{ $cell['raw'] }}</td>
                                                    <td>{{ $cell['state'] }}</td>
                                                    <td>{{ $cell['script'] === \App\Services\DocumentReading\TrialCells::NO_DIGITS ? '-' : $cell['script'] }}</td>
                                                    <td class="num">
                                                        @if ($cell['read_as'] === null || $cell['raw'] === '')
                                                            -
                                                        @elseif ($cell['problem'] !== null && $cell['value'] === null)
                                                            <span class="text-laterite">{{ __('not read: :reason', ['reason' => $cell['problem']]) }}</span>
                                                        @else
                                                            {{ $cell['value'] }} <span class="text-ink-3">{{ $cell['read_as'] }}{{ $cell['problem'] !== null ? ', '.$cell['problem'] : '' }}{{ $cell['had_comma'] ? ', '.__('had a comma') : '' }}</span>
                                                        @endif
                                                    </td>
                                                    <td><input type="checkbox" aria-label="{{ __('Wrong') }}: {{ $cell['field'] }} {{ $cell['row'] }}" @checked(isset($row['marked'][\App\Services\DocumentReading\TrialCells::key($cell['field'], $cell['row'])])) @change="mark({{ (int) $page->page_no }}, @js((string) $page->pass), @js($cell['row']), @js($cell['field']), $event.target)"></td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                <details>
                                    <summary class="cursor-pointer text-sm text-ink-3">{{ __('The answer as it came back') }}</summary>
                                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap break-words border border-rule p-2 text-xs">{{ $row['answer'] }}</pre>
                                </details>
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        @endforeach

        <section class="card overflow-x-auto">
            <div class="card-head">
                <h2 class="card-title">{{ __('What was marked') }}</h2>
                <a class="btn btn-ghost" href="{{ route('ai-reader.admin.trials.scores', $trial) }}">{{ __('Download the counts (CSV)') }}</a>
            </div>
            <div class="space-y-3 p-4">
                <p class="error" x-show="markProblem" x-text="markProblem" x-cloak role="alert"></p>
                <p class="text-sm">
                    {{ __('Answers marked wrong:') }} <span class="num" x-text="score.answers_wrong + ' / ' + score.answers_total"></span>.
                    {{ __('Cells marked wrong:') }} <span class="num" x-text="score.cells_wrong + ' / ' + score.cells_total"></span>.
                </p>
                <p class="hint">{{ __('These are counts of what you marked on the documents you tried. They are kept after the files are deleted.') }}</p>
                <table class="ledger" x-show="score.fields.length" x-cloak>
                    <thead><tr><th>{{ __('Reading pass') }}</th><th>{{ __('Field') }}</th><th class="r">{{ __('Cells') }}</th><th class="r">{{ __('Marked wrong') }}</th></tr></thead>
                    <tbody>
                        <template x-for="row in score.fields" :key="row.pass + '/' + row.field"><tr><td x-text="row.pass"></td><td class="num" x-text="row.field"></td><td class="r" x-text="row.cells_total"></td><td class="r" x-text="row.cells_wrong"></td></tr></template>
                    </tbody>
                </table>
                <table class="ledger" x-show="Object.keys(score.scripts).length" x-cloak>
                    <thead><tr><th>{{ __('Script of the digits') }}</th><th class="r">{{ __('Cells') }}</th><th class="r">{{ __('Marked wrong') }}</th></tr></thead>
                    <tbody>
                        <template x-for="[name, counts] in Object.entries(score.scripts)" :key="name"><tr><td x-text="name"></td><td class="r" x-text="counts.cells_total"></td><td class="r" x-text="counts.cells_wrong"></td></tr></template>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
        // The dry-run page: uploads the pages the browser draws, watches the run and saves marks.
        function readerTrial(config) {
            const headers = () => ({
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            });

            const firstError = (data, otherwise) => (data.errors ? Object.values(data.errors)[0]?.[0] : null) ?? data.message ?? otherwise;

            return {
                stored: config.stored, moving: config.moving, score: { ...config.score, scripts: config.score.scripts ?? {} },
                busy: false, dirty: false, progress: '', problem: null, notes: [], markProblem: null,

                // While the run is moving, the server is asked every few seconds; the page is drawn again when anything changed.
                watch() {
                    if (! this.moving) {
                        return;
                    }

                    const timer = setInterval(async () => {
                        const response = await fetch(config.statusUrl, { credentials: 'same-origin', headers: headers() }).catch(() => null);

                        if (! response?.ok) {
                            return;
                        }

                        const status = await response.json();

                        if (status.status + ':' + JSON.stringify(status.pages) !== config.state) {
                            clearInterval(timer);
                            location.reload();
                        }
                    }, 3000);
                },

                async upload(files) {
                    this.busy = true;
                    this.problem = null;
                    this.notes = [];

                    try {
                        for (const file of Array.from(files)) {
                            if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                                await window.ReaderCapture.pdfToPages(file, async (page) => {
                                    if (page.pageNo > config.total) {
                                        this.notes.push(config.words.tooManyPages.replace(':n', config.total));

                                        return false;
                                    }

                                    if (page.status !== 'ok') {
                                        this.notes.push(config.words.pageLeftOut.replace(':n', page.pageNo) + ' ' + (page.message ?? page.status));

                                        return true;
                                    }

                                    await this.send(page.pageNo, page.blob);

                                    return true;
                                }, { skip: this.stored });
                            } else {
                                const next = Array.from({ length: config.total }, (_, index) => index + 1).find((number) => ! this.stored.includes(number));

                                if (next === undefined) {
                                    this.notes.push(config.words.tooManyPages.replace(':n', config.total));
                                    break;
                                }

                                await this.send(next, (await window.ReaderCapture.imageToPage(file)).blob);
                            }
                        }
                    } catch (error) {
                        this.problem = String(error?.message || config.words.uploadFailed);
                    }

                    this.busy = false;
                },

                async send(pageNo, blob) {
                    this.progress = String(pageNo);

                    const form = new FormData();
                    form.append('page_no', String(pageNo));
                    form.append('image', blob, `page-${pageNo}.jpg`);

                    const response = await fetch(config.pagesUrl, { method: 'POST', credentials: 'same-origin', headers: headers(), body: form });
                    const data = await response.json().catch(() => ({}));

                    if (! response.ok) {
                        throw new Error(firstError(data, config.words.uploadFailed));
                    }

                    this.stored = [...new Set([...this.stored, pageNo])].sort((a, b) => a - b);
                    this.dirty = true;
                },

                async mark(pageNo, pass, row, field, box) {
                    this.markProblem = null;

                    const response = await fetch(config.marksUrl, {
                        method: 'POST', credentials: 'same-origin', headers: { ...headers(), 'Content-Type': 'application/json' },
                        body: JSON.stringify({ page_no: pageNo, pass, row, field, wrong: box.checked }),
                    }).catch(() => null);

                    const data = response ? await response.json().catch(() => ({})) : {};

                    if (! response?.ok || ! data.score) {
                        box.checked = ! box.checked;
                        this.markProblem = firstError(data, config.words.markFailed);

                        return;
                    }

                    this.score = { ...data.score, scripts: Array.isArray(data.score.scripts) ? {} : data.score.scripts };
                },
            };
        }
    </script>

    @unless ($text)
        @include('modules.document_reading._capture')
    @endunless
@endsection
