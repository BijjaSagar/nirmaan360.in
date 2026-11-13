{{--
    "Suggest groups": the AI reads the hindrance notes of daily reports that no entry cites yet and proposes
    entries from them. $grouping comes from HindranceSuggestions::panel(). What it proposes waits under
    "Waiting to be confirmed" and counts for nothing until a person confirms it.
--}}
@php
    $latest = $grouping['latest'];
    $waiting = $latest?->isWaiting() ?? false;
@endphp
@if ($grouping['offered'] || $latest)
    <section id="grouping" class="card mb-5">
        <div class="card-head"><h2 class="card-title">{{ __('Suggest groups with the AI') }}</h2></div>
        <div class="space-y-2 px-4 py-3 text-sm">
            @if ($waiting)
                <p role="status">{{ __('The AI is grouping the notes of :count daily reports. This page looks again by itself.', ['count' => count($latest->reportIds())]) }}</p>
                {{-- Looks again while the form is closed, so nothing typed in it is lost. --}}
                <div x-data x-init="const look = () => form ? setTimeout(look, 5000) : location.reload(); setTimeout(look, 5000)"></div>
            @elseif ($latest?->status === \App\Models\HindranceSuggestion::DONE)
                <p role="status">
                    @if ($latest->proposed === 0)
                        {{ __('The last request proposed no entry.') }}
                    @else
                        {{ __('The last request proposed :count entries. They are listed under Waiting to be confirmed.', ['count' => $latest->proposed]) }}
                    @endif
                </p>
                @if ($latest->left_out > 0)
                    <p class="text-ink-3">{{ __('Parts of the answer that could not be tied to the words of a report were left out: :count.', ['count' => $latest->left_out]) }}</p>
                @endif
            @elseif ($latest?->status === \App\Models\HindranceSuggestion::FAILED)
                <p class="text-[#8a6208]" role="status">{{ $grouping['reason'] }}</p>
            @endif

            @if ($grouping['offered'] && ! $waiting)
                <p class="text-ink-2">{{ __('The AI reads the hindrance notes of daily reports that no entry cites yet and proposes entries from them. A proposed entry waits for you to confirm, change or dismiss it, and counts for nothing until you confirm it. Its dates are the dates of its reports, never the AI\'s.') }}</p>
                <p class="text-ink-3">{{ __('The notes are sent to the AI service as written, with phone numbers, e-mail addresses, GSTINs and account numbers taken out.') }}</p>

                @if (! $grouping['consent'])
                    <p class="text-[#8a6208]">{{ __('Nothing can be sent until an owner or admin switches on the AI document readers for your company.') }}</p>
                    @can('settings.manage')
                        <a href="{{ route('ai-documents.edit') }}" class="link">{{ __('AI document readers') }}</a>
                    @endcan
                @elseif ($grouping['reports'] === 0)
                    <p class="text-ink-3">{{ __('Every daily report with a hindrance note is already cited by an entry.') }}</p>
                @else
                    <form method="POST" action="{{ route('site-diary.hindrances.suggest', $project) }}" class="flex flex-wrap items-center gap-3 pt-1">
                        @csrf
                        <input type="hidden" name="client_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <button class="btn btn-primary min-h-11">{{ __('Suggest groups') }}</button>
                        <span class="text-xs text-ink-3">{{ __('Sends the notes of :count daily reports. Uses up to :credits AI credits.', ['count' => $grouping['reports'], 'credits' => $grouping['credits']]) }}</span>
                    </form>
                @endif
            @endif
        </div>
    </section>
@endif
