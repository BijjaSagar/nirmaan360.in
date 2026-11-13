{{-- The fields a draft shares between the new form and the edit form. $quotation is null on the new form. --}}
@php
    // An existing draft shows what it holds, empty included. Only the new form starts from the project's client.
    $value = fn (string $field, mixed $default = null) => old($field, $quotation !== null ? $quotation->{$field} : $default);
    // Only the languages offered for documents a client reads; a draft keeps the one it has.
    $languages = array_intersect_key(config('buildos.locales'), array_flip(\App\Services\Wording\ReviewedLanguages::allowed(\App\Services\Wording\ReviewedLanguages::OWNER_PACK, \App\Models\ClientQuotation::LANGUAGES, $quotation?->language)));
@endphp
<x-field :label="__('Client from your list')" name="party_id" :hint="__('Optional. The name below is what the quotation prints.')">
    <select id="party_id" name="party_id" class="input">
        <option value="">{{ __('Not on the list') }}</option>
        @foreach ($clients as $client)
            <option value="{{ $client->id }}" @selected((string) $value('party_id', $project->client_id) === (string) $client->id)>{{ $client->name }}</option>
        @endforeach
    </select>
</x-field>
<x-field :label="__('Name on the quotation')" name="client_name">
    <input id="client_name" name="client_name" value="{{ $value('client_name', $project->client?->name) }}" maxlength="160" class="input" autocomplete="off">
</x-field>
<x-field :label="__('Language of the quotation')" name="language">
    <select id="language" name="language" class="input" required>
        @foreach ($languages as $code => $label)
            <option value="{{ $code }}" lang="{{ $code }}" @selected($value('language', $language ?? 'en') === $code)>{{ $label }}</option>
        @endforeach
    </select>
</x-field>
<x-field :label="__('GST percentage for this quotation')" name="gst_pct" :hint="__('Type the percentage that applies to this work. The app does not choose one. Leave it empty to quote without GST.')">
    <input id="gst_pct" name="gst_pct" value="{{ $value('gst_pct') }}" inputmode="decimal" class="input num" autocomplete="off">
</x-field>
<x-field :label="__('Valid until')" name="valid_until" :hint="__('Optional.')">
    <input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until', $quotation?->valid_until?->toDateString()) }}" class="input">
</x-field>
