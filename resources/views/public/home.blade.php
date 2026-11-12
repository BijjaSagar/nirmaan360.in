@extends('layouts.public')
@section('content')
@php
    // Everything drawn in the mocks is sample data. No customer, contractor or supplier here is real.
    $contractors = [
        ['Flooring', 70, 90],
        ['RCC structure', 55, 50],
        ['Plumbing', 30, 30],
    ];
    $leaks = [
        ['rupee', 'Contractor payment', 'Contractors ask for money early and often. By the time 70% of the work is done, 90% of the money has gone. The last 30% gets stuck, the contractor stops coming, and the builder pays extra to get it finished.', 'Paid % sits next to work % for every contractor. The card turns red the moment payment runs ahead.'],
        ['cement', 'Material', 'Cement, steel and copper wire get used beyond what the BOQ allows, or leave the site in a bag, and nobody asks why. A short delivery is only noticed when the pour runs out of sand.', 'BOQ, challans and use are set side by side for every material. A gap is flagged and needs a reason.'],
        ['clock', 'Time', 'Half the days are gone and a third of the work is done, but nobody adds it up until the deadline is close. Then a pipe is found missing after the plaster, and the wall is broken again.', 'Days used sit next to work done, floor by floor, and pending items are listed before the next stage covers them.'],
    ];
    $materials = [
        ['Cement OPC 53', 'bags', '100', '50', '45', '5 bags unaccounted', 'Store count today: 0. Received 50, used 45. Where are the other 5?'],
        ['Copper wire 2.5 sq mm', 'bundles', '40', '44', '44', '4 bundles over BOQ', 'Eight flats should need less wire, not more, as offcuts get reused. A reason is required on this line.'],
        ['TMT steel 12 mm', 'MT', '96.0', '61.5', '58.2', null, 'Stock on site should be 3.3 MT.'],
    ];
    $floors = [
        ['Level 5', 10, null],
        ['Level 4', 35, null],
        ['Level 3', 64, null],
        ['Level 2', 92, '1 pending'],
        ['Level 1', 100, null],
        ['Plinth', 100, null],
    ];
    $stages = ['Slab conduits', 'Wall conduits', 'Wiring', 'Boards', 'Fittings'];
    $matrix = [
        ['Level 4', ['done', 'progress', 'none', 'none', 'none']],
        ['Level 3', ['done', 'done', 'progress', 'none', 'none']],
        ['Level 2', ['done', 'done', 'pending', 'done', 'none']],
        ['Level 1', ['done', 'done', 'done', 'done', 'progress']],
    ];
    $cell = ['done' => 'bg-moss', 'progress' => 'bg-haldi', 'pending' => 'bg-laterite', 'none' => 'border border-dashed border-ink/25'];
    $quotes = [
        ['Contractor A', '₹1,10,000', '+10%', false],
        ['Contractor B', '₹90,000', '−10%', true],
        ['Contractor C', '₹1,95,000', '+95%', false],
    ];
    $roles = [
        'engineer' => ['helmet', 'Site engineer', 'Enter the day before leaving site.', ['Today\'s quantity per BOQ item and floor, in a few taps', 'Challans at the gate, with a photo', 'Pending items with a remark, so nothing gets plastered over', 'Quantities only. Rates and payments never show on your screen']],
        'qs' => ['ruler', 'Estimator / QS', 'Stop re-typing the same quantities.', ['BOQ with CSV and Excel import and export', 'Quotes compared against the BOQ amount', 'Deviation flagged once work passes the BOQ tolerance', 'RA bills built from approved work only']],
        'pm' => ['clipboard', 'Project manager', 'See what is stuck, and with whom.', ['Paid % against work % for every contractor', 'Material gaps waiting for a reason', 'Days used against work done, floor by floor', 'Pending plumbing and electrical items before finishing starts']],
        'owner' => ['chart', 'Owner', 'Know where the money stands without calling site.', ['Budget against total spend', 'Which contractor is paid ahead of work', 'Progress on every active project', 'Full audit log of who changed what']],
        'accounts' => ['rupee', 'Accounts', 'Bills that add up the first time.', ['Challans marked bill pending until the invoice comes', 'Payments by cash, bank or UPI, each against a contract', 'Retention, advance recovery, TDS and GST applied in your contract order', 'Bill PDFs for clients and subcontractors']],
    ];
    // The same four lines as the AI page, so the two pages cannot say different things.
    $agents = app(\App\Services\Website\AiOffer::class)->agents();
    $trial = ['days' => config('buildos.trial_days')];
@endphp

<section>
    <div class="site-wrap pt-14 pb-12 text-center lg:pt-24 lg:pb-16">
        <p class="text-[15px] text-ink-2">{{ __('For builders, contractors and site engineers in India') }}</p>
        <h1 class="animate-rise mx-auto mt-5 max-w-4xl text-[clamp(2.5rem,6.4vw,5.1rem)] leading-[1.06]">{{ __('Three things go untracked on a site.') }}<br>{{ __('Material, contractor payment and time.') }}</h1>
        <p class="mx-auto mt-6 max-w-2xl text-[18px] leading-[1.6] text-ink-2">{{ __('Nirmaan360 checks every bag against the BOQ, every payment against the work actually done, and every day against the plan. The gap shows the day the entry goes in, not when the work gets stuck.') }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="site-btn site-btn-primary px-6 py-3">{{ __('Start a :days-day trial', $trial) }}</a>
            <a href="{{ route('pricing') }}" class="site-btn site-btn-outline px-6 py-3">{{ __('See pricing') }}</a>
        </div>
        <p class="mt-4 text-sm text-ink-3">{{ __('No card needed. English, मराठी and हिन्दी.') }}</p>
    </div>

    <div class="site-panel overflow-hidden bg-sand">
        <div class="mx-auto grid max-w-[1120px] items-end gap-6 px-4 pt-6 sm:px-8 sm:pt-10 lg:grid-cols-[minmax(0,1fr)_232px] lg:gap-8 lg:pt-14">
            <div class="mock rounded-b-none border-b-0">
                <div class="flex items-center gap-3 border-b border-rule bg-paper px-4 py-2.5">
                    <span class="truncate rounded-md border border-rule bg-white px-3 py-0.5 text-[12px] text-ink-3">nirmaan360.in/projects/kalyani-tower-b/control</span>
                </div>
                <div class="flex">
                    <div class="hidden w-12 flex-col items-center gap-4 bg-ink py-4 text-paper/50 sm:flex">
                        <x-icon name="chart" class="size-[18px] text-paper" /><x-icon name="layers" class="size-[18px]" /><x-icon name="rupee" class="size-[18px]" /><x-icon name="cement" class="size-[18px]" /><x-icon name="clock" class="size-[18px]" /><x-icon name="users" class="size-[18px]" />
                    </div>
                    <div class="min-w-0 flex-1 p-4 pb-7 sm:p-6 sm:pb-9">
                        <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div class="min-w-0">
                                <p class="text-[12px] text-ink-3">{{ __('Baner, Pune') }}</p>
                                <p class="truncate font-serif text-[21px] font-medium">Kalyani Tower B</p>
                            </div>
                            <span class="tag-alert"><x-icon name="flag" class="size-3.5" />{{ __('Payment ahead of work') }}</span>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2.5">
                            @foreach ([['Work done', '38%', ''], ['Paid', '46%', 'text-laterite'], ['Time used', '50%', '']] as [$label, $value, $tone])
                                <div class="rounded-lg bg-paper px-3 py-2.5">
                                    <p class="truncate text-[11.5px] text-ink-3">{{ __($label) }}</p>
                                    <p class="num mt-0.5 truncate text-[13px] font-medium sm:text-[17px] {{ $tone }}">{{ $value }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-5">
                            <div class="flex items-baseline justify-between text-[11.5px] text-ink-3"><p>{{ __('Contractors') }}</p><p>{{ __('Work · Paid') }}</p></div>
                            <div class="mt-2 space-y-2.5">
                                @foreach ($contractors as [$name, $work, $paid])
                                    @php $ahead = $paid > $work; @endphp
                                    <div class="flex items-center gap-3">
                                        <p class="w-[98px] shrink-0 truncate text-[13px] font-medium sm:w-28">{{ __($name) }}</p>
                                        <div class="min-w-0 flex-1 space-y-1">
                                            <div class="h-1 rounded-full bg-sand"><div class="h-full rounded-full bg-moss" style="width: {{ $work }}%"></div></div>
                                            <div class="h-1 rounded-full bg-sand"><div class="h-full rounded-full {{ $ahead ? 'bg-laterite' : 'bg-ink/40' }}" style="width: {{ $paid }}%"></div></div>
                                        </div>
                                        <p class="num w-[72px] shrink-0 text-right text-[11.5px] whitespace-nowrap {{ $ahead ? 'font-medium text-laterite' : 'text-ink-2' }}">{{ $work }}% · {{ $paid }}%</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-5">
                            <div class="flex items-baseline justify-between text-[11.5px] text-ink-3"><p>{{ __('Material against BOQ') }}</p><p>{{ __('Received · Used') }}</p></div>
                            <div class="mt-2 divide-y divide-rule/70 rounded-lg border border-rule">
                                @foreach ([['Cement OPC 53', '50 / 45', 'bags', '5 bags unaccounted'], ['TMT steel 12 mm', '61.5 / 58.2', 'MT', null]] as [$name, $figures, $unit, $flag])
                                    <div class="flex items-center justify-between gap-3 px-3 py-2">
                                        <div class="min-w-0"><p class="truncate text-[13px] font-medium">{{ __($name) }}</p><p class="num text-[11.5px] text-ink-3">{{ $figures }} {{ __($unit) }}</p></div>
                                        @if ($flag)<span class="tag-alert shrink-0 text-[11px]">{{ __($flag) }}</span>@else<span class="shrink-0 text-[11.5px] text-ink-3">{{ __('Matches') }}</span>@endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mock hidden rounded-t-[26px] rounded-b-none border-[5px] border-b-0 border-ink p-4 pb-6 lg:block">
                <p class="font-serif text-[16px] font-medium">{{ __('Today\'s progress') }}</p>
                <p class="mt-0.5 truncate text-[11.5px] text-ink-3">{{ __('Level 3, brickwork') }}</p>
                <div class="mt-3 grid grid-cols-2 gap-1 text-center">
                    <div class="rounded-md bg-paper py-1.5"><p class="text-[9.5px] text-ink-3">{{ __('Done today') }}</p><p class="num text-[12px]">120 sqm</p></div>
                    <div class="rounded-md bg-paper py-1.5"><p class="text-[9.5px] text-ink-3">{{ __('Level total') }}</p><p class="num text-[12px]">256 / 400</p></div>
                </div>
                <div class="mt-3 flex items-baseline justify-between"><span class="text-[11.5px] text-ink-3">{{ __('Level 3') }}</span><span class="num text-[17px] font-medium">64%</span></div>
                <div class="mt-1.5 h-1 rounded-full bg-sand"><div class="h-full rounded-full bg-laterite" style="width: 64%"></div></div>
                <p class="mt-3 rounded-md border border-dashed border-ink/25 px-2 py-1.5 text-[11px] leading-snug text-ink-2">{{ __('Remark: passage wall left open for the duct') }}</p>
                <div class="mt-3 rounded-lg bg-ink py-2 text-center text-[12.5px] font-medium text-paper">{{ __('Save progress') }}</div>
            </div>
        </div>
    </div>
    <p class="mt-3 text-center text-xs text-ink-3">{{ __('Sample project data') }}</p>
</section>

@if (site_on('strip'))
<section>
    <div class="site-wrap grid gap-x-10 gap-y-8 py-14 sm:grid-cols-2 lg:grid-cols-4 lg:py-16">
        @foreach ([['rupee', 'Paid against work', 'Red the moment paid % passes work %'], ['cement', 'Material against BOQ', 'Ordered, received and used. A gap needs a reason.'], ['clock', 'Days against progress', 'Time used next to work done, floor by floor'], ['phone', 'Entered on site', 'Quantities in a few taps on the phone, not a sheet typed up at night']] as [$icon, $title, $text])
            <div>
                <x-icon :name="$icon" class="size-7 text-laterite" />
                <p class="mt-3 font-serif text-[21px] font-medium">{{ __($title) }}</p>
                <p class="mt-1 text-[15px] text-ink-2">{{ __($text) }}</p>
            </div>
        @endforeach
    </div>
</section>
@endif

@if (site_on('leaks'))
<section id="product" class="scroll-mt-20">
    <div class="site-wrap border-t border-rule py-16 lg:py-24">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-[clamp(2.1rem,4.4vw,3.5rem)]">{{ __('Where the money goes') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Most builders find out at the halfway mark: a lot of money has gone and nobody can say exactly where. It goes in three places, and all three can be written down.') }}</p>
        </div>
        <div class="mt-12 grid gap-3 lg:grid-cols-3">
            @foreach ($leaks as [$icon, $title, $problem, $answer])
                <div class="rounded-2xl border border-rule bg-white p-6 sm:p-7">
                    <x-icon :name="$icon" class="size-7 text-laterite" />
                    <h3 class="mt-4 text-[24px]">{{ __($title) }}</h3>
                    <p class="mt-2 text-[15.5px] leading-snug text-ink-2">{{ __($problem) }}</p>
                    <p class="mt-5 flex gap-2.5 border-t border-rule pt-4 text-[15px] leading-snug"><x-icon name="check" class="mt-0.5 size-5 text-moss" /><span>{{ __($answer) }}</span></p>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if (site_on('payment'))
<section class="site-panel bg-oat">
    <div class="site-wrap grid items-center gap-12 py-16 lg:grid-cols-2 lg:gap-16 lg:py-24">
        <div class="mock">
            <div class="flex items-end justify-between gap-3 border-b border-rule px-5 py-3.5">
                <div class="min-w-0"><p class="text-[12.5px] text-ink-3">{{ __('Contractor ledger') }}</p><p class="truncate font-serif text-[20px] font-medium">{{ __('Flooring, Tower B') }}</p></div>
                <p class="shrink-0 text-[12px] text-ink-3">{{ __('Sample figures') }}</p>
            </div>
            <div class="px-5 py-4">
                <p class="font-serif text-[clamp(1.6rem,4vw,2rem)] leading-tight font-medium text-laterite">{{ __('Paid 90%, Work 70%') }}</p>
                <p class="mt-1 text-[13.5px] text-ink-2">{{ __('Payment is ahead of work. Finish the work first.') }}</p>
                <div class="mt-4 space-y-2.5">
                    <div><div class="flex justify-between text-[12px] text-ink-3"><span>{{ __('Work done') }}</span><span class="num">70%</span></div><div class="mt-1 h-1.5 rounded-full bg-sand"><div class="h-full rounded-full bg-moss" style="width: 70%"></div></div></div>
                    <div><div class="flex justify-between text-[12px] text-ink-3"><span>{{ __('Paid') }}</span><span class="num text-laterite">90%</span></div><div class="mt-1 h-1.5 rounded-full bg-sand"><div class="h-full rounded-full bg-laterite" style="width: 90%"></div></div></div>
                </div>
            </div>
            <dl class="num border-t border-rule text-[14px]">
                @foreach ([['Contract value', '₹1,00,000', ''], ['Paid till date', '₹90,000', ''], ['Work done', '₹70,000', ''], ['Paid ahead of work', '₹20,000', 'text-laterite'], ['Balance work', '₹30,000', ''], ['Balance money', '₹10,000', 'text-laterite']] as [$label, $value, $tone])
                    <div class="flex justify-between border-b border-rule/70 px-5 py-2.5 {{ $tone }}"><dt class="font-sans">{{ __($label) }}</dt><dd class="font-medium">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <div class="flex items-center justify-between gap-3 bg-paper px-5 py-3 text-[12.5px] text-ink-2"><span>{{ __('Last payment :amount by UPI, receipt attached', ['amount' => '₹15,000']) }}</span><x-icon name="camera" class="size-4 text-ink-3" /></div>
        </div>
        <div>
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('Pay for work done, not for asking.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Every contractor has a contract value from the tender and a ledger of payments: date, amount, cash, bank or UPI, against which item, with the receipt. Next to it sits the work actually done on site. When paid % crosses work %, the card turns red, and you can say it plainly: your payment is ahead, finish the work first.') }}</p>
            <ul class="mt-8 space-y-6">
                @foreach ([['flag', 'Work % against paid %', 'Green while payment is at or below work. Red when it runs ahead. For every contractor and every BOQ item.'], ['wallet', 'Balance work against balance money', 'How much work is left, and how much contract money is left to pay for it.'], ['layers', 'Milestone parts', 'Exact durations cannot be fixed in advance, so define parts, set what each part pays, and payments are tracked against those parts.'], ['calendar', 'Days to the deadline', 'Finishing before Diwali? The card shows how much work is left and how many days remain, in time to push.']] as [$icon, $title, $text])
                    <li class="flex gap-4"><x-icon :name="$icon" class="mt-1 size-6" /><div><h3 class="text-[20px]">{{ __($title) }}</h3><p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
@endif

@if (site_on('material'))
<section>
    <div class="site-wrap grid items-center gap-12 py-16 lg:grid-cols-[1fr_1.1fr] lg:gap-20 lg:py-24">
        <div>
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('Every bag and every bundle, against the BOQ.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Material is ordered in stages against a BOQ item. The challan is entered at the gate with supplier, quantity, vehicle and a photo. If the bill has not come, it sits as bill pending for accounts. Use is entered by stage. Then three numbers are set side by side: what the BOQ allowed, what the challans say arrived, and what was used.') }}</p>
            <div class="mt-8 grid gap-6 border-t border-rule pt-7 sm:grid-cols-2">
                @foreach ([['flag', 'A gap needs a reason', 'BOQ said 100 bags for the slab and 105 were used. The line is flagged and a reason is required.'], ['cement', 'Stock on site', 'Received minus used is what should be in the store. Enter the count and the difference shows.'], ['file', 'Extra work is written down', 'When extra material was genuinely needed, the extra work is recorded, so it does not count as wastage.'], ['truck', 'Every challan on record', 'When a supplier keeps delivering short, you have the dates, quantities and photos to show them.']] as [$icon, $title, $text])
                    <div><x-icon :name="$icon" class="size-6 text-laterite" /><h3 class="mt-3 text-[20px]">{{ __($title) }}</h3><p class="mt-1 text-[15px] leading-snug text-ink-2">{{ __($text) }}</p></div>
                @endforeach
            </div>
        </div>
        <div class="mock">
            <div class="flex items-center justify-between border-b border-rule px-5 py-3.5"><p class="font-serif text-[20px] font-medium">{{ __('Material check') }}</p><p class="text-[12px] text-ink-3">{{ __('Sample figures') }}</p></div>
            <div class="divide-y divide-rule/70">
                @foreach ($materials as [$name, $unit, $boq, $received, $used, $flag, $note])
                    <div class="px-5 py-3.5">
                        <div class="flex items-center justify-between gap-3">
                            <p class="min-w-0 truncate text-[15px] font-medium">{{ __($name) }}</p>
                            @if ($flag)<span class="tag-alert shrink-0">{{ __($flag) }}</span>@else<span class="tag-live shrink-0">{{ __('Matches') }}</span>@endif
                        </div>
                        <div class="num mt-2.5 grid grid-cols-3 gap-2 text-[12.5px]">
                            @foreach ([['BOQ', $boq], ['Received', $received], ['Used', $used]] as [$label, $value])
                                <div class="rounded-md bg-paper px-2.5 py-1.5"><p class="font-sans text-[10.5px] text-ink-3">{{ __($label) }}</p><p class="truncate">{{ $value }} <span class="text-ink-3">{{ __($unit) }}</span></p></div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[12.5px] leading-snug text-ink-2">{{ __($note) }}</p>
                    </div>
                @endforeach
            </div>
            <div class="flex items-center gap-3 bg-paper px-5 py-3 text-[12.5px] text-ink-2"><x-icon name="truck" class="size-4 text-ink-3" />{{ __('Challan 2231: 50 bags, photo attached, bill pending') }}</div>
        </div>
    </div>
</section>
@endif

@if (site_on('progress'))
<section class="site-panel bg-sage">
    <div class="site-wrap grid items-center gap-12 py-16 lg:grid-cols-2 lg:gap-16 lg:py-24">
        <div class="mock">
            <div class="flex items-end justify-between gap-3 border-b border-rule px-5 py-3.5">
                <div class="min-w-0"><p class="text-[12.5px] text-ink-3">{{ __('Progress by floor') }}</p><p class="truncate font-serif text-[20px] font-medium">Kalyani Tower B</p></div>
                <span class="tag-alert">{{ __('Behind time') }}</span>
            </div>
            <div class="grid grid-cols-2 divide-x divide-rule border-b border-rule text-center">
                <div class="px-4 py-3"><p class="text-[11.5px] text-ink-3">{{ __('Time used') }}</p><p class="num text-[22px] font-medium">50%</p><p class="num text-[11.5px] text-ink-3">{{ __(':used of :total days', ['used' => 90, 'total' => 180]) }}</p></div>
                <div class="px-4 py-3"><p class="text-[11.5px] text-ink-3">{{ __('Work done') }}</p><p class="num text-[22px] font-medium text-laterite">38%</p><p class="text-[11.5px] text-ink-3">{{ __('by value of BOQ items') }}</p></div>
            </div>
            <div class="space-y-3 px-5 py-4">
                @foreach ($floors as [$floor, $percent, $pending])
                    <div>
                        <div class="flex items-baseline justify-between gap-3 text-[13.5px]"><p class="font-medium">{{ __($floor) }}</p><p class="num text-[12px] {{ $pending ? 'text-laterite' : 'text-ink-2' }}">{{ $percent }}%@if ($pending) · {{ __($pending) }}@endif</p></div>
                        <div class="mt-1.5 h-1 rounded-full bg-sand"><div class="h-full rounded-full {{ $pending ? 'bg-laterite' : 'bg-moss' }}" style="width: {{ $percent }}%"></div></div>
                    </div>
                @endforeach
            </div>
            <div class="flex items-center gap-3 border-t border-rule bg-paper px-5 py-3 text-[12.5px] text-ink-2"><x-icon name="flag" class="size-4 text-laterite" />{{ __('Level 2: outside passage wiring not done') }}</div>
        </div>
        <div>
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('Work by floor, day by day, against the calendar.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Every BOQ item has its full quantity. Site staff enter what got done today, per floor, in a few taps, with a photo if they want. The app works out the percentage per item, per floor and for the project, and puts the days used next to it. Half the time gone and a third of the work done is a number you want on day 90, not on day 170.') }}</p>
            <ul class="mt-8 space-y-6">
                @foreach ([['floors', 'Floor view', 'Tap a floor to see its percentage and what is still pending there.'], ['message', 'Remarks that stay open', '"Outside passage wiring not done" stays on the pending list until someone closes it.'], ['calendar', 'No pretend schedule', 'Footings, columns, slabs and brickwork overlap, so the app does not invent exact durations. It tracks the parts, what each part pays, and real progress against days.']] as [$icon, $title, $text])
                    <li class="flex gap-4"><x-icon :name="$icon" class="mt-1 size-6" /><div><h3 class="text-[20px]">{{ __($title) }}</h3><p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
@endif

@if (site_on('trades'))
<section>
    <div class="site-wrap grid items-center gap-12 py-16 lg:grid-cols-[1fr_1.1fr] lg:gap-20 lg:py-24">
        <div>
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('Nothing gets plastered over.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Plumbing and electrical work are tracked stage by stage, floor by floor. Core cutting, waterproofing, internal piping, CP fittings, sanitary ware and drainage on one side; slab conduits, wall conduits, wiring, boards and final fittings on the other. Mark a stage done while an earlier one on that floor is pending and the app warns you. Before plaster, POP or paint starts on a floor, it lists every open item there.') }}</p>
            <p class="mt-6 border-l-2 border-laterite pl-4 text-[16px] leading-relaxed text-ink-2">{{ __('The rule on site is simple: no cement work should be pending when painting starts. Breaking a finished wall for one missing pipe costs more than the pipe ever did.') }}</p>
            <div class="mt-8 flex flex-wrap gap-x-8 gap-y-3 text-[15px]">
                <p class="flex items-center gap-2"><x-icon name="drop" class="size-5 text-laterite" />{{ __('Six plumbing stages') }}</p>
                <p class="flex items-center gap-2"><x-icon name="bolt" class="size-5 text-laterite" />{{ __('Five electrical stages') }}</p>
                <p class="flex items-center gap-2"><x-icon name="camera" class="size-5 text-laterite" />{{ __('Remark and photo on every cell') }}</p>
            </div>
        </div>
        <div class="mock">
            <div class="flex items-end justify-between gap-3 border-b border-rule px-5 py-3.5">
                <div class="min-w-0"><p class="text-[12.5px] text-ink-3">{{ __('Electrical stages') }}</p><p class="truncate font-serif text-[20px] font-medium">Kalyani Tower B</p></div>
                <p class="shrink-0 text-[12px] text-ink-3">{{ __('Sample status') }}</p>
            </div>
            <div class="px-4 py-4 sm:px-5">
                <div class="grid grid-cols-[56px_repeat(5,minmax(0,1fr))] items-end gap-1.5 text-[10px] leading-[1.15] text-ink-3 sm:grid-cols-[60px_repeat(5,minmax(0,1fr))] sm:text-[11px]">
                    <p></p>
                    @foreach ($stages as $stage)
                        <p class="text-center break-words">{{ __($stage) }}</p>
                    @endforeach
                    @foreach ($matrix as [$floor, $cells])
                        <p class="self-center text-[12.5px] font-medium text-ink">{{ __($floor) }}</p>
                        @foreach ($cells as $state)
                            <div class="h-8 rounded-md {{ $cell[$state] }}"></div>
                        @endforeach
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-[11.5px] text-ink-2">
                    @foreach ([['done', 'Done'], ['progress', 'In progress'], ['pending', 'Pending'], ['none', 'Not started']] as [$state, $label])
                        <p class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm {{ $cell[$state] }}"></span>{{ __($label) }}</p>
                    @endforeach
                </div>
            </div>
            <div class="flex items-center gap-3 border-t border-rule bg-paper px-5 py-3 text-[12.5px] text-ink-2"><x-icon name="flag" class="size-4 text-laterite" />{{ __('Before POP on Level 2: 1 electrical item pending. Outside passage wiring not done.') }}</div>
        </div>
    </div>
</section>
@endif

@if (site_on('tender'))
<section class="site-panel bg-oat">
    <div class="site-wrap grid items-center gap-12 py-16 lg:grid-cols-2 lg:gap-16 lg:py-24">
        <div class="mock">
            <div class="flex items-end justify-between gap-3 border-b border-rule px-5 py-3.5">
                <div class="min-w-0"><p class="text-[12.5px] text-ink-3">{{ __('Tender') }}</p><p class="truncate font-serif text-[20px] font-medium">{{ __('Flooring, all levels') }}</p></div>
                <div class="shrink-0 text-right"><p class="text-[11.5px] text-ink-3">{{ __('BOQ amount') }}</p><p class="num text-[15px] font-medium">₹1,00,000</p></div>
            </div>
            <div class="divide-y divide-rule/70">
                @foreach ($quotes as [$who, $amount, $diff, $lowest])
                    <div class="flex items-center justify-between gap-3 px-5 py-3 text-[14px]">
                        <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 font-medium"><span>{{ __($who) }}</span>@if ($lowest)<span class="tag-live">{{ __('Lowest') }}</span>@endif</p>
                        <p class="num shrink-0"><span class="{{ $lowest ? 'text-moss' : 'text-laterite' }}">{{ $diff }}</span> <span class="ml-2 font-medium">{{ $amount }}</span></p>
                    </div>
                @endforeach
            </div>
            <div class="px-5 py-4"><div class="rounded-lg bg-ink py-2 text-center text-[12.5px] font-medium text-paper">{{ __('Select Contractor B at ₹90,000') }}</div></div>
            <div class="grid grid-cols-3 divide-x divide-rule border-t border-rule bg-paper text-center">
                @foreach ([['BOQ total', '₹4.85 Cr'], ['Contracted', '₹3.90 Cr'], ['Spent so far', '₹1.62 Cr']] as [$label, $value])
                    <div class="px-2 py-3"><p class="truncate text-[11px] text-ink-3">{{ __($label) }}</p><p class="num truncate text-[14px] font-medium">{{ $value }}</p></div>
                @endforeach
            </div>
        </div>
        <div>
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('The BOQ is the baseline. Everything is checked against it.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Build the BOQ or import it from CSV or Excel: every item from excavation to the last fitting, with quantity, unit, rate and amount. Then collect quotes against it. Each quotation is shown next to the BOQ amount in rupees and percent, with the lowest marked. The quote you pick becomes the contract value that payments are tracked against, and the budget adds up spend against the BOQ total.') }}</p>
            <ul class="mt-8 space-y-6">
                @foreach ([['scale', 'Quotes against the BOQ amount', 'Three contractors quote ₹1,10,000, ₹90,000 and ₹1,95,000 against a BOQ of ₹1,00,000. You see +10%, −10% and +95%, and you decide.'], ['link', 'The chosen rate becomes the contract', 'No re-typing. The selected quotation is the value every later payment is measured against.'], ['wallet', 'Budget against spend', 'The BOQ total is the budget. Payments and material spend add up against it, line by line.']] as [$icon, $title, $text])
                    <li class="flex gap-4"><x-icon :name="$icon" class="mt-1 size-6" /><div><h3 class="text-[20px]">{{ __($title) }}</h3><p class="mt-1 text-[15.5px] leading-snug text-ink-2">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
@endif

@if (site_on('billing'))
<section>
    <div class="site-wrap grid items-center gap-12 py-16 lg:grid-cols-[1.1fr_1fr] lg:gap-20 lg:py-24">
        <div>
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('The bill comes from measured work.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('Measurements are taken as Nos × L × B × D, checked, approved and then locked. An RA bill pulls every approved, unbilled measurement for the period, and retention, advance recovery, TDS and GST follow your contract terms. Approved records cannot be edited; a correction is a reversal entry with a name on it.') }}</p>
            <div class="mt-8 grid gap-6 border-t border-rule pt-7 sm:grid-cols-2">
                @foreach ([['ruler', 'Measurement book', 'Nos × L × B × D with deductions, level by level.'], ['lock', 'Locked once approved', 'Corrections only by reversal, through approval again.'], ['link', 'Client sign-off by link', 'Your client approves or returns the bill without an account.'], ['shield', 'Every change has a name on it', 'Who entered it, who checked it and what they said, all in the audit log.']] as [$icon, $title, $text])
                    <div><x-icon :name="$icon" class="size-6 text-laterite" /><h3 class="mt-3 text-[20px]">{{ __($title) }}</h3><p class="mt-1 text-[15px] leading-snug text-ink-2">{{ __($text) }}</p></div>
                @endforeach
            </div>
        </div>
        <div class="mock">
            <div class="flex items-center justify-between border-b border-rule px-5 py-3.5"><p class="font-serif text-[20px] font-medium">{{ __('RA bill abstract') }}</p><p class="text-[12px] text-ink-3">{{ __('Sample figures') }}</p></div>
            <dl class="num text-[14.5px]">
                <div class="flex justify-between border-b border-rule/70 px-5 py-3"><dt class="font-sans">{{ __('Gross value of work') }}</dt><dd>₹2,50,000.00</dd></div>
                <div class="flex justify-between border-b border-rule/70 px-5 py-3"><dt class="font-sans">{{ __('Retention') }} 5%</dt><dd class="text-laterite">−₹12,500.00</dd></div>
                <div class="flex justify-between border-b border-rule/70 px-5 py-3"><dt class="font-sans">{{ __('Advance recovery') }} 10%</dt><dd class="text-laterite">−₹25,000.00</dd></div>
                <div class="flex justify-between border-b border-rule/70 px-5 py-3"><dt class="font-sans">{{ __('TDS') }} 2%</dt><dd class="text-laterite">−₹5,000.00</dd></div>
                <div class="flex justify-between border-b border-rule/70 px-5 py-3"><dt class="font-sans">{{ __('GST') }} 18%</dt><dd>+₹45,000.00</dd></div>
                <div class="flex items-baseline justify-between bg-ink px-5 py-4 text-paper"><dt class="font-serif text-[19px]">{{ __('Net payable') }}</dt><dd class="text-[18px]">₹2,52,500.00</dd></div>
            </dl>
        </div>
    </div>
</section>
@endif

@if (site_on('field_app'))
<section class="site-panel overflow-hidden bg-ink text-paper">
    <div class="site-wrap grid items-center gap-12 pt-16 lg:grid-cols-2 lg:pt-0">
        <div class="lg:py-24">
            <p class="tag-soon bg-white/10 text-haldi"><x-icon name="phone" class="size-4" />{{ __('Mobile app in testing') }}</p>
            <h2 class="mt-4 text-[clamp(2rem,3.8vw,3rem)]">{{ __('Built for site, where the network drops and hands are dusty.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-paper/70">{{ __('The field app for Android and iPhone saves everything on the phone and syncs when the signal comes back. Until it ships, the web app works in any phone browser.') }}</p>
            <ul class="mt-8 grid gap-5 sm:grid-cols-2">
                @foreach ([['offline', 'Works offline', 'Entries wait on the phone and sync once.'], ['camera', 'Photos on the report', 'Site photos attached to the day.'], ['users', 'Fast hajiri', 'Present, half day or overtime in a tap.'], ['language', 'Three languages', 'English, Marathi and Hindi.']] as [$icon, $title, $text])
                    <li class="flex gap-3"><x-icon :name="$icon" class="mt-0.5 size-5 text-haldi" /><div><p class="font-medium">{{ __($title) }}</p><p class="text-[15px] text-paper/60">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
        </div>
        <div class="flex items-end justify-center gap-5 lg:self-end lg:pt-16">
            <div class="mock w-[232px] rounded-t-[26px] rounded-b-none border-[5px] border-b-0 border-white/15 p-4 pb-6">
                <p class="font-serif text-[16px] font-medium">{{ __('Today\'s attendance') }}</p>
                <div class="mt-3 space-y-2.5">
                    @foreach ([['Dattu Pawar', 'Mason', 'P'], ['Ganesh Kale', 'Bar bender', 'P'], ['Sunil Gaikwad', 'Carpenter', 'H'], ['Anita Bhosale', 'Helper', 'P'], ['Raju Shinde', 'Helper', 'OT']] as [$person, $trade, $mark])
                        <div class="flex items-center justify-between gap-2"><div class="min-w-0"><p class="truncate text-[13px] font-medium">{{ $person }}</p><p class="text-[11px] text-ink-3">{{ __($trade) }}</p></div><span class="grid size-8 shrink-0 place-items-center rounded-lg text-[12px] font-semibold {{ $mark === 'P' ? 'bg-moss text-white' : ($mark === 'H' ? 'bg-haldi text-ink' : 'bg-ink text-paper') }}">{{ $mark }}</span></div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-lg bg-paper py-2 text-center text-[11.5px] text-ink-2">{{ __('Saved on phone. Will sync.') }}</div>
            </div>
            <div class="mock hidden w-[232px] rounded-t-[26px] rounded-b-none border-[5px] border-b-0 border-white/15 p-4 pb-6 sm:block">
                <p class="font-serif text-[16px] font-medium">{{ __('Daily report') }}</p>
                <p class="text-[11px] text-ink-3">{{ __('Tower B') }}</p>
                <div class="mt-3 space-y-2">
                    @foreach ([['Mason', 12], ['Helper', 30], ['Carpenter', 8], ['Bar bender', 6]] as [$trade, $count])
                        <div class="flex items-center justify-between text-[13px]"><span>{{ __($trade) }}</span><span class="flex items-center gap-2"><span class="grid size-6 place-items-center rounded-md bg-paper text-ink-2">−</span><span class="num w-5 text-center">{{ $count }}</span><span class="grid size-6 place-items-center rounded-md bg-paper text-ink-2">+</span></span></div>
                    @endforeach
                </div>
                <div class="mt-3 grid grid-cols-3 gap-1.5"><div class="aspect-square rounded-md bg-sand"></div><div class="aspect-square rounded-md bg-oat"></div><div class="grid aspect-square place-items-center rounded-md border border-dashed border-ink/25 text-ink-3"><x-icon name="camera" class="size-4" /></div></div>
                <div class="mt-3 rounded-lg bg-ink py-2 text-center text-[12.5px] font-medium text-paper">{{ __('Submit DPR') }}</div>
            </div>
        </div>
    </div>
</section>
@endif

@if (site_on('roles'))
<section x-data="{ role: 'engineer' }">
    <div class="site-wrap py-16 lg:py-24">
        <h2 class="mx-auto max-w-3xl text-center text-[clamp(2rem,3.8vw,3rem)]">{{ __('Everyone on the job works from the same numbers.') }}</h2>
        <div class="mt-10 grid gap-6 lg:grid-cols-[260px_1fr]">
            <div class="flex gap-1 overflow-x-auto lg:flex-col" role="tablist">
                @foreach ($roles as $key => [$icon, $label])
                    <button type="button" role="tab" @click="role = '{{ $key }}'" :aria-selected="role === '{{ $key }}'" :class="role === '{{ $key }}' ? 'border-rule bg-white text-ink' : 'border-transparent text-ink-2 hover:bg-sand'" class="flex shrink-0 items-center gap-3 rounded-xl border px-4 py-3 text-left text-[15.5px] font-medium transition-colors"><x-icon :name="$icon" class="size-5" />{{ __($label) }}</button>
                @endforeach
            </div>
            <div class="site-card p-7 sm:p-10">
                @foreach ($roles as $key => [$icon, $label, $headline, $points])
                    <div x-show="role === '{{ $key }}'" @if (! $loop->first) x-cloak @endif>
                        <h3 class="text-[30px] font-normal">{{ __($headline) }}</h3>
                        <ul class="mt-6 grid gap-x-8 gap-y-4 md:grid-cols-2">
                            @foreach ($points as $point)
                                <li class="flex gap-2.5 text-[16px] leading-snug"><x-icon name="check" class="mt-0.5 size-5 text-laterite" />{{ __($point) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
@endif

{{-- AI: the four agents that are built, labelled as early access. The chat is an example with sample data, and asks only what Project Brain can look up. --}}
@if (site_on('ai'))
<section class="site-panel bg-sage">
    <div class="site-wrap grid gap-12 py-16 lg:grid-cols-[1fr_1.15fr] lg:gap-16 lg:py-24">
        <div>
            <p class="tag-soon bg-white/60">{{ __('Early access') }}</p>
            <h2 class="mt-4 text-[clamp(2rem,3.8vw,3rem)]">{{ __('AI agents that do site paperwork, with you approving the result.') }}</h2>
            <p class="mt-4 text-[18px] leading-relaxed text-ink-2">{{ __('These are built on your BOQ, measurements and bills. An agent drafts, and a person approves. Anything that moves money stays with you.') }}</p>
            <div class="mock mt-8 max-w-md p-5">
                <p class="ml-auto w-fit max-w-[85%] rounded-xl bg-ink px-3.5 py-2 text-[14.5px] text-paper">{{ __('Tower B मध्ये या महिन्यात किती काँक्रीट मोजले गेले?') }}</p>
                <p class="mt-3 w-fit max-w-[90%] rounded-xl bg-sand px-3.5 py-2 text-[14.5px] leading-snug">{{ __('186.5 cum measured and approved this month, against 640 cum in the BOQ. The 4 measurement entries are linked below.') }}</p>
                <p class="mt-3 text-xs text-ink-3">{{ __('An example answer, with sample data') }}</p>
            </div>
        </div>
        <div>
            <ul class="divide-y divide-ink/10 border-y border-ink/10">
                @foreach ($agents as [$icon, $name, $text])
                    <li class="flex gap-4 py-4"><span class="grid size-10 shrink-0 place-items-center rounded-lg bg-white/60"><x-icon :name="$icon" /></span><div><h3 class="text-[20px]">{{ __($name) }}</h3><p class="mt-0.5 text-[15px] leading-snug text-ink-2">{{ __($text) }}</p></div></li>
                @endforeach
            </ul>
            <a href="{{ route('ai') }}" class="site-btn site-btn-outline mt-7">{{ __('See the AI agents') }}<x-icon name="arrow" class="size-4" /></a>
        </div>
    </div>
</section>
@endif

@if (site_on('pricing'))
<section>
    <div class="site-wrap py-16 lg:py-24">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('Start free on one site. Pay when you run more.') }}</h2>
            <a href="{{ route('pricing') }}" class="mt-4 inline-block text-[16px] underline underline-offset-4">{{ __('Compare all plans') }}</a>
        </div>
        <div class="mt-10 grid gap-3 md:grid-cols-3">
            @foreach (['free', 'contractor', 'business'] as $key)
                @php $plan = $plans[$key]; @endphp
                <div class="site-card p-7 {{ $key === 'contractor' ? 'border-ink/60' : '' }}">
                    <x-icon :name="'plan-'.$key" class="size-10" />
                    <h3 class="mt-5 text-[28px] font-normal">{{ __($plan['label']) }}</h3>
                    <p class="mt-1 text-[15px] text-ink-2">{{ __($plan['tagline']) }}</p>
                    <p class="mt-6 font-serif text-[40px] leading-none">₹{{ \App\Support\Dec::indian($plan['monthly'], 0) }}</p>
                    <p class="mt-1.5 text-[13.5px] text-ink-3">{{ $key === 'free' ? __('forever') : __('/month').' · '.plan_gst_note() }}</p>
                    <a href="{{ route('register', ['plan' => $key]) }}" class="site-btn {{ $key === 'contractor' ? 'site-btn-primary' : 'site-btn-outline' }} mt-6 w-full">{{ $key === 'free' ? __('Start free') : __('Start a :days-day trial', $trial) }}</a>
                    <p class="mt-5 border-t border-rule pt-4 text-[15px]">{{ $plan['max_projects'] ? trans_choice(':count project|:count projects', $plan['max_projects']) : __('Unlimited projects') }}, {{ $plan['max_users'] ? __(':count users', ['count' => $plan['max_users']]) : __('unlimited users') }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if (site_on('faq'))
<section>
    <div class="site-wrap grid gap-10 border-t border-rule py-16 lg:grid-cols-[1fr_1.6fr] lg:py-24">
        <h2 class="text-[clamp(2rem,3.8vw,3rem)]">{{ __('Questions builders ask us') }}</h2>
        <div class="faq divide-y divide-rule border-y border-rule">
            @foreach ($faqs = [
                ['How much will this save me?', 'We do not promise a number. The engineer who shaped this puts wastage on an untracked site at 5 to 10% of material and aims to bring it to 1 to 2%. That is his estimate, not a measured result, and it depends on entries going in every day. What the app does is show the gap the day the entry goes in.'],
                ['Do I need a card to start?', 'No. You get :days days on the plan you pick, then you move to the free plan unless you decide to buy.'],
                ['Can site staff see rates and payments?', 'No. Site staff enter quantities, photos and remarks. Rates, contract values and payments need the money permission, which you give only to the people who should have it.'],
                ['Does the app plan the schedule for me?', 'No. Site works overlap and exact durations cannot be fixed in advance, so the app does not auto-schedule. You set the total duration and the milestone parts; it tracks real progress against the days.'],
                ['Can I bring in my existing BOQ?', 'Yes. Import a CSV or Excel file with section, code, description, unit, quantity and rate.'],
                ['What if an approved measurement is wrong?', 'Approved records stay locked. You create a reversal entry, it goes through approval, and then you record the right measurement.'],
                ['Does it work in Marathi and Hindi?', 'Yes, you can switch language at any time. The main screens are translated and we are adding the rest.'],
                ['Is there a mobile app?', 'The Android and iPhone app is in testing. Today the full product works in the browser on your phone.'],
            ] as [$question, $answer])
                <details><summary>{{ __($question) }}<x-icon name="plus" class="faq-plus size-5 text-ink-3 transition-transform" /></summary><p class="pb-5 text-[16px] leading-relaxed text-ink-2">{{ __($answer, $trial) }}</p></details>
            @endforeach
        </div>
    </div>
</section>
{{-- The same questions, in the same words, for search engines. --}}
@include('modules.seo.faq', ['faqs' => array_map(fn (array $faq) => [__($faq[0]), __($faq[1], $trial)], $faqs)])
@endif

<section class="site-panel mb-3 overflow-hidden bg-laterite text-paper sm:mb-5">
    <div class="site-wrap pt-16 text-center lg:pt-24">
        <h2 class="mx-auto max-w-3xl text-[clamp(2.25rem,4.6vw,3.75rem)]">{{ __('Start before the next payment goes out.') }}</h2>
        <p class="mt-3 text-[18px] text-paper/85">{{ __('Set up a project and BOQ in an afternoon.') }}</p>
        <a href="{{ route('register') }}" class="site-btn site-btn-light mt-8 px-6 py-3">{{ __('Start a :days-day trial', $trial) }}</a>
    </div>
    <div class="mt-10 h-24 text-paper/25 lg:h-32 [&>svg]:h-full [&>svg]:w-full">@include('public.partials.skyline')</div>
</section>
@endsection
