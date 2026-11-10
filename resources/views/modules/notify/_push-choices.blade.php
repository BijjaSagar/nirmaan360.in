{{-- The kinds of push one person receives and their quiet hours. Drawn inside the settings form of staff and of a contractor login. --}}
@php($off = ! $push['connected'])
<section class="card">
    <div class="card-head">
        <h2 class="card-title">{{ __('What is pushed to your phone') }}</h2>
        @if ($off)<span class="stamp border-laterite text-laterite">{{ __('Not connected yet') }}</span>@endif
    </div>
    <div class="divide-y divide-rule">
        @foreach ($push['categories'] as $category)
            <div class="flex items-start justify-between gap-4 p-4">
                <div>
                    <label for="category-{{ $category['key'] }}" class="font-semibold">{{ $category['label'] }}</label>
                    <p class="text-sm text-ink-3">{{ $category['hint'] }}</p>
                </div>
                <input id="category-{{ $category['key'] }}" name="categories[{{ $category['key'] }}]" type="checkbox" value="1" class="mt-1 size-5 shrink-0" @checked($category['enabled']) @disabled($off)>
            </div>
        @endforeach

        <div class="p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <label for="quiet-hours" class="font-semibold">{{ __('Quiet hours') }}</label>
                    {{-- Only site alerts can be critical, so only someone who gets them is told about the exception. --}}
                    <p class="text-sm text-ink-3">{{ __('Between these times a push waits until the morning.') }} @if (collect($push['categories'])->contains('key', 'alerts')){{ __('A critical alert still comes through at once.') }}@endif</p>
                </div>
                <input id="quiet-hours" name="quiet_hours[enabled]" type="checkbox" value="1" class="mt-1 size-5 shrink-0" @checked($push['quiet_hours']['enabled']) @disabled($off)>
            </div>
            <div class="mt-3 grid max-w-sm grid-cols-2 gap-3">
                <x-field :label="__('From')" name="quiet_hours.from">
                    <input id="quiet_hours.from" name="quiet_hours[from]" type="time" value="{{ old('quiet_hours.from', $push['quiet_hours']['from']) }}" class="input num" @disabled($off)>
                </x-field>
                <x-field :label="__('Until')" name="quiet_hours.until">
                    <input id="quiet_hours.until" name="quiet_hours[until]" type="time" value="{{ old('quiet_hours.until', $push['quiet_hours']['until']) }}" class="input num" @disabled($off)>
                </x-field>
            </div>
            <p class="hint">{{ __('Times are on the India clock (IST).') }}</p>
        </div>
    </div>
</section>
