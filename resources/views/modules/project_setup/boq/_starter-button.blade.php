{{-- Offers the starter BOQ to someone who may manage the BOQ. Only for an empty BOQ. --}}
@can('boq.manage')
    <form method="POST" action="{{ route('project-setup.boq-setup.starter', $project) }}">
        @csrf
        <button class="btn btn-primary min-h-12 px-5 text-base">{{ __('Start from the standard 12 items') }}</button>
        <p class="hint mt-2">{{ __('You fill in the quantity and rate of each item. Nothing is priced for you.') }}</p>
    </form>
@endcan
