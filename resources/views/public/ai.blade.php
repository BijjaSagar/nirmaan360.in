@extends('layouts.public')
@section('title', __('AI agents'))
@section('description', __('AI agents for construction paperwork, from daily reports to RA bills, with a person approving every result. Now taking early access requests.'))
@section('content')
@php
    $groups = [
        'On site' => [
            ['message', 'Site Reporter', 'Send a text, voice note or photo on WhatsApp in Marathi, Hindi or English. It becomes a draft DPR, and it asks one question if something is unclear.'],
            ['users', 'Labour and Payroll', 'Spots attendance that looks wrong and drafts wage runs for you to check.'],
            ['shield', 'Safety and Quality', 'Looks through site photos for missing PPE and drafts NCRs.'],
        ],
        'In the office' => [
            ['drawing', 'Takeoff', 'Reads the legend and scale on a PDF drawing, measures it, and tells you what it could not read.'],
            ['layers', 'Estimator', 'Maps quantities to BOQ items and picks DSR, SOR or your own rates.'],
            ['file', 'Tender Scout', 'Watches GeM, CPPP and state portals, and checks eligibility against your company.'],
        ],
        'Money' => [
            ['bill', 'Measurement and Billing', 'Drafts client and subcontractor RA bills from approved measurements.'],
            ['scan', 'Bills and Challan', 'Reads a challan or invoice photo and matches PO, GRN and invoice.'],
            ['rupee', 'Collections', 'Sends payment reminders and logs promises to pay.'],
        ],
    ];
@endphp
<section>
    <div class="site-wrap pt-14 pb-14 text-center lg:pt-24 lg:pb-20">
        <p class="tag-soon">{{ __('Early access') }}</p>
        <h1 class="mx-auto mt-5 max-w-4xl text-[clamp(2.75rem,6.4vw,5rem)] leading-[1.05]">{{ __('The paperwork gets drafted. You still sign it.') }}</h1>
        <p class="mx-auto mt-6 max-w-2xl text-[18px] leading-relaxed text-ink-2">{{ __('These agents are being built on top of the records already in Nirmaan360. None of them are live yet. Tell us which one would save you the most time.') }}</p>
        <a href="{{ route('contact', ['type' => 'ai_early_access']) }}" class="site-btn site-btn-primary mt-8 px-6 py-3">{{ __('Request early access') }}</a>
    </div>
</section>

<section class="site-panel bg-sand">
    <div class="site-wrap py-8 lg:py-12">
        @foreach ($groups as $group => $agents)
            <div class="grid gap-6 py-9 lg:grid-cols-[200px_1fr] {{ $loop->first ? '' : 'border-t border-ink/10' }}">
                <h2 class="text-[30px]">{{ __($group) }}</h2>
                <ul class="grid gap-x-10 gap-y-7 md:grid-cols-3">
                    @foreach ($agents as [$icon, $name, $text])
                        <li><x-icon :name="$icon" class="size-7 text-laterite" /><h3 class="mt-3 text-[20px]">{{ __($name) }}</h3><p class="mt-1 text-[15px] leading-snug text-ink-2">{{ __($text) }}</p></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>

<section class="site-panel mt-3 mb-3 bg-sage sm:mt-5 sm:mb-5">
    <div class="site-wrap grid gap-10 py-16 lg:grid-cols-2 lg:py-24">
        <h2 class="max-w-md text-[clamp(2rem,3.8vw,3rem)]">{{ __('You decide how much an agent can do.') }}</h2>
        <ol class="space-y-5">
            @foreach ([['Advise', 'It answers and recommends. Nothing changes.'], ['Draft', 'It prepares a record for a person to submit. This is the default.'], ['Act with approval', 'It does the job after one tap from you.'], ['Act within limits', 'It handles routine work inside limits you set, then reports back.']] as $index => [$level, $text])
                <li class="flex gap-4"><span class="num grid size-8 shrink-0 place-items-center rounded-full bg-white/70 text-sm">{{ $index + 1 }}</span><div><h3 class="text-[20px]">{{ __($level) }}</h3><p class="text-[15.5px] text-ink-2">{{ __($text) }}</p></div></li>
            @endforeach
            <li class="flex gap-4 border-t border-ink/10 pt-5"><x-icon name="lock" class="mt-0.5 size-6" /><p class="text-[16px] font-medium">{{ __('Payments, bill approvals and approved measurements always need a person.') }}</p></li>
        </ol>
    </div>
</section>
@endsection
