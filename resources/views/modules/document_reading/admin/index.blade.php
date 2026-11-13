@extends('layouts.app')
@section('title', __('AI readers'))
@section('content')
    @php
        $feature = fn (string $key) => \Illuminate\Support\Str::ucfirst(\App\Services\DocumentReading\AiSpend::label($key));
        $ago = fn ($moment) => $moment === null ? __('Never') : $moment->diffForHumans();
        $warn = 'rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm';
        $alarm = 'rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark';
    @endphp

    <x-page-header :title="__('AI readers')" :subtitle="__('Spending limits, the release switch, use by company and dry runs of the document readers')">
        <span class="stamp {{ $release['on'] ? 'border-moss text-moss' : 'border-ink-3 text-ink-3' }}">{{ $release['on'] ? __('Offered to companies') : __('Not offered to companies') }}</span>
    </x-page-header>
    @include('admin._tabs')

    <div class="max-w-6xl space-y-5">
        {{-- What stands in the way of a page being sent, most serious first. --}}
        <div class="space-y-2">
            @if (! $health['connected'])
                <p class="{{ $alarm }}" role="alert">{{ __('No API key for the AI service is saved. Nothing can be sent.') }} <a class="underline" href="{{ route('agents.admin.edit') }}">{{ __('Open the AI agents page to save one.') }}</a></p>
            @endif
            @if ($health['stop'] !== null)
                <form method="POST" action="{{ route('ai-reader.admin.provider-stop.clear') }}" class="{{ $alarm }} flex flex-wrap items-center justify-between gap-2" role="alert">
                    @csrf @method('DELETE')
                    <span>
                        {{ \App\Services\DocumentReading\ReaderTrials::pauseWords($health['stop']['reason']) }}
                        {{ $health['stop_until'] === null ? __('No page is sent until you clear this stop.') : __('No page is sent until :time, unless you clear this stop.', ['time' => $health['stop_until']->format('H:i')]) }}
                    </span>
                    <button class="btn btn-ghost">{{ __('Clear the stop') }}</button>
                </form>
            @endif
            @if (! $health['can_redraw'])
                <p class="{{ $alarm }}" role="alert">{{ __('This server cannot redraw pictures, so no page can be stored. The PHP image functions (GD) are missing or failed.') }}</p>
            @endif
            @if ($health['tend_late'])
                <p class="{{ $warn }}" role="alert">{{ __('The scheduler does not seem to be running: the last tending round was :when. Reads that nobody is watching will not move.', ['when' => $ago($health['last_tend'])]) }}</p>
            @endif
            @if ($health['queue_late'])
                <p class="{{ $warn }}" role="alert">{{ __('A page has been waiting in the queue since :when. Check that a queue worker is running and reads the queues named below.', ['when' => $ago($health['oldest_queued'])]) }}</p>
            @endif
            @if ($release['switched_on'] && ! $release['released'])
                <p class="{{ $warn }}" role="alert">{{ __('The release switch is on, but the provider\'s monthly cap is not on record, so no company is offered a reader.') }}</p>
            @elseif ($release['released'] && ! $release['on'])
                <p class="{{ $warn }}" role="alert">{{ $release['connected'] ? __('The release switch is on, but no reader is deployed yet, so no company is offered one.') : __('The release switch is on, but no API key is saved, so no company is offered a reader.') }}</p>
            @endif
            @if ($release['not_confirmed'] !== [])
                <p class="{{ $warn }}" role="alert">{{ __('Deployed after the release, with no dry run confirmed: :features.', ['features' => implode(', ', array_map($feature, $release['not_confirmed']))]) }}</p>
            @endif
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <x-stat :label="__('This month (US$)')" :value="$spend['month']" :note="$spend['limit'] === null ? __('No monthly cap entered') : __('Readers stop at :limit of the :cap cap', ['limit' => $spend['limit'], 'cap' => $spend['cap']])" />
            <x-stat :label="__('Companies today (US$)')" :value="$spend['today']" :note="__('Daily limit :limit', ['limit' => $spend['daily']])" />
            <x-stat :label="__('Dry runs today (US$)')" :value="$spend['trial_today']" :note="__('Daily allowance :limit', ['limit' => $spend['trial_allowance']])" />
            <x-stat :label="__('Last tending round')" :value="$ago($health['last_tend'])" :note="__('Runs every minute from the scheduler')" :accent="$health['tend_late']" />
            <x-stat :label="__('Oldest page in the queue')" :value="$health['oldest_queued'] === null ? __('None waiting') : $ago($health['oldest_queued'])" :note="__('Minutes here mean no worker is reading')" :accent="$health['queue_late']" />
        </div>
        <p class="hint">{{ __('The month is the provider\'s calendar month (UTC) and counts agents, readers, text calls and dry runs. A call that never answered is counted at the most it could have cost.') }}</p>

        <div class="grid gap-5 lg:grid-cols-2">
            <form method="POST" action="{{ route('ai-reader.admin.settings') }}" class="card" autocomplete="off">
                @csrf @method('PATCH')
                <input type="hidden" name="action" value="limits">
                <div class="card-head"><h2 class="card-title">{{ __('Spending limits') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <x-field :label="__('Provider\'s monthly cap (US$)')" name="monthly_cap" :hint="__('Copy it from the provider\'s console. The readers cannot be offered without it.')">
                        <input id="monthly_cap" name="monthly_cap" inputmode="decimal" class="input num" value="{{ old('monthly_cap', $saved['limits']['monthly_cap']['saved']) }}">
                    </x-field>
                    <x-field :label="__('Share of the cap the month may reach')" name="month_share" :hint="__('At this share no new page or text call is sent. The agents keep the rest.')">
                        <input id="month_share" name="month_share" inputmode="decimal" class="input num" value="{{ old('month_share', $saved['limits']['month_share']['saved']) }}" placeholder="{{ $saved['limits']['month_share']['usual'] }}">
                    </x-field>
                    <x-field :label="__('Daily limit for all companies (US$)')" name="daily_limit" :hint="__('Readers and text calls of every company together, per day in India.')">
                        <input id="daily_limit" name="daily_limit" inputmode="decimal" class="input num" value="{{ old('daily_limit', $saved['limits']['daily_limit']['saved']) }}" placeholder="{{ $saved['limits']['daily_limit']['usual'] }}">
                    </x-field>
                    <x-field :label="__('Daily allowance for dry runs (US$)')" name="trial_allowance" :hint="__('Apart from the companies\' limit. Zero switches dry runs off.')">
                        <input id="trial_allowance" name="trial_allowance" inputmode="decimal" class="input num" value="{{ old('trial_allowance', $saved['limits']['trial_allowance']['saved']) }}" placeholder="{{ $saved['limits']['trial_allowance']['usual'] }}">
                    </x-field>
                </div>
                <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save limits') }}</button> <span class="hint">{{ __('An empty box uses the figure shown in grey.') }}</span></div>
            </form>

            <form method="POST" action="{{ route('ai-reader.admin.settings') }}" class="card" autocomplete="off">
                @csrf @method('PATCH')
                <input type="hidden" name="action" value="queues">
                <div class="card-head"><h2 class="card-title">{{ __('Queue names') }}</h2></div>
                <div class="space-y-3 p-4">
                    <p class="{{ $warn }}">{{ __('Set a name here only after a worker listens to it. Jobs sent to a queue with no worker never run.') }}</p>
                    @foreach (['interactive' => __('Slips, bills and text calls'), 'pages' => __('BOQ and tender pages'), 'prepare' => __('Taking the text out of a tender PDF')] as $lane => $label)
                        <x-field :label="$label" name="queues.{{ $lane }}">
                            <input id="queues.{{ $lane }}" name="queues[{{ $lane }}]" class="input num" value="{{ old('queues.'.$lane, $saved['queues'][$lane]['saved']) }}" placeholder="{{ $saved['queues'][$lane]['usual'] === 'default' ? __('The worker\'s own queue') : $saved['queues'][$lane]['usual'] }}">
                        </x-field>
                    @endforeach
                </div>
                <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save queue names') }}</button></div>
            </form>
        </div>

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Offering the readers to companies') }}</h2>
                <span class="text-sm {{ $release['switched_on'] ? 'text-moss' : 'text-ink-3' }}">{{ $release['switched_on'] ? __('Switched on') : __('Switched off') }}</span>
            </div>
            <div class="space-y-3 p-4">
                <p class="text-sm text-ink-3">{{ __('Until this is on, no company sees a reader, its consent switch or an AI button, and every reader route refuses. The four agents are not affected.') }}</p>
                <p class="text-sm text-ink-3">{{ __('A company is offered a reader only while all of these hold: the switch is on, the monthly cap is entered, an API key is saved and a reader is deployed for its plan. The public website, the consent switch and the field app ask the same test.') }}</p>

                @if ($release['switched_on'])
                    <p class="text-sm">
                        {{ $release['released_at'] === null ? __('Switched on.') : __('Switched on :when.', ['when' => $release['released_at']->format('d M Y, H:i')]) }}
                        @if ($release['dry_run_done'] !== [])
                            {{ __('Dry run confirmed for: :features.', ['features' => implode(', ', array_map($feature, $release['dry_run_done']))]) }}
                        @endif
                    </p>
                    <form method="POST" action="{{ route('ai-reader.admin.settings') }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="withdraw">
                        <button class="btn btn-danger" onclick="return confirm(@js(__('Stop offering the readers to every company? Reads that are running will pause before their next page.')))">{{ __('Switch the readers off for every company') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('ai-reader.admin.settings') }}" class="space-y-2">
                        @csrf @method('PATCH')
                        <input type="hidden" name="action" value="release">
                        <p class="text-sm {{ $spend['cap'] === null ? 'text-laterite' : 'text-moss' }}">{{ $spend['cap'] === null ? __('The provider\'s monthly cap is not entered yet.') : __('The provider\'s monthly cap is on record: :cap US dollars.', ['cap' => $spend['cap']]) }}</p>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="alerts_set" value="1" class="mt-0.5"> <span>{{ __('Spend alerts are set in the provider console.') }}</span></label>
                        @error('alerts_set')<p class="error">{{ $message }}</p>@enderror
                        @foreach ($release['features'] as $key)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="dry_run[]" value="{{ $key }}" class="mt-0.5">
                                <span>{{ __('The dry run was done for :feature.', ['feature' => \App\Services\DocumentReading\AiSpend::label($key)]) }} <span class="text-ink-3">{{ __('Dry-run answers on record: :count.', ['count' => $trialPagesRead[$key] ?? 0]) }}</span></span>
                            </label>
                        @endforeach
                        @error('dry_run')<p class="error">{{ $message }}</p>@enderror
                        @error('monthly_cap')<p class="error">{{ $message }}</p>@enderror
                        @if ($release['features'] === [])
                            <p class="text-sm text-ink-3">{{ __('No reader is deployed yet, so there is no dry run to confirm.') }}</p>
                        @endif
                        <button class="btn btn-primary">{{ __('Offer the readers to companies') }}</button>
                    </form>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Dry run') }}</h2></div>
            <div class="space-y-3 p-4">
                <p class="text-sm text-ink-3">{{ __('Try a reader on a document you upload here. Nothing is created in any company and nobody is charged. The files and what was read from them are deleted after :hours hours; tokens and cost stay in the totals.', ['hours' => \App\Models\ReaderTrial::KEEP_HOURS]) }}</p>

                @if ($targets['kinds'] === [] && $targets['purposes'] === [])
                    <p class="text-sm font-semibold">{{ __('No reader is deployed yet, so there is nothing to try.') }}</p>
                @else
                    <form method="POST" action="{{ route('ai-reader.admin.trials.store') }}" class="space-y-3 border-t border-rule pt-3" autocomplete="off"
                        x-data="{
                            targets: @js($targets),
                            target: @js(old('target', array_key_first($targets['kinds']) !== null ? 'kind:'.array_key_first($targets['kinds']) : 'purpose:0')),
                            pass: @js(old('pass', '')),
                            model: '', effort: '', maxTokens: '', timeout: '',
                            get kind() { return this.target.startsWith('kind:') ? this.target.slice(5) : null; },
                            get purpose() { return this.target.startsWith('purpose:') ? this.targets.purposes[Number(this.target.slice(8))] : null; },
                            get passes() { return this.kind ? Object.keys(this.targets.kinds[this.kind].passes) : []; },
                            get usual() { return this.kind ? (this.targets.kinds[this.kind].passes[this.pass] ?? null) : this.purpose; },
                            fill() {
                                if (this.kind && ! this.passes.includes(this.pass)) { this.pass = this.passes[0] ?? ''; }
                                const usual = this.usual;
                                if (usual) { this.model = usual.model; this.effort = usual.effort; this.maxTokens = usual.max_tokens; this.timeout = usual.timeout; }
                            },
                        }" x-init="fill()">
                        @csrf
                        <input type="hidden" name="kind" :value="kind ?? ''">
                        <input type="hidden" name="purpose" :value="purpose ? purpose.purpose : ''">
                        <input type="hidden" name="subject_type" :value="purpose ? (purpose.subject_type ?? '') : ''">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <x-field :label="__('What to try')" name="kind">
                                <select id="kind" name="target" class="input" x-model="target" @change="fill()">
                                    @foreach ($targets['kinds'] as $key => $kind)
                                        <option value="kind:{{ $key }}">{{ $feature($key) }}</option>
                                    @endforeach
                                    @foreach ($targets['purposes'] as $index => $purpose)
                                        <option value="purpose:{{ $index }}">{{ __('Text call') }}: {{ $purpose['label'] }}{{ $purpose['subject_type'] === null ? '' : ' ('.class_basename($purpose['subject_type']).')' }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field :label="__('Reading pass')" name="pass" x-show="kind">
                                <select id="pass" name="pass" class="input" x-model="pass" @change="fill()">
                                    <template x-for="name in passes" :key="name"><option :value="name" x-text="name" :selected="name === pass"></option></template>
                                </select>
                            </x-field>
                            <x-field :label="__('What is sent')" name="material" x-show="kind">
                                <select id="material" name="material" class="input">
                                    <option value="pictures">{{ __('Page pictures') }}</option>
                                    <option value="text" @selected(old('material') === 'text')>{{ __('Pasted page text') }}</option>
                                </select>
                            </x-field>
                            <x-field :label="__('Pages')" name="pages_total" x-show="kind">
                                <input id="pages_total" name="pages_total" type="number" min="1" class="input num" value="{{ old('pages_total', 1) }}">
                            </x-field>
                            <x-field :label="__('Model')" name="model">
                                <select id="model" name="model" class="input" x-model="model">
                                    @foreach ($models as $model)
                                        <option value="{{ $model }}">{{ $model }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field :label="__('Effort')" name="effort">
                                <select id="effort" name="effort" class="input" x-model="effort">
                                    @foreach (\App\Services\DocumentReading\ReaderTrials::EFFORTS as $effort)
                                        <option value="{{ $effort }}">{{ $effort }}</option>
                                    @endforeach
                                </select>
                            </x-field>
                            <x-field :label="__('Output limit (tokens)')" name="max_tokens">
                                <input id="max_tokens" name="max_tokens" type="number" class="input num" x-model="maxTokens" min="{{ \App\Services\DocumentReading\ReaderTrials::LEAST_TOKENS }}" max="{{ \App\Services\DocumentReading\ReaderTrials::MOST_TOKENS }}">
                            </x-field>
                            <x-field :label="__('Time limit (seconds)')" name="timeout">
                                <input id="timeout" name="timeout" type="number" class="input num" x-model="timeout" min="{{ \App\Services\DocumentReading\ReaderTrials::SHORTEST_TIMEOUT }}" max="{{ \App\Services\DocumentReading\ReaderTrials::LONGEST_TIMEOUT }}">
                            </x-field>
                            <x-field :label="__('Main language of the document')" name="language" x-show="kind">
                                <select id="language" name="language" class="input">
                                    <option value="">{{ __('Not said') }}</option>
                                    <option value="en" @selected(old('language') === 'en')>English</option>
                                    <option value="hi" @selected(old('language') === 'hi')>हिन्दी</option>
                                    <option value="mr" @selected(old('language') === 'mr')>मराठी</option>
                                </select>
                            </x-field>
                        </div>
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="allowed" value="1" class="mt-0.5" required>
                            <span>{{ __('I am allowed to use this document for testing.') }}</span>
                        </label>
                        @error('allowed')<p class="error">{{ $message }}</p>@enderror
                        <button class="btn btn-primary">{{ __('Make the dry run') }}</button>
                    </form>
                @endif
            </div>

            @if ($recentTrials->isNotEmpty())
                <div class="overflow-x-auto border-t border-rule">
                    <table class="ledger">
                        <thead><tr><th>{{ __('Dry run') }}</th><th>{{ __('What') }}</th><th>{{ __('Model') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('Pages') }}</th><th class="r">{{ __('Cost (US$)') }}</th><th class="r">{{ __('Cells marked wrong') }}</th><th>{{ __('Files deleted') }}</th></tr></thead>
                        <tbody>
                        @foreach ($recentTrials as $trial)
                            <tr>
                                <td><a class="underline" href="{{ route('ai-reader.admin.trials.show', $trial) }}">#{{ $trial->id }}</a> <span class="text-ink-3">{{ $trial->created_at?->format('d M, H:i') }}</span></td>
                                <td>{{ $trial->isTextCall() ? __('Text call').': '.\App\Services\DocumentReading\AiSpend::label($trial->purpose) : $feature($trial->kind).' / '.$trial->pass }}</td>
                                <td class="num">{{ $trial->model }}</td>
                                <td>@include('modules.document_reading.admin._trial_status', ['status' => $trial->status])</td>
                                <td class="r">{{ (int) $trial->pages_total }}</td>
                                <td class="r">{{ number_format((float) $trial->cost_usd, 4) }}</td>
                                <td class="r">{{ (int) ($trial->score['cells_wrong'] ?? 0) }} / {{ (int) ($trial->score['cells_total'] ?? 0) }}</td>
                                <td>{{ $trial->purged_at !== null ? __('Yes') : $trial->purge_after?->format('d M, H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('What each reader runs with, and what was measured') }}</h2></div>
            @if ($readers === [])
                <x-empty :title="__('No reader is deployed yet')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Reader') }}</th><th>{{ __('Reading pass') }}</th><th>{{ __('Model') }}</th><th class="r">{{ __('Output limit') }}</th><th class="r">{{ __('Time limit (s)') }}</th><th class="r">{{ __('Prompt tokens') }}</th><th class="r">{{ __('Usual output') }}</th><th class="r">{{ __('Usual seconds') }}</th><th class="r">{{ __('Pages, :days days', ['days' => \App\Services\DocumentReading\ReaderDashboard::DAYS]) }}</th><th class="r">{{ __('Cut off') }}</th><th class="r">{{ __('Tokens a second') }}</th></tr></thead>
                    <tbody>
                    @foreach ($readers as $row)
                        <tr>
                            <td>{{ $feature($row['kind']) }}</td>
                            <td>{{ $row['pass'] }}</td>
                            <td class="num">{{ $row['model'] }}</td>
                            <td class="r">{{ number_format($row['max_tokens']) }}</td>
                            <td class="r">{{ $row['timeout'] }}</td>
                            <td class="r">{{ number_format($row['prompt_tokens']) }}</td>
                            <td class="r">{{ number_format($row['typical_output']) }} <span class="text-ink-3">{{ $row['typical_measured'] ? __('measured') : __('assumed') }}</span></td>
                            <td class="r">{{ $row['typical_seconds'] }}</td>
                            <td class="r">{{ number_format($row['pages']) }}</td>
                            <td class="r">{{ number_format($row['cut_offs']) }}</td>
                            <td class="r">{{ $row['tokens_per_second'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="hint px-4 py-3">{{ __('The output limit, the time limit and the assumed speed of :speed tokens a second belong together: a call must be able to write its limit within 80 percent of its time. Change the three together in the config file once the dry runs show the real speed.', ['speed' => (int) config('modules.document_reading.assumed_tokens_per_second')]) }}</p>
            @endif

            @if ($trialFigures !== [])
                <div class="card-head border-t border-rule"><h2 class="card-title">{{ __('Measured in dry runs, last :days days', ['days' => \App\Services\DocumentReading\ReaderDashboard::DAYS]) }}</h2></div>
                <table class="ledger">
                    <thead><tr><th>{{ __('What') }}</th><th>{{ __('Reading pass') }}</th><th>{{ __('Model') }}</th><th class="r">{{ __('Answers') }}</th><th class="r">{{ __('Usable') }}</th><th class="r">{{ __('Cut off') }}</th><th class="r">{{ __('Refused by checks') }}</th><th class="r">{{ __('Usual output') }}</th><th class="r">{{ __('Usual seconds') }}</th><th class="r">{{ __('Tokens a second') }}</th><th class="r">{{ __('US$ a call') }}</th></tr></thead>
                    <tbody>
                    @foreach ($trialFigures as $row)
                        <tr>
                            <td>{{ $row['kind'] !== null ? $feature($row['kind']) : __('Text call').': '.\App\Services\DocumentReading\AiSpend::label((string) $row['purpose']) }}</td>
                            <td>{{ $row['pass'] }}</td>
                            <td class="num">{{ $row['model'] }}</td>
                            <td class="r">{{ number_format($row['pages']) }}</td>
                            <td class="r">{{ number_format($row['read_pages']) }}</td>
                            <td class="r">{{ number_format($row['cut_offs']) }}</td>
                            <td class="r">{{ number_format($row['refused']) }}</td>
                            <td class="r">{{ number_format($row['typical_output']) }}</td>
                            <td class="r">{{ $row['typical_seconds'] }}</td>
                            <td class="r">{{ $row['tokens_per_second'] ?? '-' }}</td>
                            <td class="r">{{ $row['cost_per_page_usd'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="card overflow-x-auto">
            <div class="card-head">
                <h2 class="card-title">{{ __('Use by company this month') }}</h2>
                <form method="GET" action="{{ route('ai-reader.admin.index') }}" class="flex items-center gap-2">
                    <input name="company" value="{{ $companySearch }}" class="input max-w-48" placeholder="{{ __('Find a company') }}" aria-label="{{ __('Find a company') }}">
                    <button class="btn btn-ghost">{{ __('Find') }}</button>
                </form>
            </div>
            @if ($companies->isEmpty())
                <x-empty :title="__('No company has used a reader this month')" :note="__('Find a company by name to lift its trial allowance or to switch the readers off for it.')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Company') }}</th><th class="r">{{ __('Pages') }}</th><th class="r">{{ __('Pages read') }}</th><th class="r">{{ __('Pages failed') }}</th><th class="r">{{ __('Text calls') }}</th><th class="r">{{ __('Cost (US$)') }}</th><th class="r">{{ __('Not charged (US$)') }}</th><th class="r">{{ __('Credits') }}</th><th class="r">{{ __('Unusable, 24 hours') }}</th><th>{{ __('May use the readers') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($companies as $row)
                        <tr>
                            <td>{{ $row['organization']->name }} <span class="text-ink-3">#{{ $row['organization']->id }}</span></td>
                            <td class="r">{{ number_format($row['pages']) }}</td>
                            <td class="r">{{ number_format($row['read_pages']) }}</td>
                            <td class="r">{{ number_format($row['failed_pages']) }}</td>
                            <td class="r">{{ number_format($row['text_calls']) }}</td>
                            <td class="r">{{ $row['cost_usd'] }}</td>
                            <td class="r">{{ $row['absorbed_usd'] }}</td>
                            <td class="r">{{ $row['credits'] }}</td>
                            <td class="r">{{ $row['unusable_answers'] }} / {{ (int) config('modules.document_reading.failed_pages_per_day') }}</td>
                            <td class="text-sm">
                                @if ($row['blocked'])<span class="text-laterite">{{ __('Readers switched off') }}</span>@elseif (! $row['consent'])<span class="text-ink-3">{{ __('No consent') }}</span>@else{{ __('Yes') }}@endif
                                @if ($row['on_trial'])
                                    <span class="text-ink-3">· {{ $row['quota_lifted'] ? __('Trial, allowance lifted') : __('Trial, :pages pages left', ['pages' => (int) $row['trial_pages_left']]) }}</span>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('ai-reader.admin.companies.update', $row['organization']) }}" class="flex flex-wrap justify-end gap-1.5">
                                    @csrf @method('PATCH')
                                    @if ($row['on_trial'] || $row['quota_lifted'])
                                        <button name="action" value="{{ $row['quota_lifted'] ? 'restore_quota' : 'lift_quota' }}" class="btn btn-ghost">{{ $row['quota_lifted'] ? __('Apply the trial allowance') : __('Lift the trial allowance') }}</button>
                                    @endif
                                    @if ($row['unusable_answers'] > 0)
                                        <button name="action" value="lift_brake" class="btn btn-ghost">{{ __('Forget unusable answers') }}</button>
                                    @endif
                                    <button name="action" value="{{ $row['blocked'] ? 'unblock' : 'block' }}" class="btn {{ $row['blocked'] ? 'btn-ghost' : 'btn-danger' }}">{{ $row['blocked'] ? __('Switch on again') : __('Switch off') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="card" x-data="{ running: false, result: null, error: null,
            async run() {
                this.running = true; this.error = null; this.result = null;
                try { this.result = await window.ReaderCapture.selfCheck(@js($fixtures)); } catch (problem) { this.error = String(problem?.message ?? problem); }
                this.running = false;
            } }">
            <div class="card-head"><h2 class="card-title">{{ __('Browser self-check') }}</h2></div>
            <div class="space-y-3 p-4">
                <p class="text-sm text-ink-3">{{ __('Draws four small test PDFs, one for each way a scanner stores a page, in this browser, and checks the size arithmetic. A browser that passes can draw customers\' scans. The test pages are invented.') }}</p>
                <button type="button" class="btn btn-ghost" @click="run()" :disabled="running">{{ __('Run the self-check') }}</button>
                <p class="text-sm text-ink-3" x-show="running" x-cloak>{{ __('Drawing the test pages...') }}</p>
                <p class="error" x-show="error" x-text="error" x-cloak></p>
                <div x-show="result" x-cloak class="space-y-2">
                    <p class="text-sm font-semibold" :class="result?.ok ? 'text-moss' : 'text-laterite'" x-text="result?.ok ? @js(__('Every check passed in this browser.')) : @js(__('A check failed in this browser. See below.'))"></p>
                    <table class="ledger">
                        <thead><tr><th>{{ __('Check') }}</th><th>{{ __('Result') }}</th></tr></thead>
                        <tbody>
                            <template x-for="row in (result?.fixtures ?? [])" :key="'f' + row.name"><tr><td x-text="row.name"></td><td :class="row.ok ? 'text-moss' : 'text-laterite'" x-text="row.ok ? (row.width + ' x ' + row.height) : (row.status + (row.message ? ': ' + row.message : ''))"></td></tr></template>
                            <template x-for="row in (result?.sizes ?? [])" :key="'s' + row.name"><tr><td x-text="row.name"></td><td :class="row.ok ? 'text-moss' : 'text-laterite'" x-text="JSON.stringify(row.got)"></td></tr></template>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    @include('modules.document_reading._capture')
@endsection
