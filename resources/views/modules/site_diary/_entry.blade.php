{{--
    One entry of the register with the figures the app works out for it. $entry comes from
    HindranceRegister::entries(). Every figure here is a count of calendar days or of reports.
    A notice date is shown only for an entry that counts, and only from confirmed contract terms.
--}}
@php
    $hindrance = $entry['hindrance'];
    $reports = $entry['reports'];
    $period = $hindrance->isOngoing()
        ? __('From :from, still going on', ['from' => $hindrance->started_on->format('d M Y')])
        : __(':from to :to', ['from' => $hindrance->started_on->format('d M Y'), 'to' => $hindrance->ended_on->format('d M Y')]);
    $near = (int) config('modules.site_diary.remind_days_before');
@endphp
<article id="hindrance-{{ $hindrance->id }}" class="card">
    <div class="card-head flex-wrap gap-2">
        <h3 class="card-title">{{ __($categories[$hindrance->category] ?? $hindrance->category) }}</h3>
        <div class="flex flex-wrap items-center gap-2">
            @if ($hindrance->isOngoing() && $hindrance->status !== \App\Models\Hindrance::DISMISSED)
                <span class="stamp border-neel text-neel">{{ __('Still going on') }}</span>
            @endif
            @if ($hindrance->isLocked())
                <span class="stamp border-ink-3 text-ink-3">{{ __('Cited in a letter') }}</span>
            @endif
            @if ($entry['corrected_by'])
                <span class="stamp border-ink-3 text-ink-3">{{ __('Corrected by a later entry') }}</span>
            @endif
            @if ($hindrance->status === \App\Models\Hindrance::CONFIRMED)
                <span class="stamp border-moss text-moss">{{ __($statuses['confirmed']) }}</span>
            @elseif ($hindrance->status === \App\Models\Hindrance::PROPOSED)
                <span class="stamp border-haldi text-[#8a6208]">{{ __($statuses['proposed']) }}</span>
            @else
                <span class="stamp border-ink-3 text-ink-3">{{ __($statuses[$hindrance->status] ?? $hindrance->status) }}</span>
            @endif
        </div>
    </div>

    <div class="space-y-3 px-4 py-3 text-sm">
        <p class="whitespace-pre-line">{{ $hindrance->description }}</p>

        @if ($hindrance->supersedes && $hindrance->status !== \App\Models\Hindrance::DISMISSED)
            <p class="text-ink-3">{{ __('This entry corrects the entry for :period that a letter cites.', ['period' => $hindrance->supersedes->isOngoing()
                ? __('From :from, still going on', ['from' => $hindrance->supersedes->started_on->format('d M Y')])
                : __(':from to :to', ['from' => $hindrance->supersedes->started_on->format('d M Y'), 'to' => $hindrance->supersedes->ended_on->format('d M Y')])]) }}</p>
        @endif

        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <div><dt class="eyebrow text-ink-3">{{ __('Period') }}</dt><dd class="num">{{ $period }}</dd></div>
            <div>
                <dt class="eyebrow text-ink-3">{{ __('Calendar days, both ends counted') }}</dt>
                <dd class="num">{{ $hindrance->isOngoing() ? __(':period up to today', ['period' => $days($entry['days'])]) : $days($entry['days']) }}</dd>
            </div>
            <div><dt class="eyebrow text-ink-3">{{ __('Site') }}</dt><dd>{{ $hindrance->site?->name ?? __('Whole project') }}</dd></div>
            <div><dt class="eyebrow text-ink-3">{{ __('Who is responsible, as entered') }}</dt><dd>{{ $hindrance->responsible ?? '—' }}</dd></div>
            <div>
                <dt class="eyebrow text-ink-3">{{ __('Daily reports cited') }}</dt>
                <dd>{{ __(':cited cited, :approved approved, :editable can still be edited', ['cited' => $reports['cited'], 'approved' => $reports['approved'], 'editable' => $reports['editable']]) }}</dd>
            </div>
            <div>
                <dt class="eyebrow text-ink-3">{{ __('Days of the period') }}</dt>
                <dd>{{ __(':cited with a cited report, :none with no daily report at all', ['cited' => $entry['days_with_cited_report'], 'none' => $entry['days_without_report']]) }}</dd>
            </div>
            <div>
                @if ($hindrance->status === \App\Models\Hindrance::CONFIRMED)
                    <dt class="eyebrow text-ink-3">{{ __('Confirmed by') }}</dt>
                    <dd>{{ $hindrance->confirmedBy?->name ?? '—' }} · <span class="num">{{ $hindrance->confirmed_at?->format('d M Y') }}</span></dd>
                @else
                    <dt class="eyebrow text-ink-3">{{ __('Put forward by') }}</dt>
                    <dd>{{ match ($hindrance->proposed_by) { \App\Models\Hindrance::BY_AI => __('The AI, from the daily reports'), \App\Models\Hindrance::BY_CODE => __('The app, from the daily reports'), default => __('A person') } }}</dd>
                @endif
            </div>
        </dl>

        @if ($reports['changed'] > 0)
            <p class="text-[#8a6208]">{{ __('Reports that say something else now than when they were cited: :count. The entry keeps the words as they were. Change the entry and save it to take the words as they are now.', ['count' => $reports['changed']]) }}</p>
        @endif
        @if ($reports['gone'] > 0)
            <p class="text-[#8a6208]">{{ __('Cited reports that have been removed since: :count. The entry keeps the copy of what they said.', ['count' => $reports['gone']]) }}</p>
        @endif
        @if ($reports['outside'] > 0)
            <p class="text-ink-3">{{ __('Cited reports dated outside the period: :count.', ['count' => $reports['outside']]) }}</p>
        @endif
    </div>

    <details class="border-t border-rule">
        <summary class="flex min-h-11 cursor-pointer items-center px-4 py-2 text-sm font-medium">{{ __('What the reports said') }}</summary>
        @foreach ($entry['sources'] as $row)
            <div class="border-t border-rule px-4 py-2 text-sm">
                <p class="flex flex-wrap items-center gap-2">
                    @if (! $row['gone'] && auth()->user()->can('dpr.view') && Route::has('dprs.show'))
                        <a href="{{ route('dprs.show', $row['source']->dpr_id) }}" class="link num font-semibold">{{ $row['source']->report_date->format('d M Y') }}</a>
                    @else
                        <span class="num font-semibold">{{ $row['source']->report_date->format('d M Y') }}</span>
                    @endif
                    <x-stamp :status="$row['status']" />
                    @if ($row['gone'])
                        <span class="text-xs text-[#8a6208]">{{ __('Report removed') }}</span>
                    @elseif ($row['changed'])
                        <span class="text-xs text-[#8a6208]">{{ __('Says something else now') }}</span>
                    @endif
                </p>
                <p class="mt-1 whitespace-pre-line text-ink-2">{{ $row['source']->excerpt }}</p>
            </div>
        @endforeach
    </details>

    @if ($entry['counts'])
        <div class="border-t border-rule px-4 py-3 text-sm">
            <p class="eyebrow text-ink-3">{{ __('Notice dates') }}</p>

            @forelse ($entry['deadlines'] as $deadline)
                @php
                    $left = $deadline->daysLeft($today);
                    $when = match (true) {
                        $left === null => null,
                        $left > 1 => __('in :days days', ['days' => $left]),
                        $left === 1 => __('tomorrow'),
                        $left === 0 => __('today'),
                        $left === -1 => __('yesterday'),
                        default => __(':days days ago', ['days' => abs($left)]),
                    };
                    $tone = match (true) {
                        $left === null => 'text-ink-3',
                        $entry['cited'] => 'text-ink',
                        $left < 0 => 'text-laterite',
                        $left <= $near => 'text-[#8a6208]',
                        default => 'text-ink',
                    };
                    $name = __($termOptions['codes']['notice'][$deadline->code] ?? $deadline->code);
                @endphp
                <div class="mt-2 border-b border-rule pb-2 last:border-b-0">
                    <p class="font-semibold {{ $tone }}">
                        {{ $name }}:
                        @if ($deadline->state === \App\Services\SiteDiary\NoticeDeadline::KNOWN)
                            <span class="num">{{ $deadline->date->format('d M Y') }}</span> · {{ $when }}
                        @elseif ($deadline->state === \App\Services\SiteDiary\NoticeDeadline::NOT_LATER_THAN)
                            {{ __('not later than :date', ['date' => $deadline->date->format('d M Y')]) }} · {{ $when }}
                        @elseif ($deadline->state === \App\Services\SiteDiary\NoticeDeadline::OPEN)
                            {{ __('cannot be worked out yet') }}
                        @else
                            {{ __('cannot be worked out') }}
                        @endif
                    </p>

                    @foreach ($deadline->parts as $part)
                        <p class="text-ink-2">
                            {{ $text->value($part->term) }} ·
                            {{ __('Counted from: :start', ['start' => __($termOptions['counted_from']['notice'][$part->countedFrom] ?? $part->countedFrom)]) }}.
                            @if ($part->date)
                                {{ __('Counted from :from, this gives :date.', ['from' => $part->from->format('d M Y'), 'date' => $part->date->format('d M Y')]) }}
                            @elseif ($part->missing === \App\Services\SiteDiary\NoticePart::NO_END)
                                {{ __('The hindrance is still going on, so there is no day to count from yet.') }}
                            @else
                                {{ __('No confirmed stipulated completion date covers the day this hindrance started, so there is no day to count from.') }}
                            @endif
                        </p>
                        {{-- What was entered, with its dates. The clause a person typed for it goes to people who may open Contract terms. --}}
                        <p class="text-xs text-ink-3">
                            {{ __('As entered under Contract terms') }} · {{ $text->period($part->term) }}
                            @if ($termsLink)
                                · {{ __('Clause or page in the agreement') }}: {{ $part->term->source_ref ?? '—' }}
                            @endif
                        </p>
                    @endforeach

                    @if ($deadline->combine)
                        <p class="text-ink-2">{{ __('Entered as periods that go together: :choice.', ['choice' => __($termOptions['combine'][$deadline->combine] ?? $deadline->combine)]) }}</p>
                    @endif
                    @if ($deadline->state === \App\Services\SiteDiary\NoticeDeadline::NOT_LATER_THAN)
                        <p class="text-ink-2">{{ __('One of the periods cannot be worked out yet, so the date may come sooner than the one shown.') }}</p>
                    @endif
                    @if ($deadline->state === \App\Services\SiteDiary\NoticeDeadline::UNCLEAR)
                        <p class="text-[#8a6208]">{{ __('More than one confirmed notice period of this name covers the day this hindrance started, and they are not entered as one group, or one of them is incomplete. The app cannot tell which applies. Correct them under Contract terms.') }}</p>
                    @endif
                </div>
            @empty
                @if ($noticeTerms['confirmed'] > 0)
                    <p class="mt-1 text-ink-3">{{ __('No confirmed notice period covers :date, the day this hindrance started. Check the dates under Contract terms.', ['date' => $hindrance->started_on->format('d M Y')]) }}</p>
                @else
                    <p class="mt-1 text-ink-3">{{ __('None. No notice period is confirmed for this contract.') }}</p>
                @endif
            @endforelse

            @if ($entry['deadlines'] !== [])
                <p class="mt-2 text-xs text-ink-3">{{ __('The app added the days of each period, as it was entered, to the day shown. It did not read your agreement. Check every date against the agreement before you rely on it.') }}</p>
                @if ($termsLink)
                    <p class="mt-1 text-xs"><a href="{{ $termsLink }}" class="link">{{ __('Contract terms') }}</a></p>
                @endif
            @endif
        </div>
    @endif

    @if ($canManage)
        <div class="flex flex-wrap items-center gap-3 border-t border-rule bg-paper/50 px-4 py-3">
            @if ($hindrance->isLocked())
                <p class="min-w-0 flex-1 text-xs text-ink-3">{{ __('A finalised letter cites this entry, so it cannot be changed. A correction is made as a new entry.') }}</p>
                @if ($canMake && ! $entry['corrected_by'])
                    <a href="{{ route('site-diary.hindrances.index', [$project, 'correct' => $hindrance->id]) }}#entry-form" class="btn btn-ghost min-h-11">{{ __('Correct this entry') }}</a>
                @endif
            @else
                @if ($canMake)
                    <a href="{{ route('site-diary.hindrances.index', [$project, 'edit' => $hindrance->id]) }}#entry-form" class="btn btn-ghost min-h-11">{{ __('Change this entry') }}</a>
                @endif
                @if ($hindrance->status !== \App\Models\Hindrance::CONFIRMED)
                    <form method="POST" action="{{ route('site-diary.hindrances.confirm', $hindrance) }}">
                        @csrf
                        <button class="btn btn-primary min-h-11">{{ $hindrance->status === \App\Models\Hindrance::DISMISSED ? __('Bring back and confirm') : __('Confirm') }}</button>
                    </form>
                @endif
                @if ($hindrance->status !== \App\Models\Hindrance::DISMISSED)
                    <form method="POST" action="{{ route('site-diary.hindrances.dismiss', $hindrance) }}" onsubmit="return confirm(@js(__('Dismiss this entry? It stays in the register and is no longer counted.')))">
                        @csrf
                        <button class="link text-sm text-laterite">{{ __('Dismiss') }}</button>
                    </form>
                @endif
            @endif
        </div>
    @endif
</article>
