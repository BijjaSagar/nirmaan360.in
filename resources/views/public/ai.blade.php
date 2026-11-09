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
<section class="bg-[#12181c] text-white">
    <div class="site-wrap grid items-end gap-10 py-20 lg:grid-cols-[1.4fr_1fr] lg:py-24">
        <div>
            <p class="font-semibold text-haldi">{{ __('Early access') }}</p>
            <h1 class="mt-3 text-[clamp(2.5rem,5.4vw,4.25rem)]">{{ __('The paperwork gets drafted. You still sign it.') }}</h1>
        </div>
        <div>
            <p class="text-lg leading-relaxed text-white/70">{{ __('These agents are being built on top of the records already in Nirmaan360. None of them are live yet. Tell us which one would save you the most time.') }}</p>
            <a href="{{ route('contact', ['type' => 'ai_early_access']) }}" class="site-btn site-btn-primary mt-6">{{ __('Request early access') }}<x-icon name="arrow" class="size-4" /></a>
        </div>
    </div>
</section>

<section>
    <div class="site-wrap py-20 lg:py-24">
        @foreach ($groups as $group => $agents)
            <div class="grid gap-6 border-t border-black/10 py-9 lg:grid-cols-[220px_1fr]">
                <h2 class="text-[24px]">{{ __($group) }}</h2>
                <ul class="grid gap-x-10 gap-y-7 md:grid-cols-3">
                    @foreach ($agents as [$icon, $name, $text])
                        <li><x-icon :name="$icon" class="size-7 text-moss" /><h3 class="mt-3 text-[18px]">{{ __($name) }}</h3><p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __($text) }}</p></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>

<section class="bg-[#e8f1ec]">
    <div class="site-wrap grid gap-10 py-20 lg:grid-cols-2 lg:py-24">
        <h2 class="max-w-md text-[clamp(1.9rem,3.6vw,2.75rem)]">{{ __('You decide how much an agent can do.') }}</h2>
        <ol class="space-y-5">
            @foreach ([['Advise', 'It answers and recommends. Nothing changes.'], ['Draft', 'It prepares a record for a person to submit. This is the default.'], ['Act with approval', 'It does the job after one tap from you.'], ['Act within limits', 'It handles routine work inside limits you set, then reports back.']] as $index => [$level, $text])
                <li class="flex gap-4"><span class="num grid size-8 shrink-0 place-items-center rounded-full bg-white text-sm">{{ $index + 1 }}</span><div><h3 class="text-[18px]">{{ __($level) }}</h3><p class="text-[16px] text-ink-2">{{ __($text) }}</p></div></li>
            @endforeach
            <li class="flex gap-4 border-t border-black/10 pt-5"><x-icon name="lock" class="mt-0.5 size-6 text-moss" /><p class="text-[16px] font-medium">{{ __('Payments, bill approvals and approved measurements always need a person.') }}</p></li>
        </ol>
    </div>
</section>
@endsection
