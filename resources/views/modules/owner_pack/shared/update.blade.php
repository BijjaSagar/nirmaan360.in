@php
    use App\Services\Wording\Fact;
    use App\Services\Wording\FactFormat;

    $update = $shared->update;
    $locale = app()->getLocale();
    $date = fn ($day) => FactFormat::display(Fact::DATE, $day->toDateString(), $locale);
    // The text in the order the page reads: the week and its work, then progress and photos, then payments, then what comes next.
    $progress = $shared->slots('workers', 'progress', 'progress_basis', 'photos');
    $money = $shared->moneySlots();
    $nextWeek = $shared->slots('next_week_intro', 'next_week');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    {{-- The head include marks this page noindex: only listed website pages may be indexed. The response says so in a header too. --}}
    @include('layouts.partials.head')
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Weekly update') }} · {{ $shared->company }}</title>
</head>
<body class="font-sans antialiased">
<header class="bg-neel-deep px-5 py-4 text-paper"><x-brand /></header>
<main class="mx-auto max-w-4xl px-4 py-8 sm:px-5">
    @if ($shared->replacedBy !== null)
        <div class="mb-5 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="status">{{ __('The quotation this update referred to was replaced by revision :n, so its payment figures are no longer shown. Ask for a new update.', ['n' => $shared->replacedBy]) }}</div>
    @endif

    <p class="eyebrow text-ink-3">{{ $shared->company }}</p>
    <h1 class="mt-1 text-4xl">{{ __('Weekly update') }}</h1>
    <p class="mt-1 text-sm text-ink-3">{{ $shared->project }} · {{ __(':from to :to', ['from' => $date($update->week_start), 'to' => $date($update->week_end)]) }} · {{ __('Approved on :date', ['date' => $date($update->approved_at)]) }}</p>

    <section class="mt-5 space-y-2 text-[15px] leading-relaxed">
        @include('modules.owner_pack.shared._text', ['slots' => $shared->slots('opening', 'period', 'reports', 'work', 'work_more')])
    </section>

    @if ($shared->work !== [])
        <section class="card mt-5">
            @include('modules.owner_pack.updates._work', ['work' => $shared->work, 'tick' => false])
        </section>
    @endif

    @if ($progress !== [])
        <section class="mt-5 space-y-2 text-[15px] leading-relaxed">
            @include('modules.owner_pack.shared._text', ['slots' => $progress])
        </section>
    @endif

    @if ($shared->photos !== [])
        <section class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach ($shared->photos as $photo)
                <figure class="card p-2">
                    <img src="{{ $photoUrls[$photo['id']] }}" alt="{{ __('Site photo') }}" loading="lazy" referrerpolicy="no-referrer" class="w-full rounded-[2px]">
                    <figcaption class="mt-2 px-1 pb-1 text-sm">
                        <span class="num text-xs text-ink-3">{{ $photo['date'] }}</span>@if ($photo['caption'] !== null)<br>{{ $photo['caption'] }}@endif
                    </figcaption>
                </figure>
            @endforeach
        </section>
    @endif

    @if ($money !== [])
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Payments') }}</h2></div>
            <div class="space-y-1.5 px-4 py-3 text-sm">
                @include('modules.owner_pack.shared._text', ['slots' => $money])
            </div>
        </section>
    @endif

    @if ($nextWeek !== [])
        <section class="mt-5 space-y-2 text-[15px] leading-relaxed">
            @include('modules.owner_pack.shared._text', ['slots' => $nextWeek])
        </section>
    @endif

    <section class="mt-5 space-y-2 text-[15px] leading-relaxed">
        @include('modules.owner_pack.shared._text', ['slots' => $shared->slots('closing')])
    </section>

    <p class="mt-8 border-t border-rule pt-3 text-xs text-ink-3">{{ __('Shared by :company. This link stays open until :date.', ['company' => $shared->company, 'date' => $date($update->share_expires_at)]) }}</p>
</main>
</body>
</html>
