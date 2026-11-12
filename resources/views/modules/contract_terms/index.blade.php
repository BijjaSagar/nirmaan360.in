@extends('layouts.app')
@section('title', __('Contract terms'))
@section('content')
    @php
        $canManage = auth()->user()->can('contract_terms.manage');
        $total = $contracts->flatten(1)->count();
        $unconfirmed = $contracts->flatten(1)->reject->isConfirmed()->count();
    @endphp
    <div x-data="{ add: {{ $errors->any() && old('_term') === null ? 'true' : 'false' }} }">
        <x-page-header :title="__('Contract terms')" :subtitle="$project->name">
            @if ($canManage)
                <button type="button" class="btn btn-primary min-h-11" @click="add = ! add"><x-icon name="plus" class="size-4" />{{ __('Add a term') }}</button>
            @endif
        </x-page-header>

        <div class="mb-5 space-y-1 text-sm text-ink-3">
            <p>{{ __('The app fills in nothing on this page. Each term is typed from your agreement, with the date it applies from and the clause or page where it is written.') }}</p>
            <p>{{ __('A term counts only after someone ticks that they have read it in the agreement, and only for the dates it covers.') }}</p>
            <p>{{ __('Entering a term here does not change any bill. Bills are still worked out from the percentages on the project and work order forms.') }}</p>
        </div>

        @if ($unconfirmed > 0)
            <p class="mb-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium text-[#8a6208]" role="status">{{ __('Not confirmed yet: :count. A term that is not confirmed does not count.', ['count' => $unconfirmed]) }}</p>
        @endif

        @if ($canManage)
            <form method="POST" action="{{ route('contract-terms.store', $project) }}" x-show="add" x-cloak class="card mb-5">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <div class="card-head"><h2 class="card-title">{{ __('Add a term') }}</h2></div>
                @include('modules.contract_terms._fields', ['term' => null])
                <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                    <button type="button" class="btn btn-ghost min-h-11" @click="add = false">{{ __('Cancel') }}</button>
                    <button class="btn btn-primary min-h-11">{{ __('Save term') }}</button>
                </div>
            </form>
        @endif

        @if ($total === 0)
            <div class="card">
                <x-empty :title="__('No contract terms entered')" :note="__('Add what your agreement says about deductions, notice periods, payment and its dates. Nothing is assumed until you enter it.')" />
            </div>
        @else
            <div class="space-y-5">
                @foreach ($contracts as $terms)
                    @php $first = $terms->first(); @endphp
                    <section class="card">
                        <div class="card-head">
                            <h2 class="card-title">
                                @if ($first->work_order_id === null)
                                    {{ __('Client contract') }}
                                @else
                                    <span class="num text-sm">{{ $first->workOrder?->number ?? __('Removed work order') }}</span>
                                    @if ($first->workOrder) · {{ $first->workOrder->title }} @endif
                                @endif
                            </h2>
                        </div>

                        @foreach ($terms as $term)
                            <details class="border-b border-rule last:border-b-0" @if ((string) old('_term') === (string) $term->id) open @endif>
                                <summary class="flex min-h-14 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-semibold">{{ $text->name($term) }}</span>
                                        <span class="block text-xs text-ink-3">{{ $text->kind($term) }} · {{ $text->period($term) }}</span>
                                    </span>
                                    <span class="num text-sm">{{ $text->value($term) }}</span>
                                    @if (! $term->covers($today))
                                        <span class="stamp border-ink-3 text-ink-3">{{ $term->effective_from->gt($today) ? __('Not yet in force') : __('Ended') }}</span>
                                    @endif
                                    @if ($term->isConfirmed())
                                        <span class="stamp border-moss text-moss">{{ __('Confirmed') }}</span>
                                    @else
                                        <span class="stamp border-haldi text-[#8a6208]">{{ __('Not confirmed') }}</span>
                                    @endif
                                </summary>

                                <div class="border-t border-rule">
                                    <dl class="grid gap-x-6 gap-y-2 px-4 py-3 text-sm sm:grid-cols-2">
                                        @if ($text->notes($term) !== [])
                                            <div class="sm:col-span-2">
                                                <dt class="eyebrow text-ink-3">{{ __('As entered') }}</dt>
                                                @foreach ($text->notes($term) as $note)
                                                    <dd>{{ $note }}</dd>
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="sm:col-span-2"><dt class="eyebrow text-ink-3">{{ __('Clause or page in the agreement') }}</dt><dd class="whitespace-pre-line">{{ $term->source_ref ?? '—' }}</dd></div>
                                        <div><dt class="eyebrow text-ink-3">{{ __('Entered by') }}</dt><dd>{{ $term->enteredBy?->name ?? '—' }} · <span class="num">{{ $term->created_at?->format('d M Y') }}</span></dd></div>
                                        <div>
                                            <dt class="eyebrow text-ink-3">{{ __('Confirmed by') }}</dt>
                                            @if ($term->isConfirmed())
                                                <dd>{{ $term->confirmedBy?->name ?? '—' }} · <span class="num">{{ $term->confirmed_at->format('d M Y') }}</span></dd>
                                            @else
                                                <dd class="text-ink-3">{{ __('Nobody yet. Until then this term does not count.') }}</dd>
                                            @endif
                                        </div>
                                    </dl>

                                    @if ($canManage)
                                        @unless ($term->isConfirmed())
                                            <form method="POST" action="{{ route('contract-terms.confirm', $term) }}" class="flex flex-wrap items-center gap-3 border-t border-rule bg-paper/50 px-4 py-3">
                                                @csrf
                                                <input type="hidden" name="_term" value="{{ $term->id }}">
                                                {{-- What this person is looking at. A term changed since then is not confirmed unseen. --}}
                                                <input type="hidden" name="seen" value="{{ $term->fingerprint() }}">
                                                <label class="flex min-h-11 flex-1 items-center gap-2 text-sm font-medium">
                                                    <input type="checkbox" name="read_in_agreement" value="1" class="size-5" required>
                                                    {{ __('I have read this in the agreement') }}
                                                </label>
                                                <button class="btn btn-primary min-h-11">{{ __('Confirm') }}</button>
                                            </form>
                                        @endunless

                                        <form method="POST" action="{{ route('contract-terms.update', $term) }}" class="border-t border-rule">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="_term" value="{{ $term->id }}">
                                            @include('modules.contract_terms._fields', ['term' => $term])
                                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-rule px-4 py-3">
                                                <p class="text-xs text-ink-3">{{ __('Saving a change removes the confirmation. Confirm the term again after checking it against the agreement.') }}</p>
                                                <button class="btn btn-primary min-h-11">{{ __('Save changes') }}</button>
                                            </div>
                                        </form>
                                        <form method="POST" action="{{ route('contract-terms.destroy', $term) }}" class="border-t border-rule bg-paper/50 px-4 py-3" onsubmit="return confirm(@js(__('Remove this term?')))">
                                            @csrf @method('DELETE')
                                            <button class="link text-sm text-laterite">{{ __('Remove this term') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                        @endforeach
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection
