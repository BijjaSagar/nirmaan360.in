@extends('layouts.public')
@section('title', __('Site to bill, on one record'))
@section('content')
@php
    $stages = [
        ['Tender', 'Contacts today. Tender pipeline next.', false],
        ['BOQ', 'Build it or import a CSV. Every later figure hangs off these lines.', true],
        ['Plan', 'Projects and sites today. Gantt and WBS next.', false],
        ['Site', 'Daily reports, photos, issues, attendance and petty cash.', true],
        ['Measure', 'Nos × L × B × D, checked level by level.', true],
        ['Bill', 'Client and subcontractor RA bills with GST.', true],
        ['Pay', 'Mark bills paid today. Ledger and Tally sync next.', false],
        ['Close', 'Final bill and retention release next.', false],
    ];
    $schedule = [
        ['1.1', 'BOQ and item ledger', 'CSV import and export. Executed, measured, approved, billed and balance on every line.', true],
        ['1.2', 'Daily progress reports', 'Manpower, machinery, work done against BOQ items, site photos and approval.', true],
        ['1.3', 'Issues and snags', 'Priority, assignee, due date and status.', true],
        ['1.4', 'Labour attendance', 'Daily hajiri for own and contractor labour: present, half day, overtime.', true],
        ['1.5', 'Petty cash', 'A wallet for each person, top-ups, and expenses with a bill photo.', true],
        ['1.6', 'Measurement book', 'Deductions, multi-level approval, deviation flags, and correction only by reversal.', true],
        ['1.7', 'Work orders and RA bills', 'Previous, this bill and cumulative. Retention, advance, TDS and GST. PDF download.', true],
        ['1.8', 'Client sign-off', 'Send a signed link. The client approves or returns the bill without an account.', true],
        ['2.1', 'Materials and procurement', 'Indent, RFQ, PO, GRN and site stock.', false],
        ['2.2', 'Tally sync and GST reports', 'Vouchers, ledgers and GSTR-1 data.', false],
        ['2.3', 'WhatsApp site reporting', 'Send a DPR by text, voice or photo in Marathi, Hindi or English.', false],
        ['2.4', 'Offline field app', 'Android app that keeps working without a network. The sync API is ready.', false],
        ['2.5', 'AI takeoff and drawing review', 'Quantities from PDF drawings, with a person accepting every figure.', false],
    ];
    $ledger = [['BOQ', '100.000'], ['Executed', '68.000'], ['Measured', '63.000'], ['Approved', '50.000'], ['Billed', '50.000'], ['Balance', '37.000']];
@endphp

<section class="graph-paper overflow-hidden bg-neel-deep text-paper">
    <div class="mx-auto grid max-w-7xl items-center gap-14 px-5 pt-12 pb-24 lg:grid-cols-[1.12fr_1fr] lg:pt-16 lg:pb-32">
        <div>
            <p class="eyebrow animate-rise text-haldi">{{ __('Sheet 01 · Site to bill') }}</p>
            <h1 class="animate-rise mt-4 text-[clamp(3.5rem,8.6vw,7.25rem)] leading-[0.9] [animation-delay:80ms]">
                {{ __('One BOQ line.') }}<br><span class="text-haldi">{{ __('Tender to final bill.') }}</span>
            </h1>
            <p lang="hi" class="animate-rise mt-5 font-display text-[26px] font-medium text-paper/90 [animation-delay:160ms]">निर्माण का पूरा हिसाब, एक जगह।</p>
            <p class="animate-rise mt-4 max-w-xl text-lg leading-relaxed text-paper/75 [animation-delay:220ms]">{{ __('Nirmaan360 keeps your BOQ, daily reports, measurement book and RA bills on one record. The quantity your engineer measures on site is the quantity your client is billed.') }}</p>
            <div class="animate-rise mt-7 flex flex-wrap gap-3 [animation-delay:300ms]">
                <a href="{{ route('register') }}" class="btn btn-primary px-5 py-2.5 text-base">{{ __('Start a 30-day trial') }}</a>
                <a href="{{ route('calculators') }}" class="btn border-paper/40 px-5 py-2.5 text-base text-paper hover:bg-white/10">{{ __('Try the free calculators') }}</a>
            </div>
        </div>

        <div class="animate-rise relative [animation-delay:260ms] lg:rotate-[1.4deg]">
            <div class="rounded-[3px] bg-sheet text-ink shadow-[0_30px_70px_-20px_rgb(0_0_0/0.7)]">
                <div class="flex items-end justify-between border-b-2 border-ink px-5 py-3">
                    <div>
                        <p class="eyebrow text-ink-3">{{ __('Measurement book') }}</p>
                        <p class="font-display text-[26px] font-semibold">{{ __('Slab, Tower B, Level 4') }}</p>
                    </div>
                    <p class="num text-xs text-ink-3">{{ __('Sample entry') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[420px] text-sm">
                        <thead>
                        <tr class="eyebrow border-b border-rule text-[10px] text-ink-3">
                            <th class="px-5 py-2 text-left font-normal">{{ __('Location') }}</th>
                            <th class="px-2 py-2 text-right font-normal">{{ __('Nos') }}</th>
                            <th class="px-2 py-2 text-right font-normal">L</th>
                            <th class="px-2 py-2 text-right font-normal">B</th>
                            <th class="px-2 py-2 text-right font-normal">D</th>
                            <th class="px-5 py-2 text-right font-normal">{{ __('Qty') }}</th>
                        </tr>
                        </thead>
                        <tbody class="num">
                        <tr class="border-b border-rule"><td class="px-5 py-2.5 font-sans">{{ __('Panel S1') }}</td><td class="px-2 text-right">2</td><td class="px-2 text-right">10.000</td><td class="px-2 text-right">5.000</td><td class="px-2 text-right">0.500</td><td class="px-5 text-right">50.000</td></tr>
                        <tr class="border-b border-rule"><td class="px-5 py-2.5 font-sans">{{ __('Panel S2') }}</td><td class="px-2 text-right">1</td><td class="px-2 text-right">6.000</td><td class="px-2 text-right">5.000</td><td class="px-2 text-right">0.500</td><td class="px-5 text-right">15.000</td></tr>
                        <tr class="border-b border-rule text-laterite"><td class="px-5 py-2.5 font-sans">{{ __('Less: lift opening') }}</td><td class="px-2 text-right">1</td><td class="px-2 text-right">2.000</td><td class="px-2 text-right">2.000</td><td class="px-2 text-right">0.500</td><td class="px-5 text-right">-2.000</td></tr>
                        <tr class="font-medium"><td colspan="5" class="px-5 py-3 text-right font-sans">{{ __('Total') }}</td><td class="px-5 text-right text-base whitespace-nowrap">63.000 cum</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="relative grid grid-cols-3 border-t-2 border-ink text-[13px]">
                    <div class="border-r border-rule px-5 py-3"><p class="eyebrow text-[10px] text-ink-3">{{ __('Item') }}</p><p class="num">2.1 RCC M25</p></div>
                    <div class="border-r border-rule px-4 py-3"><p class="eyebrow text-[10px] text-ink-3">BOQ</p><p class="num">100.000</p></div>
                    <div class="px-4 py-3"><p class="eyebrow text-[10px] text-ink-3">{{ __('Balance') }}</p><p class="num">37.000</p></div>
                </div>
            </div>
            <span class="animate-stamp absolute -top-5 right-4 rounded-[3px] border-[3px] border-laterite bg-sheet/90 px-3 py-0.5 font-display text-[28px] font-bold tracking-widest text-laterite [animation-delay:900ms] sm:right-10">{{ __('APPROVED') }}</span>
        </div>
    </div>
</section>

<section id="how" class="border-b border-rule">
    <div class="mx-auto max-w-7xl px-5 py-20">
        <p class="eyebrow text-laterite">{{ __('How it works') }}</p>
        <h2 class="mt-3 max-w-3xl text-[clamp(2.5rem,5vw,4rem)]">{{ __('Eight stages. One record.') }}</h2>
        <p class="mt-3 max-w-2xl text-lg text-ink-2">{{ __('A BOQ line is quantified, executed, measured, approved and billed in one place, so nobody has to re-key a figure or argue over which sheet is right.') }}</p>

        <div class="mt-12 grid gap-px bg-rule sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($stages as $index => [$stage, $note, $live])
                <div class="bg-paper p-5">
                    <div class="flex items-center justify-between">
                        <span class="num text-sm text-ink-3">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="stamp {{ $live ? 'border-moss text-moss' : 'border-ink-3 text-ink-3' }}">{{ $live ? __('Live') : __('Next') }}</span>
                    </div>
                    <h3 class="mt-4 text-[34px]">{{ __($stage) }}</h3>
                    <p class="mt-1 text-[15px] leading-snug text-ink-2">{{ __($note) }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-14">
            <p class="eyebrow text-ink-3">{{ __('One line, every figure · 2.1 RCC M25, cum · sample') }}</p>
            <div class="dim-line mt-4 text-ink"></div>
            <div class="mt-4 grid grid-cols-3 gap-y-6 sm:grid-cols-6">
                @foreach ($ledger as [$label, $value])
                    <div>
                        <p class="eyebrow text-[10px] text-ink-3">{{ __($label) }}</p>
                        <p class="num mt-1 text-[clamp(1.25rem,2.4vw,2rem)] {{ $label === 'Balance' ? 'text-laterite' : '' }}">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="bg-paper-2/60">
    <div class="mx-auto grid max-w-7xl gap-12 px-5 py-20 lg:grid-cols-2">
        <div>
            <p class="eyebrow text-laterite">{{ __('RA billing') }}</p>
            <h2 class="mt-3 text-[clamp(2.5rem,5vw,4rem)]">{{ __('The bill writes itself from approved work.') }}</h2>
            <dl class="mt-8 space-y-6">
                <div class="border-l-2 border-laterite pl-4">
                    <dt class="font-display text-2xl font-semibold">{{ __('Approved means locked') }}</dt>
                    <dd class="text-ink-2">{{ __('An approved measurement or bill cannot be edited. Corrections are made with a reversal entry that goes through approval again.') }}</dd>
                </div>
                <div class="border-l-2 border-laterite pl-4">
                    <dt class="font-display text-2xl font-semibold">{{ __('One measurement, one bill') }}</dt>
                    <dd class="text-ink-2">{{ __('Once a measurement is on a bill it cannot appear on another. Cancel an unapproved bill and it is free again.') }}</dd>
                </div>
                <div class="border-l-2 border-laterite pl-4">
                    <dt class="font-display text-2xl font-semibold">{{ __('Your contract, your order') }}</dt>
                    <dd class="text-ink-2">{{ __('Retention, advance recovery and TDS follow the terms set on each project or work order.') }}</dd>
                </div>
            </dl>
        </div>

        <div class="ruled-paper self-center rounded-[3px] border border-rule bg-sheet shadow-sm">
            <div class="border-b-2 border-ink bg-sheet px-6 py-3">
                <p class="eyebrow text-ink-3">{{ __('Sample figures') }}</p>
                <p class="font-display text-[26px] font-semibold">{{ __('RA bill abstract') }}</p>
            </div>
            <dl class="num bg-sheet/80 text-[15px]">
                <div class="flex justify-between border-b border-rule px-6 py-3"><dt class="font-sans">{{ __('Gross value of work') }}</dt><dd>₹2,50,000.00</dd></div>
                <div class="flex justify-between border-b border-rule px-6 py-3"><dt class="font-sans">{{ __('Retention') }} 5%</dt><dd class="text-laterite">−₹12,500.00</dd></div>
                <div class="flex justify-between border-b border-rule px-6 py-3"><dt class="font-sans">{{ __('Advance recovery') }} 10%</dt><dd class="text-laterite">−₹25,000.00</dd></div>
                <div class="flex justify-between border-b border-rule px-6 py-3"><dt class="font-sans">{{ __('TDS') }} 2%</dt><dd class="text-laterite">−₹5,000.00</dd></div>
                <div class="flex justify-between border-b border-rule px-6 py-3"><dt class="font-sans">{{ __('GST') }} 18%</dt><dd>+₹45,000.00</dd></div>
                <div class="flex items-baseline justify-between bg-ink px-6 py-4 text-paper"><dt class="font-display text-2xl font-semibold">{{ __('Net payable') }}</dt><dd class="text-xl">₹2,52,500.00</dd></div>
            </dl>
        </div>
    </div>
</section>

<section id="schedule" class="border-b border-rule">
    <div class="mx-auto max-w-7xl px-5 py-20">
        <p class="eyebrow text-laterite">{{ __('Schedule of items') }}</p>
        <h2 class="mt-3 text-[clamp(2.5rem,5vw,4rem)]">{{ __('What is in the book today') }}</h2>
        <div class="mt-8 overflow-x-auto rounded-[3px] border border-rule bg-sheet">
            <table class="ledger min-w-[680px]">
                <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Work') }}</th><th>{{ __('Includes') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($schedule as [$item, $work, $includes, $live])
                    <tr>
                        <td class="num text-xs">{{ $item }}</td>
                        <td class="font-semibold whitespace-nowrap">{{ __($work) }}</td>
                        <td class="text-ink-2">{{ __($includes) }}</td>
                        <td><span class="stamp {{ $live ? 'border-moss text-moss' : 'border-ink-3 text-ink-3' }}">{{ $live ? __('Live') : __('Next') }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

<section id="plans">
    <div class="mx-auto max-w-7xl px-5 py-20">
        <p class="eyebrow text-laterite">{{ __('Plans') }}</p>
        <h2 class="mt-3 text-[clamp(2.5rem,5vw,4rem)]">{{ __('From one engineer to a full contractor.') }}</h2>
        <div class="mt-10 grid gap-px bg-ink/80 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Free / Site', 'Free', '1 project · 3 users', 'Daily reports, issues, measurement book, attendance and petty cash.'],
                ['Contractor', '30-day trial', '5 projects · 25 users', 'Everything in Free, plus work orders, RA bills and contacts.'],
                ['Business', 'Pricing at launch', 'Unlimited projects and users', 'Everything in Contractor, plus API access.'],
                ['Enterprise / Infra', 'Pricing at launch', 'Unlimited projects and users', 'Everything in Business. SSO and a dedicated database are planned.'],
            ] as [$plan, $price, $limits, $features])
                <div class="flex flex-col bg-sheet p-6">
                    <h3 class="text-[30px]">{{ __($plan) }}</h3>
                    <p class="num mt-2 text-sm text-laterite">{{ __($price) }}</p>
                    <p class="mt-4 text-sm font-semibold">{{ __($limits) }}</p>
                    <p class="mt-1 flex-1 text-[15px] text-ink-2">{{ __($features) }}</p>
                    <a href="{{ route('register') }}" class="btn btn-ghost mt-6">{{ __('Start free') }}</a>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="graph-paper bg-laterite-dark text-paper">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-5 py-14">
        <div>
            <h2 class="text-[clamp(2.25rem,4.5vw,3.5rem)]">{{ __('Need a quick quantity?') }}</h2>
            <p class="mt-1 text-lg text-paper/80">{{ __('Concrete, brickwork, plaster, steel, tiles, paint, cut and fill. Free, with no sign-up.') }}</p>
        </div>
        <a href="{{ route('calculators') }}" class="btn border-paper bg-paper px-5 py-2.5 text-base text-ink hover:bg-sheet">{{ __('Open calculators') }}</a>
    </div>
</section>
@endsection
