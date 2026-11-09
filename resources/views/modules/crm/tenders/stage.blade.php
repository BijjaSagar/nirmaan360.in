{{-- Stage controls and the convert prompt. Shown once after the facts on a phone and once in the side column on a wide screen, so $p keeps the ids apart. --}}
@php $stages = \App\Services\Crm\Vocab::stages(); @endphp
<section class="card" x-data="{ outcome: @js($closing ?? '') }">
    <div class="card-head"><h2 class="card-title">{{ __('Stage') }}</h2>@include('modules.crm.partials.stage', ['stage' => $tender->stage])</div>
    <div class="space-y-3 p-4">
        @if ($tender->isOpen())
            @can('crm.manage')
                <form method="POST" action="{{ route('crm.tenders.move', $tender) }}" class="flex gap-2">
                    @csrf
                    <select name="stage" class="input min-h-10" aria-label="{{ __('Move to') }}">
                        @foreach (\App\Models\CrmTender::OPEN_STAGES as $stage)<option value="{{ $stage }}" @selected($stage === $tender->stage)>{{ $stages[$stage] }}</option>@endforeach
                    </select>
                    <button class="btn btn-ghost min-h-10">{{ __('Move') }}</button>
                </form>
                <div>
                    <p class="label">{{ __('Close this tender as') }}</p>
                    <div class="grid grid-cols-3 gap-1.5">
                        @foreach (\App\Models\CrmTender::CLOSED_STAGES as $stage)
                            <button type="button" @click="outcome = '{{ $stage }}'" :class="outcome === '{{ $stage }}' ? 'btn-ink' : 'btn-ghost'" class="btn min-h-10 px-2">{{ $stages[$stage] }}</button>
                        @endforeach
                    </div>
                </div>
                <form x-show="outcome" x-cloak method="POST" action="{{ route('crm.tenders.close', $tender) }}" class="space-y-3 border-t border-rule pt-3">
                    @csrf
                    <input type="hidden" name="outcome" :value="outcome">
                    @foreach (\App\Models\CrmTender::CLOSED_STAGES as $stage)
                        <div x-show="outcome === '{{ $stage }}'">
                            <label class="label" for="{{ $p }}reason-{{ $stage }}">{{ \App\Services\Crm\Vocab::reasonKinds()[$stage] }}</label>
                            <select id="{{ $p }}reason-{{ $stage }}" name="outcome_reason_id" class="input" :disabled="outcome !== '{{ $stage }}'">
                                <option value="">{{ $stage === 'won' ? __('No reason') : __('Choose a reason') }}</option>
                                @foreach ($reasonOptions[$stage] as $reason)<option value="{{ $reason->id }}">{{ $reason->label }}</option>@endforeach
                            </select>
                        </div>
                    @endforeach
                    <div x-show="outcome === 'won'">
                        <label class="label" for="{{ $p }}awarded">{{ __('Awarded value (₹)') }}</label>
                        <input id="{{ $p }}awarded" name="awarded_value" type="number" step="0.01" min="0" inputmode="decimal" class="input num" :disabled="outcome !== 'won'">
                        <p class="hint">{{ __('Leave blank to use our quoted value.') }}</p>
                    </div>
                    <div x-show="outcome === 'lost'" class="grid gap-3">
                        <div>
                            <label class="label" for="{{ $p }}winner">{{ __('Who won') }}</label>
                            <input id="{{ $p }}winner" name="winner_name" class="input" maxlength="160" :disabled="outcome !== 'lost'">
                        </div>
                        <div>
                            <label class="label" for="{{ $p }}winning">{{ __('Winning value (₹)') }}</label>
                            <input id="{{ $p }}winning" name="winning_value" type="number" step="0.01" min="0" inputmode="decimal" class="input num" :disabled="outcome !== 'lost'">
                        </div>
                    </div>
                    <div>
                        <label class="label" for="{{ $p }}closed">{{ __('Closed on') }}</label>
                        <input id="{{ $p }}closed" name="closed_on" type="date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" class="input">
                    </div>
                    <div>
                        <label class="label" for="{{ $p }}note">{{ __('Note') }}</label>
                        <textarea id="{{ $p }}note" name="outcome_note" rows="2" class="input" maxlength="2000"></textarea>
                    </div>
                    <button class="btn btn-primary w-full">{{ __('Close tender') }}</button>
                </form>
            @endcan
        @else
            <div class="space-y-1 text-sm">
                <p><span class="text-ink-3">{{ __('Closed on') }}</span> <span class="num">{{ $tender->closed_on?->format('d M Y') }}</span></p>
                @if ($tender->reason)<p><span class="text-ink-3">{{ __('Reason') }}</span> {{ $tender->reason->label }}</p>@endif
                @if ($tender->winner_name)<p><span class="text-ink-3">{{ __('Who won') }}</span> {{ $tender->winner_name }}@if ($tender->winning_value) · <span class="num">{{ inr($tender->winning_value) }}</span>@endif</p>@endif
                @if ($tender->outcome_note)<p class="whitespace-pre-line text-ink-2">{{ $tender->outcome_note }}</p>@endif
            </div>
            @if ($tender->isConverted())
                <p class="text-sm text-ink-3">{{ __('Converted to a project on :date.', ['date' => $tender->converted_at?->format('d M Y')]) }}</p>
            @else
                @can('crm.manage')
                    <form method="POST" action="{{ route('crm.tenders.reopen', $tender) }}" class="flex gap-2 border-t border-rule pt-3">
                        @csrf
                        <select name="stage" class="input min-h-10" aria-label="{{ __('Reopen as') }}">
                            @foreach (\App\Models\CrmTender::OPEN_STAGES as $stage)<option value="{{ $stage }}" @selected($stage === 'bidding')>{{ $stages[$stage] }}</option>@endforeach
                        </select>
                        <button class="btn btn-ghost min-h-10">{{ __('Reopen') }}</button>
                    </form>
                @endcan
            @endif
        @endif
    </div>
</section>

@if ($conversion)
    @can('crm.convert')@can('project.manage')
        <section class="card mt-5 border-moss">
            <div class="card-head"><h2 class="card-title">{{ __('Won. Start the project.') }}</h2></div>
            <div class="space-y-2 p-4 text-sm">
                <p>{{ __(':lines BOQ lines and :documents documents will be carried to the new project.', ['lines' => $conversion['boq_lines'], 'documents' => $conversion['documents']]) }}</p>
                <a href="{{ route('crm.tenders.convert', $tender) }}" class="btn btn-primary w-full">{{ __('Convert to project') }}</a>
            </div>
        </section>
    @endcan @endcan
@endif
