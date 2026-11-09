<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <meta name="robots" content="noindex">
    <title>{{ $bill->number }} · {{ config('buildos.brand') }}</title>
</head>
<body class="font-sans antialiased">
<header class="bg-neel-deep px-5 py-4 text-paper"><x-brand /></header>
<main class="mx-auto max-w-4xl px-5 py-8">
    <p class="eyebrow text-ink-3">{{ $bill->organization->name }}</p>
    <h1 class="mt-1 text-4xl">{{ __('RA bill') }} {{ $bill->number }}</h1>
    <p class="mt-1 text-sm text-ink-3">{{ $bill->project->name }} · {{ $bill->period_from->format('d M Y') }} – {{ $bill->period_to->format('d M Y') }}</p>

    @if (session('success'))
        <div class="mt-4 rounded-[3px] border border-moss bg-moss/10 px-3 py-2 text-sm font-medium text-moss" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mt-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="card mt-5 overflow-x-auto">
        <table class="ledger min-w-[640px]">
            <thead><tr><th>{{ __('Item') }}</th><th class="w-2/5">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('This bill') }}</th><th class="r">{{ __('Cumulative') }}</th><th class="r">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @foreach ($bill->items as $line)
                <tr>
                    <td class="num text-xs">{{ $line->code }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->unit }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($line->rate) }}</td>
                    <td class="r">{{ fmt_qty($line->current_qty) }}</td>
                    <td class="r">{{ fmt_qty($line->cumulative_qty) }}</td>
                    <td class="r">{{ \App\Support\Dec::indian($line->current_amount) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <div class="mt-5 grid gap-5 md:grid-cols-2">
        <section class="card">
            <dl class="text-sm">
                <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('Gross value of work') }}</dt><dd class="num">{{ inr($bill->gross_amount) }}</dd></div>
                @foreach ($bill->deductions as $deduction)
                    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __($deduction->label) }}</dt><dd class="num text-laterite">−{{ inr($deduction->amount) }}</dd></div>
                @endforeach
                <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('GST') }}</dt><dd class="num">+{{ inr($bill->gst_amount) }}</dd></div>
                <div class="flex items-baseline justify-between bg-paper-2/60 px-4 py-3"><dt class="font-display text-lg font-semibold">{{ __('Net payable') }}</dt><dd class="num text-lg font-medium">{{ inr($bill->net_payable) }}</dd></div>
            </dl>
        </section>

        @if ($approval)
            <form method="POST" action="{{ request()->fullUrl() }}" class="card space-y-3 p-4">
                @csrf
                <h2 class="card-title">{{ __('Your sign-off') }}</h2>
                <x-field :label="__('Your name')" name="name"><input id="name" name="name" value="{{ old('name') }}" class="input" required autocomplete="name"></x-field>
                <x-field :label="__('Remark')" name="remark" :hint="__('Needed if you return the bill.')"><textarea id="remark" name="remark" rows="2" class="input">{{ old('remark') }}</textarea></x-field>
                <div class="flex gap-2">
                    <button name="action" value="approve" class="btn btn-primary">{{ __('Approve') }}</button>
                    <button name="action" value="return" class="btn btn-ghost">{{ __('Return') }}</button>
                </div>
            </form>
        @else
            <div class="card p-4"><p class="text-sm text-ink-2">{{ __('This bill is not waiting for your sign-off.') }}</p></div>
        @endif
    </div>
</main>
</body>
</html>
