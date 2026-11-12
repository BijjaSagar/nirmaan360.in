{{--
    "Suggest rates" on the BOQ page: the button and the panel where the lines are picked. Lines with no rate
    are ticked to begin with. Shown only to people who manage the BOQ and may see money, on plans with reports.
    The rates themselves are looked up after the form is sent, from the company's own records.
--}}
@php
    use App\Services\RateHelper\UnitGate;
    use App\Support\Dec;
    use Illuminate\Support\Str;

    $viewer = auth()->user();
    $offered = $items->isNotEmpty()
        && $viewer->organization->allowsFeature('reports')
        && $viewer->can('boq.manage')
        && $viewer->can('money.view');
@endphp
@if ($offered)
    @php
        $max = (int) config('modules.rate_helper.max_lines', 100);
        $sent = old('form') === 'rate-suggest';
        $pickable = $items->reject(fn ($item) => UnitGate::key($item->unit) === null);
        // A refused form opens again with the same ticks, and only for lines on this page.
        $picked = $sent
            ? $pickable->pluck('id')->intersect(array_map('intval', array_filter((array) old('boq_item_ids', []), 'is_scalar')))->values()
            : $pickable->filter(fn ($item) => Dec::of($item->rate)->isZero())->take($max)->pluck('id')->values();
        $uuid = $sent && Str::isUuid((string) old('client_uuid')) ? (string) old('client_uuid') : (string) Str::uuid();
    @endphp
    <div x-data="{ open: @js($sent), picked: @js($picked->map(fn ($id) => (string) $id)->all()), max: @js($max) }">
        <button type="button" class="btn btn-ghost" @click="open = true">{{ __('Suggest rates') }}</button>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto bg-ink/50 p-4 text-left" @keydown.escape.window="open = false">
            <div class="card mx-auto my-4 w-full max-w-2xl" role="dialog" aria-modal="true" aria-labelledby="rate-suggest-title" @click.outside="open = false">
                <form method="POST" action="{{ route('rate-suggestions.store', $project) }}">
                    @csrf
                    <input type="hidden" name="form" value="rate-suggest">
                    <input type="hidden" name="client_uuid" value="{{ $uuid }}">
                    <div class="card-head"><h2 id="rate-suggest-title" class="card-title">{{ __('Suggest rates') }}</h2></div>
                    <div class="space-y-3 p-4">
                        <p class="text-sm text-ink-2">{{ __('Choose the lines to look up. The app searches your own rate library, your other projects, your past tenders and your purchases, and shows each rate with its source and date. No government schedule is loaded.') }}</p>
                        @if ($sent)
                            @error('boq_item_ids')<p class="error">{{ $message }}</p>@enderror
                            @error('boq_item_ids.*')<p class="error">{{ $message }}</p>@enderror
                            @error('client_uuid')<p class="error">{{ $message }}</p>@enderror
                        @endif
                        <div class="max-h-[70vh] divide-y divide-rule overflow-y-auto rounded-[3px] border border-rule">
                            @foreach ($items as $item)
                                @php $lumpSum = UnitGate::key($item->unit) === null; @endphp
                                <label class="flex min-h-11 items-start gap-3 px-3 py-2 text-sm {{ $lumpSum ? 'text-ink-3' : '' }}">
                                    <input type="checkbox" name="boq_item_ids[]" value="{{ $item->id }}" class="mt-1" x-model="picked" @disabled($lumpSum)>
                                    <span class="min-w-0 flex-1">
                                        <span class="num text-xs text-ink-3">{{ $item->code }}</span>
                                        {{ Str::limit($item->description, 120) }}
                                        @if ($lumpSum)
                                            <span class="block text-xs">{{ __('Lump-sum line: no rate is suggested.') }}</span>
                                        @endif
                                    </span>
                                    <span class="num whitespace-nowrap text-xs text-ink-3">{{ Dec::indian($item->rate) }} / {{ $item->unit }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p class="text-xs text-ink-3">
                            <span x-text="picked.length"></span> {{ __('chosen') }} · {{ __('At most :max lines at a time.', ['max' => $max]) }}
                        </p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                        <button type="button" class="btn btn-ghost" @click="open = false">{{ __('Cancel') }}</button>
                        <button class="btn btn-primary" :disabled="picked.length === 0 || picked.length > max">{{ __('Suggest rates') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
