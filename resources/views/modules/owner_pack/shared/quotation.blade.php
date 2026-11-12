@php
    use App\Services\OwnerPack\QuotationMath;
    use App\Services\Wording\Fact;
    use App\Services\Wording\FactFormat;

    $quotation = $shared->quotation;
    $locale = app()->getLocale();
    $date = fn ($day) => FactFormat::display(Fact::DATE, $day->toDateString(), $locale);

    // The cover note in the order the page reads: what this is, then the lines, then the money, then the schedule.
    $parts = collect($shared->document->parts());
    $before = $parts->whereIn('id', ['opening', 'reference', 'lines']);
    $money = $parts->whereIn('id', ['subtotal', 'gst', 'total', 'total_words', 'validity']);
    $schedule = $parts->filter(fn (array $slot) => str_starts_with($slot['id'], 'stage'));
    $closing = $parts->where('id', 'closing');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    {{-- The head include marks this page noindex: only listed website pages may be indexed. The response says so in a header too. --}}
    @include('layouts.partials.head')
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Quotation') }} {{ $quotation->number }} · {{ $shared->company }}</title>
</head>
<body class="font-sans antialiased">
<header class="bg-neel-deep px-5 py-4 text-paper"><x-brand /></header>
<main class="mx-auto max-w-4xl px-4 py-8 sm:px-5">
    @if ($shared->replacedBy !== null)
        <div class="mb-5 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="status">{{ __('Replaced by revision :n. Ask for the link to that revision. The payment schedule of this one is no longer shown.', ['n' => $shared->replacedBy]) }}</div>
    @endif

    <p class="eyebrow text-ink-3">{{ $shared->company }}</p>
    <h1 class="mt-1 text-4xl">{{ __('Quotation') }} <span class="num">{{ $quotation->number }}</span></h1>
    <p class="mt-1 text-sm text-ink-3">{{ __('Revision :n', ['n' => $quotation->revision]) }} · {{ $shared->project }}@if ($quotation->issued_at) · {{ __('Issued on :date', ['date' => $date($quotation->issued_at)]) }}@endif</p>
    <p class="mt-1 text-sm">{{ __('Prepared for') }}: <span class="font-semibold">{{ $quotation->client_name }}</span></p>

    <section class="mt-5 space-y-2 text-[15px] leading-relaxed">
        @include('modules.owner_pack.quotations._note', ['slots' => $before, 'marked' => false])
    </section>

    <section class="card mt-5">
        @include('modules.owner_pack.quotations._lines', ['items' => $shared->items, 'tick' => false])
    </section>

    <div class="mt-5 grid gap-5 md:grid-cols-2">
        <section class="space-y-2 text-[15px] leading-relaxed">
            @include('modules.owner_pack.quotations._note', ['slots' => $money, 'marked' => false])
        </section>
        <section class="card self-start">
            @include('modules.owner_pack.quotations._totals')
        </section>
    </div>

    @if ($shared->stages->isNotEmpty())
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Payment stages') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="ledger min-w-[520px]">
                    <thead><tr><th>{{ __('Stage') }}</th><th>{{ __('Condition') }}</th><th class="r">{{ __('Share of the total') }}</th><th class="r">{{ __('Amount') }}</th></tr></thead>
                    <tbody>
                    @foreach ($shared->stages as $stage)
                        <tr>
                            <td><span class="num text-xs text-ink-3">{{ $loop->iteration }}</span> {{ $stage->title }}</td>
                            <td>{{ $stage->condition_text ?: '—' }}</td>
                            <td class="r">{{ $stage->isPercent() ? QuotationMath::plain($stage->percent).'%' : '—' }}</td>
                            <td class="r">{{ inr($stage->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="space-y-1.5 border-t border-rule px-4 py-3 text-sm">
                @include('modules.owner_pack.quotations._note', ['slots' => $schedule, 'marked' => false])
            </div>
        </section>
    @endif

    <section class="mt-5 space-y-2 text-[15px] leading-relaxed">
        @include('modules.owner_pack.quotations._note', ['slots' => $closing, 'marked' => false])
    </section>

    <p class="mt-8 border-t border-rule pt-3 text-xs text-ink-3">{{ __('Shared by :company. This link stays open until :date.', ['company' => $shared->company, 'date' => $date($quotation->share_expires_at)]) }}</p>
</main>
</body>
</html>
