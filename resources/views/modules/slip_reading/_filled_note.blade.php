{{--
    Who filled a saved record from a photo reading, and which fields they typed over rather than
    confirmed. When the record was changed by hand after that, without a reading, which fields
    changed, by whom and when. Field names only: no value read from the photo is shown or kept.

    Expects $reading (a used SlipRead) and $labels (field name => words).
--}}
@php
    $notePerson = fn ($id) => $id === null ? null : \App\Models\User::query()->where('organization_id', $reading->organization_id)->find($id);
    $noteDate = fn ($at) => filled($at) ? \Illuminate\Support\Carbon::parse($at)->timezone(config('app.timezone')) : null;
    $noteNames = fn (array $fields) => collect($fields)->map(fn ($field) => $labels[$field] ?? null)->filter()->values();
    $noteBy = $notePerson($reading->confirmed['by'] ?? null);
    $noteAt = $noteDate($reading->confirmed['at'] ?? null) ?? $reading->updated_at;
    $noteTypedOver = $noteNames($reading->confirmed['typed_over'] ?? []);
    $noteLater = $reading->confirmed['changed_later'] ?? null;
    $noteLaterFields = is_array($noteLater) ? $noteNames($noteLater['fields'] ?? []) : collect();
@endphp
<div class="rounded-[3px] border border-rule bg-paper-2/60 px-3 py-2 text-sm sm:col-span-2">
    <p>{{ __('Filled from a photo. Confirmed field by field by :name on :date', ['name' => $noteBy?->name ?? '—', 'date' => $noteAt?->format('d M Y') ?? '—']) }}</p>
    <p class="text-ink-2">{{ $noteTypedOver->isEmpty() ? __('No field was typed over.') : __('Typed over by hand: :fields', ['fields' => $noteTypedOver->join(', ')]) }}</p>
    @if ($noteLaterFields->isNotEmpty())
        <p class="font-medium text-laterite">{{ __('Changed by hand later by :name on :date: :fields', [
            'name' => $notePerson($noteLater['by'] ?? null)?->name ?? '—',
            'date' => $noteDate($noteLater['at'] ?? null)?->format('d M Y') ?? '—',
            'fields' => $noteLaterFields->join(', '),
        ]) }}</p>
    @endif
</div>
