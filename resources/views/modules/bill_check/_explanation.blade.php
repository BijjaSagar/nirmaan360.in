{{--
    The findings of the check explained in plain words, inside the pre-check card. Needs $checkId.
    In the reader's own language unless they pick another with ?explain=hi. Without the AI every finding
    is explained in the app's own words; the button asks a model to write what each kind of finding
    means. Every finding and every figure is placed by the app, in the form the reader may see.
--}}
@php
    $explained = app(\App\Services\BillCheck\Explain\BillCheckExplanations::class)->forCard($checkId, auth()->user(), request()->query('explain'));
@endphp
@if ($explained !== null)
<details class="border-b border-rule" id="bill-check-explain" @if (request()->has('explain') || $explained['status'] === 'drafting') open @endif>
    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">{{ __('What these findings mean') }}</summary>
    <div class="px-4 pb-3 text-sm">
        <p class="flex flex-wrap gap-3 text-xs">
            @foreach (['en' => 'English', 'hi' => 'हिन्दी', 'mr' => 'मराठी'] as $code => $name)
                @if ($code === $explained['locale'])
                    <span class="font-semibold" lang="{{ $code }}">{{ $name }}</span>
                @else
                    <a class="underline" lang="{{ $code }}" href="{{ request()->fullUrlWithQuery(['explain' => $code]) }}#bill-check-explain">{{ $name }}</a>
                @endif
            @endforeach
        </p>

        <div lang="{{ $explained['locale'] }}">
            @foreach ($explained['paragraphs'] as $paragraph)
                <div class="mt-3">
                    @if ($paragraph['about'] !== null)
                        <p class="text-ink-2">{{ $paragraph['about'] }}</p>
                    @endif
                    <p class="mt-1 font-medium">{{ $paragraph['finding'] }}</p>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-xs text-ink-3">
            {{ $explained['source'] === 'ai'
                ? __('The sentence before each finding was written by AI. The findings and every figure in them are placed by the app from the check.')
                : __('Explained in the app\'s own words. Every figure is placed by the app from the check.') }}
        </p>

        @if ($explained['status'] === 'drafting')
            <p class="mt-2 text-xs font-medium" role="status">{{ __('The AI is writing the explanation. It appears here when it is ready.') }}</p>
            <div x-data x-init="
                const started = Date.now();
                const check = setInterval(async () => {
                    if (Date.now() - started > 600000) { clearInterval(check); return; }
                    const response = await fetch(@js(route('bill-checks.explanation', ['check' => $checkId, 'locale' => $explained['locale']])), { headers: { Accept: 'application/json' } });
                    if (response.ok && (await response.json()).status !== 'drafting') { clearInterval(check); location.reload(); }
                }, 3000);
            "></div>
        @elseif ($explained['can']['ask'])
            <form method="POST" action="{{ route('bill-checks.explain', $checkId) }}" class="mt-3 flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="locale" value="{{ $explained['locale'] }}">
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <button class="btn btn-ghost min-h-11 py-1 text-xs">{{ __('Write it in plain words with AI') }}</button>
                @if ($explained['credits'] !== null)
                    <span class="text-xs text-ink-3">{{ __('Uses up to :count AI credits', ['count' => \App\Support\Dec::indian($explained['credits'], 0)]) }}</span>
                @endif
            </form>
        @elseif ($explained['plan_stops'] && $explained['source'] !== 'ai')
            <p class="mt-2 text-xs text-ink-3">{{ __('Writing it with AI is part of the Business and Enterprise plans.') }}</p>
        @endif
    </div>
</details>
@endif
