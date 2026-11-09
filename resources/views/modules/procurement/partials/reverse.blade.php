{{-- Pass $action (form URL) and $hint. --}}
@can('procurement.adjust')
    <details class="card mt-5">
        <summary class="card-head cursor-pointer font-semibold">{{ __('Reverse this document') }}</summary>
        <form method="POST" action="{{ $action }}" class="space-y-3 p-4">
            @csrf
            <p class="text-sm text-ink-3">{{ $hint }}</p>
            <x-field :label="__('Reason')" name="reason"><input id="reason" name="reason" class="input" maxlength="255" required></x-field>
            <button class="btn btn-danger">{{ __('Reverse') }}</button>
        </form>
    </details>
@endcan
