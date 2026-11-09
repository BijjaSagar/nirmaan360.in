{{-- Links to the project setup screens, in the BOQ page header. Shown on plans that include site control. --}}
<a href="{{ route('project-setup.boq-setup', $project) }}" class="btn btn-ghost">{{ __('BOQ setup') }}</a>
<a href="{{ route('project-setup.history', $project) }}" class="btn btn-ghost">{{ __('History') }}</a>
@can('money.overview')
    <a href="{{ route('project-setup.budget', $project) }}" class="btn btn-ghost">{{ __('Budget') }}</a>
@endcan
