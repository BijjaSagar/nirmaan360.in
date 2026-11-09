{{-- How a concrete line is entered, for someone who may look but not change it. --}}
@if ($row['mix']['mode'] === 'rmc')
    <p class="text-sm text-ink-2">{{ __('Entered by the cubic metre, as ready-mix (RMC).') }}</p>
@else
    <dl class="grid gap-2 text-sm sm:grid-cols-3">
        @foreach ($roles as $role => $label)
            @if (isset($row['mix']['rows'][$role]))
                @php $ingredient = $row['mix']['rows'][$role]; @endphp
                <div>
                    <dt class="eyebrow text-ink-3">{{ __($label) }}</dt>
                    <dd>{{ $ingredient['material'] }}</dd>
                    <dd class="num">{{ fmt_qty($ingredient['qty']) }} {{ $ingredient['unit'] }}</dd>
                </div>
            @endif
        @endforeach
    </dl>
@endif
