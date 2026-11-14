@extends('layouts.app')
@section('title', $tender->title)
@section('content')
    @php
        $stages = \App\Services\Crm\Vocab::stages();
        $locked = $tender->isConverted();
    @endphp
    <x-page-header :title="$tender->title" :subtitle="$tender->authority.($tender->reference ? ' · '.$tender->reference : '')">
        @include('modules.crm.partials.stage', ['stage' => $tender->stage])
        @can('crm.manage')<a href="{{ route('crm.tenders.edit', $tender) }}" class="btn btn-ghost">{{ __('Edit') }}</a>@endcan
        @if ($tender->stage === 'won' && ! $tender->isConverted())
            @can('crm.convert')@can('project.manage')
                <a href="{{ route('crm.tenders.convert', $tender) }}" class="btn btn-primary">{{ __('Convert to project') }}</a>
            @endcan @endcan
        @endif
        @if ($tender->isConverted())
            <a href="{{ route('projects.show', $tender->project_id) }}" class="btn btn-primary">{{ __('Open project') }}</a>
        @endif
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            {{-- Facts --}}
            <section class="card">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 p-4 text-sm lg:grid-cols-3 lg:gap-x-6">
                    <div><p class="eyebrow text-ink-3">{{ __('Estimated value') }}</p><p class="num">{{ inr($tender->estimated_value) }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Our quoted value') }}</p><p class="num">{{ $tender->bid_value !== null ? inr($tender->bid_value) : '—' }}</p></div>
                    @if ($tender->stage === 'won')<div><p class="eyebrow text-ink-3">{{ __('Awarded value') }}</p><p class="num font-semibold text-moss">{{ inr($tender->awarded_value) }}</p></div>@endif
                    <div><p class="eyebrow text-ink-3">{{ __('Submission deadline') }}</p>
                        @php $state = $tender->deadlineState(); @endphp
                        <p class="num {{ $state === 'overdue' ? 'font-semibold text-laterite' : ($state === 'soon' ? 'font-semibold text-[#8a6208]' : '') }}">{{ $tender->due_at?->format('d M Y, h:i A') ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Pre-bid meeting') }}</p><p class="num">{{ $tender->pre_bid_at?->format('d M Y, h:i A') ?? '—' }}</p>@if ($tender->pre_bid_note)<p class="text-xs text-ink-3">{{ $tender->pre_bid_note }}</p>@endif</div>
                    <div><p class="eyebrow text-ink-3">{{ __('Bid opening') }}</p><p class="num">{{ $tender->opens_at?->format('d M Y, h:i A') ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Published on') }}</p><p class="num">{{ $tender->published_on?->format('d M Y') ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Client') }}</p><p>@if ($tender->party)<a href="{{ route('crm.companies.show', $tender->party) }}" class="link">{{ $tender->party->name }}</a>@else — @endif</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Owner') }}</p><p>{{ $tender->owner?->name ?? '—' }}</p></div>
                    <div><p class="eyebrow text-ink-3">{{ __('Location') }}</p><p>{{ collect([$tender->location, $tender->state])->filter()->implode(', ') ?: '—' }}</p></div>
                    @if ($tender->portal_url)<div class="sm:col-span-2"><p class="eyebrow text-ink-3">{{ __('Portal link') }}</p><p class="break-all"><a href="{{ $tender->portal_url }}" target="_blank" rel="noopener noreferrer" class="link">{{ $tender->portal_url }}</a></p></div>@endif
                    @if ($tender->lead)<div><p class="eyebrow text-ink-3">{{ __('From lead') }}</p><p><a href="{{ route('crm.leads.show', $tender->lead) }}" class="link">{{ $tender->lead->name }}</a></p></div>@endif
                </div>
                @if ($tender->tags || $fieldDefinitions->isNotEmpty() || $tender->notes)
                    <div class="space-y-3 border-t border-rule p-4">
                        @include('modules.crm.partials.tags', ['tags' => $tender->tags, 'route' => 'crm.tenders.index'])
                        @include('modules.crm.partials.custom-values', ['values' => $tender->custom_fields ?? []])
                        @if ($tender->notes)<p class="whitespace-pre-line text-sm text-ink-2">{{ $tender->notes }}</p>@endif
                    </div>
                @endif
            </section>

            <div class="space-y-5 lg:hidden">
                @include('modules.crm.tenders.stage', ['p' => 'top-'])
            </div>

            {{-- EMD and fees --}}
            <section id="payments" class="card" x-data="{ add: false }">
                <div class="card-head">
                    <h2 class="card-title">{{ __('EMD and fees') }}</h2>
                    @can('crm.emd')<button type="button" class="btn btn-ghost min-h-9" @click="add = !add">{{ __('Add payment') }}</button>@endcan
                </div>
                <div class="grid grid-cols-2 gap-px border-b border-rule bg-rule sm:grid-cols-4">
                    <div class="bg-sheet px-4 py-2"><p class="eyebrow text-ink-3">{{ __('Held') }}</p><p class="num text-sm">{{ inr($totals['held']) }}</p></div>
                    <div class="bg-sheet px-4 py-2"><p class="eyebrow text-ink-3">{{ __('Refund due') }}</p><p class="num text-sm {{ \App\Support\Dec::of($totals['refund_due'])->isPositive() ? 'font-semibold text-laterite' : '' }}">{{ inr($totals['refund_due']) }}</p></div>
                    <div class="bg-sheet px-4 py-2"><p class="eyebrow text-ink-3">{{ __('Refunded') }}</p><p class="num text-sm">{{ inr($totals['refunded']) }}</p></div>
                    <div class="bg-sheet px-4 py-2"><p class="eyebrow text-ink-3">{{ __('Not recoverable') }}</p><p class="num text-sm">{{ inr($totals['cost']) }}</p></div>
                </div>
                @can('crm.emd')
                    <form x-show="add" x-cloak method="POST" action="{{ route('crm.payments.store', $tender) }}" class="border-b border-rule p-4">
                        @csrf
                        @include('modules.crm.payments.fields', ['payment' => null])
                        <div class="mt-3"><button class="btn btn-primary">{{ __('Save payment') }}</button></div>
                    </form>
                @endcan
                @if ($payments->isEmpty())
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No EMD or fees recorded.') }}</p>
                @else
                    @foreach ($payments as $payment)
                        @include('modules.crm.payments.row', ['payment' => $payment])
                    @endforeach
                @endif
            </section>

            {{-- BOQ --}}
            <section id="boq" class="card" x-data="{ add: false }">
                <div class="card-head">
                    <h2 class="card-title">{{ __('BOQ') }} <span class="num text-sm text-ink-3">{{ inr($boqTotal) }}</span></h2>
                    @can('crm.manage')
                        @unless ($locked)<button type="button" class="btn btn-ghost min-h-9" @click="add = !add">{{ __('Add or import') }}</button>@endunless
                    @endcan
                </div>
                @if ($locked)
                    <p class="border-b border-rule bg-paper-2/40 px-4 py-2 text-sm text-ink-2">{{ __('These lines were carried to the project. Change the BOQ there.') }}</p>
                @endif
                @can('crm.manage')
                    @unless ($locked)
                        <div x-show="add" x-cloak class="grid gap-5 border-b border-rule p-4 lg:grid-cols-3">
                            <form method="POST" action="{{ route('crm.boq.store', $tender) }}" class="grid gap-3 sm:grid-cols-4 lg:col-span-2">
                                @csrf
                                <x-field :label="__('Section')" name="section" class="sm:col-span-2"><input id="section" name="section" class="input" maxlength="120"></x-field>
                                <x-field :label="__('Item code')" name="code" class="sm:col-span-2"><input id="code" name="code" class="input" maxlength="32" required></x-field>
                                <x-field :label="__('Description')" name="description" class="sm:col-span-4"><textarea id="description" name="description" rows="2" class="input" maxlength="2000" required></textarea></x-field>
                                <x-field :label="__('Unit')" name="unit">
                                    <input id="unit" name="unit" list="crm-units" class="input" maxlength="16" required>
                                    <datalist id="crm-units">@foreach (config('buildos.units') as $unit)<option value="{{ $unit }}">@endforeach</datalist>
                                </x-field>
                                <x-field :label="__('Quantity')" name="qty"><input id="qty" name="qty" type="number" step="0.001" min="0" inputmode="decimal" class="input num" required></x-field>
                                <x-field :label="__('Rate (₹)')" name="rate"><input id="rate" name="rate" type="number" step="0.01" min="0" inputmode="decimal" class="input num" required></x-field>
                                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Add item') }}</button></div>
                            </form>
                            <form method="POST" action="{{ route('crm.boq.import', $tender) }}" enctype="multipart/form-data" class="space-y-3">
                                @csrf
                                <p class="text-sm text-ink-3">{{ __('Rows with an existing item code are updated. If any row has a problem, nothing is imported.') }}</p>
                                <x-field :label="__('CSV or Excel file')" name="file"><input id="file" name="file" type="file" class="input" required></x-field>
                                <div class="flex items-center justify-between gap-2">
                                    <a href="{{ route('boq.template') }}" class="link text-sm">{{ __('Download template') }}</a>
                                    <button class="btn btn-ghost">{{ __('Import') }}</button>
                                </div>
                            </form>
                        </div>
                    @endunless
                @endcan
                @if ($boqItems->isEmpty())
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No BOQ lines yet. Add them here and they carry to the project when you convert this tender.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="ledger min-w-[620px]">
                            <thead><tr><th>{{ __('Code') }}</th><th class="w-[40%]">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Amount') }}</th>@can('crm.manage')@unless ($locked)<th></th>@endunless @endcan</tr></thead>
                            <tbody>
                            @php $section = false; @endphp
                            @foreach ($boqItems as $line)
                                @if ($line->section !== $section)
                                    @php $section = $line->section; @endphp
                                    @if ($section)<tr><td colspan="7" class="bg-paper-2/40 font-display text-base font-semibold">{{ $section }}</td></tr>@endif
                                @endif
                                <tr x-data="{ edit: false }">
                                    <td class="num text-xs">{{ $line->code }}</td>
                                    <td>
                                        <span x-show="!edit">{{ $line->description }}</span>
                                        @can('crm.manage')
                                            @unless ($locked)
                                                <form x-show="edit" x-cloak id="line-{{ $line->id }}" method="POST" action="{{ route('crm.boq.update', $line) }}" class="grid gap-2">
                                                    @csrf @method('PATCH')
                                                    <input name="section" value="{{ $line->section }}" class="input" maxlength="120" placeholder="{{ __('Section') }}" aria-label="{{ __('Section') }}">
                                                    <input name="code" value="{{ $line->code }}" class="input" maxlength="32" required aria-label="{{ __('Item code') }}">
                                                    <textarea name="description" rows="2" class="input" maxlength="2000" required aria-label="{{ __('Description') }}">{{ $line->description }}</textarea>
                                                    <input name="unit" value="{{ $line->unit }}" class="input" maxlength="16" required aria-label="{{ __('Unit') }}">
                                                    <input name="qty" type="number" step="0.001" min="0" value="{{ $line->qty }}" class="input num" required aria-label="{{ __('Quantity') }}">
                                                    <input name="rate" type="number" step="0.01" min="0" value="{{ $line->rate }}" class="input num" required aria-label="{{ __('Rate') }}">
                                                    <div class="flex gap-2"><button class="btn btn-primary min-h-9">{{ __('Save') }}</button><button type="button" class="btn btn-ghost min-h-9" @click="edit = false">{{ __('Cancel') }}</button></div>
                                                </form>
                                            @endunless
                                        @endcan
                                    </td>
                                    <td>{{ $line->unit }}</td>
                                    <td class="r">{{ fmt_qty($line->qty) }}</td>
                                    <td class="r">{{ \App\Support\Dec::indian($line->rate) }}</td>
                                    <td class="r">{{ \App\Support\Dec::indian($line->amount()) }}</td>
                                    @can('crm.manage')
                                        @unless ($locked)
                                            <td class="whitespace-nowrap">
                                                <button type="button" class="link text-xs" @click="edit = !edit">{{ __('Edit') }}</button>
                                                <form method="POST" action="{{ route('crm.boq.destroy', $line) }}" class="inline" onsubmit="return confirm('{{ __('Remove this line?') }}')">@csrf @method('DELETE')<button class="link ml-2 text-xs text-laterite">{{ __('Remove') }}</button></form>
                                            </td>
                                        @endunless
                                    @endcan
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Documents --}}
            <section id="documents" class="card" x-data="{ add: false }">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Documents') }}</h2>
                    @can('crm.manage')<button type="button" class="btn btn-ghost min-h-9" @click="add = !add">{{ __('Add document') }}</button>@endcan
                </div>
                @can('crm.manage')
                    <form x-show="add" x-cloak method="POST" action="{{ route('crm.documents.store', $tender) }}" enctype="multipart/form-data" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-3">
                        @csrf
                        <x-field :label="__('File')" name="file" class="sm:col-span-3"><input id="file-doc" name="file" type="file" class="input" required></x-field>
                        <x-field :label="__('Title')" name="title"><input id="title-doc" name="title" class="input" maxlength="160"></x-field>
                        <x-field :label="__('Type')" name="kind">
                            <select id="kind-doc" name="kind" class="input">@foreach (\App\Services\Crm\Vocab::documentKinds() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                        </x-field>
                        <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Upload') }}</button></div>
                        <p class="hint sm:col-span-3">{{ __('PDF, Word, Excel, images, drawings and zip files up to 20 MB.') }}</p>
                    </form>
                @endcan
                @forelse ($documents as $document)
                    <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                        <div class="min-w-0">
                            <a href="{{ route('crm.documents.download', $document) }}" class="link break-words">{{ $document->title }}</a>
                            <p class="text-xs text-ink-3">{{ \App\Services\Crm\Vocab::documentKinds()[$document->kind] ?? $document->kind }} · {{ number_format($document->size / 1024, 0) }} KB · {{ $document->created_at->format('d M Y') }}@if ($document->uploader) · {{ $document->uploader->name }}@endif @if ($document->project_id) · {{ __('In project') }}@endif</p>
                        </div>
                        @can('crm.manage')
                            <form method="POST" action="{{ route('crm.documents.destroy', $document) }}" onsubmit="return confirm('{{ __('Remove this document?') }}')">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Remove') }}</button></form>
                        @endcan
                    </div>
                @empty
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No documents yet. Keep the tender notice, corrigenda and drawings here; they travel to the project.') }}</p>
                @endforelse
            </section>

            @includeWhen(view()->exists('modules.tender_reading._tender'), 'modules.tender_reading._tender', ['tender' => $tender, 'documents' => $documents])
        </div>

        <div class="space-y-5">
            <div class="hidden space-y-5 lg:block">
                @include('modules.crm.tenders.stage', ['p' => 'side-'])
            </div>

            @include('modules.crm.partials.tasks-panel', ['tasks' => $tasks, 'subjectType' => 'tender', 'subjectId' => $tender->id])
            @can('crm.view')@include('modules.crm.partials.message-picker', ['templates' => $templates, 'subjectType' => 'tender', 'subjectId' => $tender->id])@endcan
        </div>
    </div>

    <div class="mt-5">
        @include('modules.crm.partials.timeline', ['timeline' => $timeline, 'subjectType' => 'tender', 'subjectId' => $tender->id])
    </div>

    @can('crm.manage')
        @unless ($tender->isConverted())
            <form method="POST" action="{{ route('crm.tenders.destroy', $tender) }}" class="mt-5 text-right" onsubmit="return confirm('{{ __('Delete this tender?') }}')">
                @csrf @method('DELETE')
                <button class="link text-xs text-laterite">{{ __('Delete tender') }}</button>
            </form>
        @endunless
    @endcan
@endsection
