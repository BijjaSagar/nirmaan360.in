@extends('layouts.app')
@section('title', $quotation->number)
@section('content')
    @php
        use App\Services\OwnerPack\QuotationMath;
        use App\Services\Wording\Fact;
        use App\Services\Wording\FactFormat;

        $draft = $quotation->isDraft();
        $canManage = auth()->user()->can('owner_pack.manage');
        $noteSlots = $note?->parts() ?? [];
        $stagesTotal = QuotationMath::sum($stages->pluck('amount'));
        $stagesBalance = \App\Support\Dec::of($quotation->total)->minus($stagesTotal);
        $date = fn ($day) => FactFormat::display(Fact::DATE, $day->toDateString(), app()->getLocale());
        // Ticks come back after a refusal, except when the figures themselves changed: then each one is checked afresh.
        $ticked = $errors->has('facts') ? [] : array_filter((array) old('number_ticks', []), 'is_string');
    @endphp

    <x-page-header :title="__('Quotation').' '.$quotation->number" :subtitle="__('Revision :n', ['n' => $quotation->revision]).' · '.$quotation->client_name.' · '.$project->name">
        @include('modules.owner_pack.quotations._status', ['status' => $quotation->status, 'replacedBy' => $replacedBy])
        <a href="{{ route('quotations.index', $project) }}" class="btn btn-ghost">{{ __('All quotations') }}</a>
    </x-page-header>

    @if ($replacedBy !== null)
        <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm">{{ __('This revision was replaced by revision :n. Its shared link says so and no longer shows the payment schedule.', ['n' => $replacedBy]) }}</div>
    @elseif ($openRevision !== null)
        <div class="mb-5 rounded-[3px] border border-neel bg-neel/8 px-3 py-2 text-sm">
            {{ __('Revision :n is being prepared as a draft.', ['n' => $openRevision->revision]) }} <a href="{{ route('quotations.show', $openRevision) }}" class="link">{{ __('Open it') }}</a>
            <p class="mt-1 text-xs text-ink-2">{{ __('Until it is issued, mark the stages reached here. When it is issued, each mark goes to the stage of the new revision that is unchanged.') }}</p>
        </div>
    @elseif ($draft && $quotation->revision > 1)
        <div class="mb-5 rounded-[3px] border border-neel bg-neel/8 px-3 py-2 text-sm">{{ __('This is a draft of revision :n. The earlier revision stays as issued, and its link keeps working, until this one is issued.', ['n' => $quotation->revision]) }}</div>
    @endif

    <div class="space-y-5">
        @if ($draft && $canManage)
            <form method="POST" action="{{ route('quotations.update', $quotation) }}" class="card">
                @csrf @method('PATCH')
                <div class="card-head"><h2 class="card-title">{{ __('Details') }}</h2></div>
                <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    @include('modules.owner_pack.quotations._header-fields', ['language' => $quotation->language])
                    <x-field :label="__('Opening sentence')" name="opening" class="sm:col-span-2 lg:col-span-3" :hint="__('State no amount, percentage or date here: the app writes those sentences itself. A number you type is marked and needs its own tick.')">
                        <textarea id="opening" name="opening" rows="2" maxlength="{{ config('wording.free_slot_max_chars') }}" class="input" lang="{{ $quotation->language }}">{{ old('opening', $note?->slot('opening')?->text) }}</textarea>
                    </x-field>
                    <x-field :label="__('Closing sentence')" name="closing" class="sm:col-span-2 lg:col-span-3" :hint="__('Empty brings back the app\'s own sentence.')">
                        <textarea id="closing" name="closing" rows="2" maxlength="{{ config('wording.free_slot_max_chars') }}" class="input" lang="{{ $quotation->language }}">{{ old('closing', $note?->slot('closing')?->text) }}</textarea>
                    </x-field>
                </div>
                <div class="border-t border-rule p-4"><button class="btn btn-primary">{{ __('Save draft') }}</button></div>
            </form>
        @else
            <section class="card px-4 py-3 text-sm">
                <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-3">
                    <div><dt class="text-xs text-ink-3">{{ __('Prepared for') }}</dt><dd>{{ $quotation->client_name }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Language of the quotation') }}</dt><dd>{{ config('buildos.locales.'.$quotation->language, $quotation->language) }}</dd></div>
                    @if ($quotation->valid_until)
                        <div><dt class="text-xs text-ink-3">{{ __('Valid until') }}</dt><dd class="num">{{ $date($quotation->valid_until) }}</dd></div>
                    @endif
                    @if ($quotation->isIssued())
                        <div><dt class="text-xs text-ink-3">{{ __('Issued by') }}</dt><dd>{{ $quotation->issuedBy?->name ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-ink-3">{{ __('Issued on') }}</dt><dd class="num">{{ $date($quotation->issued_at) }}</dd></div>
                    @endif
                </dl>
            </section>
        @endif

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Lines') }}</h2><p class="text-sm">{{ trans_choice(':count line|:count lines', $items->count(), ['count' => $items->count()]) }}</p></div>
            @include('modules.owner_pack.quotations._lines', ['tick' => $draft && $canManage, 'ticked' => $ticked])
            @include('modules.owner_pack.quotations._totals')
            @if ($draft && $canManage)
                <details class="border-t border-rule" @if ($errors->has('boq_item_ids')) open @endif>
                    <summary class="link cursor-pointer px-4 py-3 text-sm">{{ __('Change the lines') }}</summary>
                    <form method="POST" action="{{ route('quotations.update', $quotation) }}">
                        @csrf @method('PATCH')
                        @include('modules.owner_pack.quotations._boq-lines', ['ticked' => $items->pluck('boq_item_id')->filter()->all()])
                        <div class="border-t border-rule p-4"><button class="btn btn-ghost">{{ __('Copy the ticked lines again') }}</button></div>
                    </form>
                </details>
            @endif
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Payment stages') }}</h2></div>
            @forelse ($stages as $stage)
                <div class="border-b border-rule px-4 py-3 last:border-b-0">
                    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                        <div class="min-w-0">
                            <p class="font-semibold"><span class="num text-xs text-ink-3">{{ $loop->iteration }}</span> {{ $stage->title }}</p>
                            <p class="text-xs text-ink-3">{{ $stage->isPercent() ? __(':pct of the total', ['pct' => QuotationMath::plain($stage->percent).'%']) : __('A typed amount') }}</p>
                            @if ($stage->condition_text)<p class="mt-0.5 text-xs text-ink-2">{{ $stage->condition_text }}</p>@endif
                        </div>
                        <p class="num font-medium">{{ inr($stage->amount) }}</p>
                    </div>
                    @if ($quotation->isIssued())
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                            @if ($stage->isReached())
                                <span class="stamp border-moss text-moss">{{ __('Reached') }}</span>
                                <span class="text-ink-3">{{ $date($stage->reached_on) }}@if ($stage->reachedBy) · {{ $stage->reachedBy->name }}@endif</span>
                            @else
                                <span class="stamp border-ink-3 text-ink-3">{{ __('Not reached yet') }}</span>
                            @endif
                            @if ($canManage && $replacedBy === null)
                                <form method="POST" action="{{ route('quotation-stages.reached', $stage) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @if ($stage->isReached())
                                        <input type="hidden" name="reached" value="0">
                                        <button class="btn btn-ghost py-1 text-xs">{{ __('Undo') }}</button>
                                    @else
                                        <input type="hidden" name="reached" value="1">
                                        <input type="date" name="reached_on" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="input w-auto py-1 text-xs" aria-label="{{ __('Reached on') }}">
                                        <button class="btn btn-ghost py-1 text-xs">{{ __('Mark as reached') }}</button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    @else
                        @if ($mark = $carried['kept'][$stage->id] ?? null)
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span class="stamp border-moss text-moss">{{ __('Reached on revision :n', ['n' => $carried['from']->revision]) }}</span>
                                <span class="text-ink-3">{{ $date($mark->reached_on) }}@if ($mark->reachedBy) · {{ $mark->reachedBy->name }}@endif · {{ __('Kept when this revision is issued, unless the stage is changed.') }}</span>
                            </div>
                        @endif
                        @if ($canManage)
                            <details class="mt-1" @if (old('_form') === 'stage-'.$stage->id) open @endif>
                                <summary class="link cursor-pointer text-xs">{{ __('Change') }}</summary>
                                <form method="POST" action="{{ route('quotation-stages.update', $stage) }}" class="mt-2">
                                    @csrf @method('PATCH')
                                    @include('modules.owner_pack.quotations._stage-fields', ['stage' => $stage, 'key' => $loop->iteration, 'button' => new \Illuminate\Support\HtmlString('<button class="btn btn-ghost">'.e(__('Save stage')).'</button>')])
                                </form>
                                <form method="POST" action="{{ route('quotation-stages.destroy', $stage) }}" class="mt-2" onsubmit="return confirm(@js(__('Remove this stage?')))">@csrf @method('DELETE')<button class="btn btn-ghost py-1 text-xs">{{ __('Remove') }}</button></form>
                            </details>
                        @endif
                    @endif
                </div>
            @empty
                <p class="px-4 py-3 text-sm text-ink-3">{{ __('No payment stages. The quotation then states the total and no schedule.') }}</p>
            @endforelse
            @if (($carried['dropped'] ?? []) !== [])
                <div class="border-t border-rule bg-haldi/10 px-4 py-3 text-sm" role="status">
                    <p>{{ __('These stages are marked as reached on revision :n, and no stage of this draft is the same. They are issued as not reached: mark them again once this revision is issued.', ['n' => $carried['from']->revision]) }}</p>
                    <ul class="mt-1 list-disc pl-5 text-xs">
                        @foreach ($carried['dropped'] as $mark)
                            <li>{{ $mark->title }} · {{ $date($mark->reached_on) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($stages->isNotEmpty())
                <dl class="border-t border-rule text-sm">
                    <div class="flex justify-between px-4 py-2"><dt>{{ __('Total of the stages') }}</dt><dd class="num">{{ inr($stagesTotal) }}</dd></div>
                    @if ($stagesBalance->isPositive())
                        <div class="flex justify-between border-t border-rule px-4 py-2"><dt>{{ __('Balance not covered by a stage') }}</dt><dd class="num">{{ inr($stagesBalance) }}</dd></div>
                    @endif
                </dl>
            @endif
            @if ($draft && $canManage)
                <form method="POST" action="{{ route('quotations.stages.store', $quotation) }}" class="border-t border-rule p-4">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ old('_form') === 'add-stage' ? old('client_uuid', (string) \Illuminate\Support\Str::uuid()) : (string) \Illuminate\Support\Str::uuid() }}">
                    <p class="mb-2 text-xs text-ink-3">{{ __('A stage is a share of the total or a typed amount. When every stage is a percentage they must add up to the whole of the total, and the last stage takes what is left so the stages add up to the paisa.') }}</p>
                    @include('modules.owner_pack.quotations._stage-fields', ['stage' => null, 'key' => 'new', 'button' => new \Illuminate\Support\HtmlString('<button class="btn btn-primary">'.e(__('Add stage')).'</button>')])
                </form>
            @endif
        </section>

        <section class="card" id="check">
            <div class="card-head"><h2 class="card-title">{{ $draft ? __('Check and issue') : __('Cover note') }}</h2></div>
            <div class="space-y-3 p-4">
                @if ($note === null)
                    @if ($draft && $canManage)
                        <p class="error">{{ __('The cover note cannot be shown from the saved figures. Save the draft again.') }}</p>
                    @elseif ($draft)
                        <p class="error">{{ __('The cover note cannot be shown from the saved figures.') }}</p>
                    @else
                        <p class="error">{{ __('The cover note of this issued quotation cannot be shown now. Its lines, totals and stages above stand as issued.') }}</p>
                        @if ($canManage && $replacedBy === null && $openRevision === null)
                            <p class="text-sm text-ink-2">{{ __('To give the client a cover note, make a revision below and issue it.') }}</p>
                        @endif
                    @endif
                @else
                    @if ($draft)
                        <p class="text-sm text-ink-2">{{ __('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.') }}</p>
                    @endif
                    <div class="space-y-2 rounded-[3px] border border-rule bg-paper/60 p-3 text-sm" lang="{{ $quotation->language }}">
                        @include('modules.owner_pack.quotations._note', ['slots' => $noteSlots, 'marked' => $draft])
                    </div>
                    @if ($draft && $canManage)
                        <p class="text-xs text-ink-3">{{ __('The marked figures were placed by the app from this quotation. Tick each one below after you have checked it, and tick each line in the table above.') }}</p>
                        @error('number_ticks')<p class="error">{{ $message }}</p>@enderror
                        @error('facts')<p class="error">{{ $message }}</p>@enderror
                        <form id="issue-form" method="POST" action="{{ route('quotations.issue', $quotation) }}">
                            @csrf
                            <input type="hidden" name="fact_fingerprint" value="{{ $note->fingerprint }}">
                            <input type="hidden" name="text_fingerprint" value="{{ $textFingerprint }}">
                            <ul class="space-y-1.5 text-sm">
                                @foreach ($tickList as $row)
                                    <li>
                                        <label class="flex cursor-pointer items-start gap-2">
                                            <input type="checkbox" name="number_ticks[]" value="{{ $row['id'] }}" class="mt-1" @checked(in_array($row['id'], $ticked, true))>
                                            <span>{{ $row['label'] }}: <span class="num font-medium">{{ $row['value'] }}</span>@if ($row['context']) <span class="text-xs text-ink-3">({{ $row['context'] }})</span>@endif</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            <button class="btn btn-primary mt-4">{{ __('Issue quotation') }}</button>
                            <p class="hint">{{ __('An issued quotation cannot be changed. A change is a new revision.') }}</p>
                        </form>
                    @endif
                @endif
            </div>
        </section>

        @if ($quotation->isIssued())
            <section class="card" x-data="{ copied: false }">
                <div class="card-head"><h2 class="card-title">{{ __('Share with the client') }}</h2></div>
                <div class="space-y-3 p-4 text-sm">
                    @error('share')<p class="error">{{ $message }}</p>@enderror
                    @error('facts')<p class="error">{{ $message }}</p>@enderror
                    @if ($quotation->isShared())
                        <p>{{ __('A link is open until :date. Anyone who has the link can read this quotation without signing in, so send it only to your client.', ['date' => $date($quotation->share_expires_at)]) }}</p>
                        @if ($canManage)
                            <div class="flex gap-2">
                                <input readonly value="{{ $shareUrl }}" class="input num text-xs" aria-label="{{ __('Link to the quotation') }}" x-ref="link">
                                <button type="button" class="btn btn-ghost" @click="navigator.clipboard.writeText($refs.link.value); copied = true" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost">{{ __('Send on WhatsApp') }}</a>
                                <form method="POST" action="{{ route('quotations.share.revoke', $quotation) }}" onsubmit="return confirm(@js(__('Withdraw the link? The client will no longer be able to open it.')))">@csrf<button class="btn btn-danger">{{ __('Withdraw the link') }}</button></form>
                                <form method="POST" action="{{ route('quotations.share', $quotation) }}" onsubmit="return confirm(@js(__('Make a new link? The link you sent earlier will stop working.')))">@csrf<button class="btn btn-ghost">{{ __('Make a new link') }}</button></form>
                            </div>
                        @endif
                    @else
                        <p>
                            @if ($quotation->share_token === null)
                                {{ __('No link has been made yet.') }}
                            @elseif ($quotation->share_revoked_at !== null)
                                {{ __('The link was withdrawn. It no longer opens.') }}
                            @else
                                {{ __('The link has passed its date. It no longer opens.') }}
                            @endif
                        </p>
                        @if ($canManage)
                            <form method="POST" action="{{ route('quotations.share', $quotation) }}">@csrf<button class="btn btn-primary">{{ __('Make a link') }}</button></form>
                            <p class="hint">{{ trans_choice('The link stays open for :count day and you send it yourself.|The link stays open for :count days and you send it yourself.', config('modules.owner_pack.share_link_days'), ['count' => config('modules.owner_pack.share_link_days')]) }}</p>
                        @endif
                    @endif
                </div>
            </section>

            @if ($canManage && $replacedBy === null && $openRevision === null)
                <form method="POST" action="{{ route('quotations.revise', $quotation) }}">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button class="btn btn-ghost">{{ __('Make a revision') }}</button>
                    <p class="hint">{{ __('Starts the next revision as a draft with the same lines and stages. This one stays as issued.') }}</p>
                </form>
            @endif
        @endif
    </div>
@endsection
