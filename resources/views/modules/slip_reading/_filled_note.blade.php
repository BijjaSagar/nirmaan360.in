{{--
    Who filled a saved record from a photo reading, and which fields they typed over rather than
    confirmed. Field names only: no value read from the photo is shown or kept.

    Expects $reading (a used SlipRead) and $labels (field name => words).
--}}
@php
    $noteBy = \App\Models\User::query()->where('organization_id', $reading->organization_id)->find($reading->confirmed['by'] ?? null);
    $noteAt = filled($reading->confirmed['at'] ?? null) ? \Illuminate\Support\Carbon::parse($reading->confirmed['at'])->timezone(config('app.timezone')) : $reading->updated_at;
    $noteTypedOver = collect($reading->confirmed['typed_over'] ?? [])->map(fn ($field) => $labels[$field] ?? null)->filter()->values();
@endphp
<div class="rounded-[3px] border border-rule bg-paper-2/60 px-3 py-2 text-sm sm:col-span-2">
    <p>{{ __('Filled from a photo. Confirmed field by field by :name on :date', ['name' => $noteBy?->name ?? '—', 'date' => $noteAt?->format('d M Y') ?? '—']) }}</p>
    <p class="text-ink-2">{{ $noteTypedOver->isEmpty() ? __('No field was typed over.') : __('Typed over by hand: :fields', ['fields' => $noteTypedOver->join(', ')]) }}</p>
</div>
