@php
    $days = (int) $entry->work_date->startOfDay()->diffInDays(now()->startOfDay(), false);
@endphp
<article class="border-b border-rule px-4 py-3 last:border-b-0">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-semibold">{{ $entry->boqItem?->code }} · {{ $entry->boqItem?->description }}</p>
            <p class="text-xs text-ink-3">
                {{ $entry->floor ? ($entry->floor->parent?->name ? $entry->floor->parent->name.' › ' : '').$entry->floor->name : __('Whole project') }}
                · {{ $entry->enteredBy?->name }} · <span class="num">{{ $entry->work_date->format('d M') }}</span>
            </p>
        </div>
        <span class="stamp shrink-0 {{ $days >= 7 ? 'border-laterite text-laterite' : 'border-haldi text-[#8a6208]' }}">{{ $days <= 0 ? __('Today') : __(':days days', ['days' => $days]) }}</span>
    </div>
    <p class="mt-1.5 text-[15px] text-ink">{{ $entry->remark }}</p>
    @include('modules.site_progress._photos', ['photos' => $entry->photos])
    @can('progress.create')
        <details class="mt-2">
            <summary class="btn btn-ghost min-h-11 cursor-pointer">{{ __('Mark finished') }}</summary>
            <form method="POST" action="{{ route('site-progress.entries.resolve', $entry) }}" class="mt-2 grid gap-3 rounded-[3px] border border-rule bg-paper p-3 sm:w-96">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-field :label="__('Note (optional)')"><textarea name="remark" rows="2" maxlength="1000" class="input text-base"></textarea></x-field>
                <button class="btn btn-primary min-h-11">{{ __('Mark finished') }}</button>
            </form>
        </details>
    @endcan
</article>
