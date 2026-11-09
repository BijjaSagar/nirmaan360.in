@extends('layouts.public')
@section('title', __('Site to bill, on one record'))
@section('content')
@php
    $stages = [
        ['file', 'Tender', 'Clients and subcontractors today. Tender pipeline next.', false],
        ['layers', 'BOQ', 'Build it or import a CSV. Every later figure hangs off these lines.', true],
        ['calendar', 'Plan', 'Projects and sites today. Gantt and WBS next.', false],
        ['helmet', 'Site', 'Daily reports, photos, issues, attendance and petty cash.', true],
        ['ruler', 'Measure', 'Nos × L × B × D, checked level by level.', true],
        ['bill', 'Bill', 'Client and subcontractor RA bills, with or without GST.', true],
        ['rupee', 'Pay', 'Mark bills paid today. Ledger and Tally sync next.', false],
        ['stamp', 'Close', 'Final bill and retention release next.', false],
    ];
    $ledger = [
        ['1.1', 'Earthwork in excavation', '1,850.000', '1,620.000', 88],
        ['2.1', 'RCC M25, slabs and beams', '620.000', '410.000', 66],
        ['2.2', 'TMT steel Fe500D', '96.000', '58.500', 61],
        ['2.3', 'AAC block masonry', '410.000', '120.000', 29],
    ];
    $roles = [
        'engineer' => ['helmet', 'Site engineer', 'Log the day before leaving site.', ['Manpower, machinery and work done against BOQ items', 'Site photos on the daily report', 'Hajiri for own and contractor labour', 'Measurements with the quantity worked out for you']],
        'qs' => ['ruler', 'Estimator / QS', 'Stop re-typing the same quantities.', ['BOQ with CSV import and export', 'Check or return measurements with a remark', 'Deviation flagged once work passes the BOQ tolerance', 'RA bills built from approved work only']],
        'pm' => ['clipboard', 'Project manager', 'See what is stuck, and with whom.', ['One inbox for everything waiting on you', 'Issues with priority, owner and due date', 'BOQ line ledger: executed, approved, billed, balance', 'Work orders with item rates for subcontractors']],
        'owner' => ['chart', 'Owner', 'Know where the project stands without calling site.', ['Contract value, billed amount and open issues', 'Progress on every active project', 'Full audit log of who changed what', 'Roles and approval chains set by you']],
        'accounts' => ['rupee', 'Accounts', 'Bills that add up the first time.', ['Retention, advance recovery, TDS and GST applied in your contract order', 'Petty cash wallets with bill photos', 'Bill PDFs for clients and subcontractors', 'Signed link for client sign-off']],
    ];
    $agents = [
        ['message', 'Site Reporter', 'Turns a WhatsApp voice note in Marathi, Hindi or English into a draft DPR.'],
        ['scan', 'Bills and Challan', 'Reads a challan photo and matches it against the PO and GRN.'],
        ['chart', 'Project Brain', 'Answers "how much steel went to Tower B this month?" with links to the records.'],
        ['drawing', 'Takeoff', 'Measures areas and lengths from PDF drawings and shows what it could not read.'],
        ['bill', 'Measurement and Billing', 'Drafts RA bills at month end and flags deviations and extra items.'],
        ['users', 'Owner\'s Copilot', 'A morning brief on cash, billing due, delays and stock-outs.'],
    ];
@endphp

{{-- Hero: white, with the product doing the talking --}}
<section class="relative overflow-hidden">
    <div class="site-wrap grid items-center gap-14 pt-14 pb-20 lg:grid-cols-[1fr_1.18fr] lg:gap-10 lg:pt-20 lg:pb-28">
        <div class="relative z-10">
            <p class="text-[15px] font-semibold text-laterite">{{ __('For contractors, builders and consultants in India') }}</p>
            <h1 class="animate-rise mt-4 text-[clamp(2.75rem,6.1vw,4.75rem)]">{{ __('One BOQ line.') }}<br>{{ __('Tender to final bill.') }}</h1>
            <p class="mt-6 max-w-[34rem] text-[19px] leading-[1.55] text-ink-2">{{ __('Nirmaan360 keeps your BOQ, daily reports, measurement book and RA bills on one record. The quantity your engineer measures on site is the quantity your client is billed.') }}</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="site-btn site-btn-primary px-6 py-3">{{ __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}<x-icon name="arrow" class="size-4" /></a>
                <a href="{{ route('pricing') }}" class="site-btn site-btn-outline px-6 py-3">{{ __('See pricing') }}</a>
            </div>
            <p class="mt-4 text-sm text-ink-3">{{ __('No card needed. English, मराठी and हिन्दी.') }}</p>
        </div>

        <div class="relative">
            <div class="mock lg:mr-[-7rem]">
                <div class="flex items-center gap-3 border-b border-black/8 bg-stone-50 px-4 py-2.5">
                    <span class="truncate rounded border border-black/8 bg-white px-3 py-0.5 text-[12px] text-ink-3">nirmaan360.in/projects/kalyani-tower-b/boq</span>
                </div>
                <div class="flex">
                    <div class="hidden w-12 flex-col items-center gap-4 bg-neel-deep py-4 text-white/55 sm:flex">
                        <x-icon name="chart" class="size-[18px] text-white" /><x-icon name="layers" class="size-[18px]" /><x-icon name="helmet" class="size-[18px]" /><x-icon name="ruler" class="size-[18px]" /><x-icon name="bill" class="size-[18px]" /><x-icon name="users" class="size-[18px]" />
                    </div>
                    <div class="min-w-0 flex-1 p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[12px] text-ink-3">{{ __('Baner, Pune') }}</p>
                                <p class="text-[17px] font-bold">Kalyani Tower B</p>
                            </div>
                            <span class="tag-live"><x-icon name="check" class="size-3.5" />{{ __('On track') }}</span>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2.5">
                            @foreach ([['Contract value', '₹4.85 Cr'], ['Approved work', '₹1.32 Cr'], ['Waiting on you', '3']] as [$label, $value])
                                <div class="rounded-md bg-stone-50 px-3 py-2.5">
                                    <p class="truncate text-[11.5px] text-ink-3">{{ __($label) }}</p>
                                    <p class="num mt-0.5 text-[15px] font-medium sm:text-[17px]">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 space-y-3 md:pl-32">
                            @foreach ($ledger as [$code, $name, $boq, $approved, $percent])
                                <div>
                                    <div class="flex items-baseline justify-between gap-3 text-[13px]">
                                        <p class="min-w-0 truncate"><span class="num text-[11.5px] text-ink-3">{{ $code }}</span> <span class="font-medium">{{ __($name) }}</span></p>
                                        <p class="num shrink-0 text-[11.5px] text-ink-2">{{ $approved }} / {{ $boq }}</p>
                                    </div>
                                    <div class="mt-1 h-1.5 rounded-full bg-stone-100"><div class="h-full rounded-full bg-neel" style="width: {{ $percent }}%"></div></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="mock absolute -bottom-12 -left-8 hidden w-[218px] rounded-[20px] border-4 border-[#12181c] p-3.5 md:block">
                <p class="text-[12px] font-bold">{{ __('New measurement') }}</p>
                <p class="mt-0.5 truncate text-[11px] text-ink-3">{{ __('Level 4, panel S1') }}</p>
                <div class="mt-2.5 grid grid-cols-4 gap-1 text-center">
                    @foreach ([['Nos', '2'], ['L', '10.0'], ['B', '5.0'], ['D', '0.5']] as [$label, $value])
                        <div class="rounded bg-stone-50 py-1.5"><p class="text-[9.5px] text-ink-3">{{ $label }}</p><p class="num text-[12px]">{{ $value }}</p></div>
                    @endforeach
                </div>
                <div class="mt-2.5 flex items-baseline justify-between"><span class="text-[11px] text-ink-3">{{ __('Quantity') }}</span><span class="num text-[17px] font-medium">50.000 cum</span></div>
                <div class="mt-2.5 rounded-md bg-laterite py-1.5 text-center text-[12px] font-semibold text-white">{{ __('Send for approval') }}</div>
            </div>
            <p class="mt-3 text-right text-xs text-ink-3 lg:mr-[-7rem]">{{ __('Sample project data') }}</p>
        </div>
    </div>
    <div class="h-24 text-black/10 [&>svg]:h-full [&>svg]:w-full">@include('public.partials.skyline')</div>
</section>

@if (site_on('strip'))
<section class="bg-[#12181c] text-white">
    <div class="site-wrap grid gap-x-8 gap-y-6 py-9 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['ruler', 'Measurement book', 'Nos × L × B × D with deductions'], ['bill', 'RA billing', 'Previous, this bill, cumulative'], ['language', 'Your language', 'English, Marathi and Hindi'], ['lock', 'Locked once approved', 'Corrections only by reversal']] as [$icon, $title, $text])
            <div class="flex items-start gap-3">
                <x-icon :name="$icon" class="mt-0.5 size-6 text-haldi" />
                <div><p class="font-semibold">{{ __($title) }}</p><p class="text-[14.5px] text-white/60">{{ __($text) }}</p></div>
            </div>
        @endforeach
    </div>
</section>

@endif

@if (site_on('stages'))
<section id="product" class="scroll-mt-20">
    <div class="site-wrap py-20 lg:py-24">
        <div class="max-w-2xl">
            <h2 class="text-[clamp(2rem,4vw,3rem)]">{{ __('Eight stages. One record.') }}</h2>
            <p class="mt-4 text-lg leading-relaxed text-ink-2">{{ __('A BOQ line is quantified, executed, measured, approved and billed in one place, so nobody has to re-key a figure or argue over which sheet is right.') }}</p>
        </div>
        <ol class="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($stages as $index => [$icon, $stage, $note, $live])
                <li class="border-t-2 {{ $live ? 'border-laterite' : 'border-stone-200' }} pt-4">
                    <div class="flex items-center justify-between">
                        <x-icon :name="$icon" class="size-7 {{ $live ? 'text-laterite' : 'text-ink-3' }}" />
                        @if ($live)
                            <span class="tag-live"><x-icon name="check" class="size-3.5" />{{ __('Live') }}</span>
                        @else
                            <span class="text-[12.5px] font-semibold text-ink-3">{{ __('Next') }}</span>
                        @endif
                    </div>
                    <h3 class="mt-3 text-[21px]">{{ $index + 1 }}. {{ __($stage) }}</h3>
                    <p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __($note) }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

@endif

@if (site_on('measurement'))
<section class="bg-[#eaf1f6]">
    <div class="site-wrap grid items-center gap-12 py-20 lg:grid-cols-2 lg:gap-16 lg:py-24">
        <div class="relative">
            <div class="mock">
                <div class="flex items-end justify-between border-b-2 border-ink px-5 py-3.5">
                    <div><p class="text-[12.5px] text-ink-3">{{ __('Measurement book') }}</p><p class="text-[18px] font-bold">{{ __('Slab, Tower B, Level 4') }}</p></div>
                    <p class="text-[12px] text-ink-3">{{ __('Sample entry') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[400px] text-[13.5px]">
                        <thead><tr class="border-b border-black/8 text-[11.5px] text-ink-3"><th class="px-5 py-2 text-left font-medium">{{ __('Location') }}</th><th class="px-2 text-right font-medium">{{ __('Nos') }}</th><th class="px-2 text-right font-medium">L</th><th class="px-2 text-right font-medium">B</th><th class="px-2 text-right font-medium">D</th><th class="px-5 text-right font-medium">{{ __('Qty') }}</th></tr></thead>
                        <tbody class="num">
                            <tr class="border-b border-black/5"><td class="px-5 py-2.5 font-sans">{{ __('Panel S1') }}</td><td class="px-2 text-right">2</td><td class="px-2 text-right">10.000</td><td class="px-2 text-right">5.000</td><td class="px-2 text-right">0.500</td><td class="px-5 text-right">50.000</td></tr>
                            <tr class="border-b border-black/5"><td class="px-5 py-2.5 font-sans">{{ __('Panel S2') }}</td><td class="px-2 text-right">1</td><td class="px-2 text-right">6.000</td><td class="px-2 text-right">5.000</td><td class="px-2 text-right">0.500</td><td class="px-5 text-right">15.000</td></tr>
                            <tr class="border-b border-black/5 text-laterite"><td class="px-5 py-2.5 font-sans">{{ __('Less: lift opening') }}</td><td class="px-2 text-right">1</td><td class="px-2 text-right">2.000</td><td class="px-2 text-right">2.000</td><td class="px-2 text-right">0.500</td><td class="px-5 text-right">-2.000</td></tr>
                            <tr class="font-medium"><td colspan="5" class="px-5 py-3 text-right font-sans">{{ __('Total') }}</td><td class="px-5 text-right whitespace-nowrap">63.000 cum</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <span class="animate-stamp absolute -top-5 right-6 rounded border-[3px] border-moss bg-white/90 px-3 py-0.5 text-[20px] font-extrabold tracking-[0.12em] text-moss">{{ __('APPROVED') }}</span>
        </div>
        <div>
            <h2 class="text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('A measurement book that holds up in a client meeting.') }}</h2>
            <ul class="mt-7 space-y-5">
                @foreach ([['stamp', 'Approved means locked', 'An approved measurement or bill cannot be edited. Corrections are made with a reversal entry that goes through approval again.'], ['flag', 'Deviations show up on their own', 'Go past the BOQ quantity and tolerance, and the entry is flagged for the project manager.'], ['shield', 'Every change has a name on it', 'Who entered it, who checked it and what they said, all in the audit log.']] as [$icon, $title, $text])
                    <li class="flex gap-4"><x-icon :name="$icon" class="mt-0.5 size-6 text-neel" /><div><h3 class="text-[18px]">{{ __($title) }}</h3><p class="mt-1 text-[16px] leading-snug text-ink-2">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
        </div>
    </div>
</section>

@endif

@if (site_on('billing'))
<section>
    <div class="site-wrap grid items-center gap-12 py-20 lg:grid-cols-[1.1fr_1fr] lg:gap-20 lg:py-24">
        <div>
            <h2 class="text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('The bill writes itself from approved work.') }}</h2>
            <p class="mt-4 text-lg leading-relaxed text-ink-2">{{ __('Pick the period and Nirmaan360 pulls every approved, unbilled measurement. Retention, advance recovery and TDS follow the terms on your project or work order.') }}</p>
            <div class="mt-7 grid gap-5 sm:grid-cols-2">
                <div class="flex gap-3"><x-icon name="layers" class="size-6 text-laterite" /><div><h3 class="text-[17px]">{{ __('One measurement, one bill') }}</h3><p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __('Once it is on a bill it cannot appear on another.') }}</p></div></div>
                <div class="flex gap-3"><x-icon name="link" class="size-6 text-laterite" /><div><h3 class="text-[17px]">{{ __('Client sign-off by link') }}</h3><p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __('Your client approves or returns the bill without an account.') }}</p></div></div>
            </div>
        </div>
        <div class="mock">
            <div class="flex items-center justify-between border-b border-black/8 px-5 py-3.5"><p class="font-bold">{{ __('RA bill abstract') }}</p><p class="text-[12px] text-ink-3">{{ __('Sample figures') }}</p></div>
            <dl class="num text-[14.5px]">
                <div class="flex justify-between border-b border-black/5 px-5 py-3"><dt class="font-sans">{{ __('Gross value of work') }}</dt><dd>₹2,50,000.00</dd></div>
                <div class="flex justify-between border-b border-black/5 px-5 py-3"><dt class="font-sans">{{ __('Retention') }} 5%</dt><dd class="text-laterite">−₹12,500.00</dd></div>
                <div class="flex justify-between border-b border-black/5 px-5 py-3"><dt class="font-sans">{{ __('Advance recovery') }} 10%</dt><dd class="text-laterite">−₹25,000.00</dd></div>
                <div class="flex justify-between border-b border-black/5 px-5 py-3"><dt class="font-sans">{{ __('TDS') }} 2%</dt><dd class="text-laterite">−₹5,000.00</dd></div>
                <div class="flex justify-between border-b border-black/5 px-5 py-3"><dt class="font-sans">{{ __('GST') }} 18%</dt><dd>+₹45,000.00</dd></div>
                <div class="flex items-baseline justify-between bg-neel-deep px-5 py-4 text-white"><dt class="font-sans text-[17px] font-bold">{{ __('Net payable') }}</dt><dd class="text-[18px]">₹2,52,500.00</dd></div>
            </dl>
        </div>
    </div>
</section>

@endif

@if (site_on('field_app'))
<section class="overflow-hidden bg-neel-deep text-white">
    <div class="site-wrap grid items-center gap-12 py-20 lg:grid-cols-2 lg:py-0">
        <div class="lg:py-24">
            <p class="tag-soon text-haldi"><x-icon name="phone" class="size-4" />{{ __('Mobile app in testing') }}</p>
            <h2 class="mt-3 text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('Built for site, where the network drops and hands are dusty.') }}</h2>
            <p class="mt-4 text-lg leading-relaxed text-white/70">{{ __('The field app for Android and iPhone saves everything on the phone and syncs when the signal comes back. Until it ships, the web app works in any phone browser.') }}</p>
            <ul class="mt-7 grid gap-4 sm:grid-cols-2">
                @foreach ([['offline', 'Works offline', 'Entries wait on the phone and sync once.'], ['camera', 'Photos on the report', 'Site photos attached to the day.'], ['users', 'Fast hajiri', 'Present, half day or overtime in a tap.'], ['language', 'Three languages', 'English, Marathi and Hindi.']] as [$icon, $title, $text])
                    <li class="flex gap-3"><x-icon :name="$icon" class="mt-0.5 size-5 text-haldi" /><div><p class="font-semibold">{{ __($title) }}</p><p class="text-[15px] text-white/60">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
        </div>
        <div class="flex justify-center gap-5 lg:pt-16">
            <div class="mock w-[230px] translate-y-6 rounded-t-[28px] rounded-b-none border-[6px] border-b-0 border-black/60 p-4 lg:translate-y-12">
                <p class="text-[13px] font-bold">{{ __('Today\'s attendance') }}</p>
                <div class="mt-3 space-y-2.5">
                    @foreach ([['Dattu Pawar', 'Mason', 'P'], ['Ganesh Kale', 'Bar bender', 'P'], ['Sunil Gaikwad', 'Carpenter', 'H'], ['Anita Bhosale', 'Helper', 'P'], ['Raju Shinde', 'Helper', 'OT']] as [$person, $trade, $mark])
                        <div class="flex items-center justify-between gap-2"><div class="min-w-0"><p class="truncate text-[13px] font-medium">{{ $person }}</p><p class="text-[11px] text-ink-3">{{ __($trade) }}</p></div><span class="grid size-8 shrink-0 place-items-center rounded-md text-[12px] font-bold {{ $mark === 'P' ? 'bg-moss text-white' : ($mark === 'H' ? 'bg-haldi text-ink' : 'bg-neel text-white') }}">{{ $mark }}</span></div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-md bg-stone-100 py-2 text-center text-[11.5px] text-ink-2">{{ __('Saved on phone. Will sync.') }}</div>
            </div>
            <div class="mock hidden w-[230px] rounded-t-[28px] rounded-b-none border-[6px] border-b-0 border-black/60 p-4 sm:block">
                <p class="text-[13px] font-bold">{{ __('Daily report') }}</p>
                <p class="text-[11px] text-ink-3">{{ __('Tower B') }}</p>
                <div class="mt-3 space-y-2">
                    @foreach ([['Mason', 12], ['Helper', 30], ['Carpenter', 8], ['Bar bender', 6]] as [$trade, $count])
                        <div class="flex items-center justify-between text-[13px]"><span>{{ __($trade) }}</span><span class="flex items-center gap-2"><span class="grid size-6 place-items-center rounded bg-stone-100 text-ink-2">−</span><span class="num w-5 text-center">{{ $count }}</span><span class="grid size-6 place-items-center rounded bg-stone-100 text-ink-2">+</span></span></div>
                    @endforeach
                </div>
                <div class="mt-3 grid grid-cols-3 gap-1.5"><div class="aspect-square rounded bg-stone-200"></div><div class="aspect-square rounded bg-stone-200"></div><div class="grid aspect-square place-items-center rounded border border-dashed border-black/20 text-ink-3"><x-icon name="camera" class="size-4" /></div></div>
                <div class="mt-3 rounded-md bg-laterite py-2 text-center text-[12.5px] font-semibold text-white">{{ __('Submit DPR') }}</div>
            </div>
        </div>
    </div>
</section>

@endif

@if (site_on('roles'))
<section class="bg-[#f6f2ea]" x-data="{ role: 'engineer' }">
    <div class="site-wrap py-20 lg:py-24">
        <h2 class="max-w-2xl text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('Everyone on the job works from the same numbers.') }}</h2>
        <div class="mt-9 grid gap-8 lg:grid-cols-[280px_1fr]">
            <div class="flex gap-1 overflow-x-auto lg:flex-col" role="tablist">
                @foreach ($roles as $key => [$icon, $label])
                    <button type="button" role="tab" @click="role = '{{ $key }}'" :aria-selected="role === '{{ $key }}'" :class="role === '{{ $key }}' ? 'bg-white text-ink shadow-sm' : 'text-ink-2 hover:bg-white/60'" class="flex shrink-0 items-center gap-3 rounded-md px-4 py-3 text-left text-[16px] font-semibold transition-colors"><x-icon :name="$icon" class="size-5" />{{ __($label) }}</button>
                @endforeach
            </div>
            <div class="rounded-lg bg-white p-7 sm:p-9">
                @foreach ($roles as $key => [$icon, $label, $headline, $points])
                    <div x-show="role === '{{ $key }}'" @if (! $loop->first) x-cloak @endif>
                        <h3 class="text-[25px]">{{ __($headline) }}</h3>
                        <ul class="mt-5 grid gap-x-8 gap-y-3.5 md:grid-cols-2">
                            @foreach ($points as $point)
                                <li class="flex gap-2.5 text-[16px] leading-snug"><x-icon name="check" class="mt-0.5 size-5 text-moss" />{{ __($point) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

@endif

{{-- AI: clearly labelled as early access --}}
@if (site_on('ai'))
<section>
    <div class="site-wrap grid gap-12 py-20 lg:grid-cols-[1fr_1.15fr] lg:gap-16 lg:py-24">
        <div>
            <p class="tag-soon">{{ __('Early access') }}</p>
            <h2 class="mt-2 text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('AI agents that do site paperwork, with you approving the result.') }}</h2>
            <p class="mt-4 text-lg leading-relaxed text-ink-2">{{ __('We are building these on top of your BOQ, measurements and bills. An agent drafts, and a person approves. Anything that moves money stays with you.') }}</p>
            <div class="mock mt-8 max-w-md p-5">
                <p class="ml-auto w-fit max-w-[85%] rounded-lg bg-neel px-3.5 py-2 text-[14.5px] text-white">{{ __('Tower B ला या महिन्यात किती स्टील दिले?') }}</p>
                <p class="mt-3 w-fit max-w-[90%] rounded-lg bg-stone-100 px-3.5 py-2 text-[14.5px] leading-snug">{{ __('42.6 MT issued this month, against 58.5 MT measured to date. 3 issue slips are linked below.') }}</p>
                <p class="mt-3 text-xs text-ink-3">{{ __('Preview of a planned feature, with sample data') }}</p>
            </div>
        </div>
        <div>
            <ul class="divide-y divide-black/8 border-y border-black/8">
                @foreach ($agents as [$icon, $name, $text])
                    <li class="flex gap-4 py-4"><span class="grid size-10 shrink-0 place-items-center rounded-md bg-[#e8f1ec] text-moss"><x-icon :name="$icon" /></span><div><h3 class="text-[17px]">{{ __($name) }}</h3><p class="mt-0.5 text-[15.5px] leading-snug text-ink-2">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
            <a href="{{ route('ai') }}" class="site-btn site-btn-outline mt-7">{{ __('See the AI roadmap') }}<x-icon name="arrow" class="size-4" /></a>
        </div>
    </div>
</section>

@endif

@if (site_on('pricing'))
<section class="bg-[#f6f2ea]">
    <div class="site-wrap py-20 lg:py-24">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <h2 class="max-w-xl text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('Start free on one site. Pay when you run more.') }}</h2>
            <a href="{{ route('pricing') }}" class="font-semibold text-laterite hover:underline">{{ __('Compare all plans') }}</a>
        </div>
        <div class="mt-9 grid gap-5 md:grid-cols-3">
            @foreach (['free', 'contractor', 'business'] as $key)
                @php $plan = $plans[$key]; @endphp
                <div class="rounded-lg bg-white p-7 {{ $key === 'contractor' ? 'ring-2 ring-laterite' : '' }}">
                    <h3 class="text-[22px]">{{ __($plan['label']) }}</h3>
                    <p class="mt-1 text-[15px] text-ink-2">{{ __($plan['tagline']) }}</p>
                    <p class="mt-5"><span class="text-[34px] font-extrabold tracking-tight">₹{{ \App\Support\Dec::indian($plan['monthly'], 0) }}</span> <span class="text-ink-3">{{ $key === 'free' ? __('forever') : __('/month').' · '.plan_gst_note() }}</span></p>
                    <p class="mt-3 text-[15px] font-medium">{{ $plan['max_projects'] ? trans_choice(':count project|:count projects', $plan['max_projects']) : __('Unlimited projects') }}, {{ $plan['max_users'] ? __(':count users', ['count' => $plan['max_users']]) : __('unlimited users') }}</p>
                    <a href="{{ route('register', ['plan' => $key]) }}" class="site-btn {{ $key === 'contractor' ? 'site-btn-primary' : 'site-btn-outline' }} mt-6 w-full">{{ $key === 'free' ? __('Start free') : __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}</a>
                </div>
            @endforeach
        </div>
    </div>
</section>

@endif

@if (site_on('faq'))
<section>
    <div class="site-wrap grid gap-10 py-20 lg:grid-cols-[1fr_1.6fr] lg:py-24">
        <h2 class="text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('Questions contractors ask us') }}</h2>
        <div class="faq divide-y divide-black/10 border-y border-black/10">
            @foreach ([
                ['Do I need a card to start?', 'No. You get :days days on the plan you pick, then you move to the free plan unless you decide to buy.'],
                ['Can I bring in my existing BOQ?', 'Yes. Import a CSV with section, code, description, unit, quantity and rate. Excel import is on the way.'],
                ['What if an approved measurement is wrong?', 'Approved records stay locked. You create a reversal entry, it goes through approval, and then you record the right measurement.'],
                ['Does it work in Marathi and Hindi?', 'Yes, you can switch language at any time. The main screens are translated and we are adding the rest.'],
                ['Is there a mobile app?', 'The Android and iPhone app is in testing. Today the full product works in the browser on your phone.'],
                ['Can my client approve a bill?', 'Yes. Add the client as an approval level and send a signed link. They do not need an account.'],
            ] as [$question, $answer])
                <details><summary>{{ __($question) }}<x-icon name="plus" class="faq-plus size-5 text-ink-3 transition-transform" /></summary><p class="pb-5 text-[16.5px] leading-relaxed text-ink-2">{{ __($answer, ['days' => config('buildos.trial_days')]) }}</p></details>
            @endforeach
        </div>
    </div>
</section>

@endif

<section class="bg-laterite text-white">
    <div class="site-wrap flex flex-wrap items-center justify-between gap-6 py-14">
        <div>
            <h2 class="text-[clamp(1.75rem,3.4vw,2.5rem)]">{{ __('Put your next RA bill on Nirmaan360.') }}</h2>
            <p class="mt-2 text-lg text-white/85">{{ __('Set up a project and BOQ in an afternoon.') }}</p>
        </div>
        <a href="{{ route('register') }}" class="site-btn site-btn-light px-6 py-3">{{ __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}<x-icon name="arrow" class="size-4" /></a>
    </div>
</section>
@endsection
