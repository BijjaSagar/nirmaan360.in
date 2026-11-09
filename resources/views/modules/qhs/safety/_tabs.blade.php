{{-- Safety sections. Expects $project and $active (incidents or toolbox). --}}
<div class="mb-4 flex flex-wrap gap-1.5 text-sm">
    <a href="{{ route('incidents.index', $project) }}" class="btn {{ $active === 'incidents' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Incidents and near misses') }}</a>
    <a href="{{ route('incidents.toolbox.index', $project) }}" class="btn {{ $active === 'toolbox' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Toolbox talks') }}</a>
    @can('checklist.view')<a href="{{ route('inspections.index', [$project, 'category' => 'ppe']) }}" class="btn btn-ghost">{{ __('PPE checks') }}</a>@endcan
</div>
