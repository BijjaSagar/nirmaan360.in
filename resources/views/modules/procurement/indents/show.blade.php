@extends('layouts.app')
@section('title', $indent->number)
@section('content')
    <x-page-header :title="$indent->number" :subtitle="$project->name">
        <x-stamp :status="$indent->status" />
        @if ($indent->isEditable())
            @can('indent.create')
                <a href="{{ route('procurement.indents.edit', $indent) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('procurement.indents.submit', $indent) }}">@csrf<button class="btn btn-primary">{{ __('Send for approval') }}</button></form>
            @endcan
        @endif
    </x-page-header>

    <div class="grid items-start gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Materials needed') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[520px]">
                        <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('BOQ item') }}</th></tr></thead>
                        <tbody>
                        @foreach ($indent->items as $line)
                            <tr>
                                <td>{{ $line->material->name }}</td>
                                <td class="r">{{ fmt_qty($line->qty) }} {{ $line->unit->code }}</td>
                                <td>{{ $line->boqItem?->code ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <dl class="grid gap-2 border-t border-rule p-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-ink-3">{{ __('Deliver to store') }}</dt><dd>{{ $indent->store->name }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Required by') }}</dt><dd>{{ $indent->required_by?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Raised by') }}</dt><dd>{{ $indent->creator?->name ?? '—' }}</dd></div>
                    @if ($indent->remarks)<div class="sm:col-span-2"><dt class="text-ink-3">{{ __('Remarks') }}</dt><dd>{{ $indent->remarks }}</dd></div>@endif
                </dl>
            </section>

            @if ($indent->status === 'APPROVED')
                @can('procurement.purchase')
                    <section class="card">
                        <div class="card-head"><h2 class="card-title">{{ __('Ask vendors for quotations') }}</h2></div>
                        @if ($vendors->isEmpty())
                            <p class="px-4 py-5 text-sm text-ink-3">{{ __('Add vendors in Contacts first.') }}</p>
                        @else
                            <form method="POST" action="{{ route('procurement.rfqs.store', $indent) }}" class="space-y-3 p-4">
                                @csrf
                                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <fieldset>
                                    <legend class="label">{{ __('Vendors') }}</legend>
                                    <div class="grid gap-1.5 sm:grid-cols-2">
                                        @foreach ($vendors as $vendor)
                                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="party_ids[]" value="{{ $vendor->id }}"> {{ $vendor->name }}</label>
                                        @endforeach
                                    </div>
                                    @error('party_ids')<p class="error">{{ $message }}</p>@enderror
                                </fieldset>
                                <x-field :label="__('Quotes due by')" name="due_date"><input id="due_date" name="due_date" type="date" class="input sm:max-w-56"></x-field>
                                <button class="btn btn-primary">{{ __('Create RFQ') }}</button>
                            </form>
                        @endif
                    </section>
                @endcan
            @endif

            @if ($indent->rfqs->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('RFQs') }}</h2></div>
                    @foreach ($indent->rfqs as $rfq)
                        <div class="flex items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                            <a href="{{ route('procurement.rfqs.show', $rfq) }}" class="link num text-xs">{{ $rfq->number }}</a>
                            <x-stamp :status="$rfq->status" />
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

        <x-approval-trail :approvals="$indent->approvals" />
    </div>
@endsection
