{{-- The text of a shared update as it was approved, one paragraph per sentence block. $slots: a list of id and text. Every piece is escaped. --}}
@foreach ($slots as $slot)
    <p class="whitespace-pre-line" data-slot="{{ $slot['id'] }}">{{ $slot['text'] }}</p>
@endforeach
