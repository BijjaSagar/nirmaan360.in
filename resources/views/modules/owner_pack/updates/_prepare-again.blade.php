{{-- An approved update that a share would refuse (its records changed, or its quotation was replaced) cannot be shared again. This offers a new update for the same week and language. --}}
@can('owner_pack.progress')
    <a href="{{ route('owner-updates.create', [$project, 'week_start' => $update->week_start->toDateString(), 'language' => $update->language]) }}" class="btn btn-primary">{{ __('Prepare a new update for this week') }}</a>
    <p class="hint">{{ __('The new update reads the records as they stand today. This one stays as it was approved.') }}</p>
@endcan
