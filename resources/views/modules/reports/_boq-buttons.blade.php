{{-- Excel buttons for the BOQ page header. Shown only on plans that include reports. --}}
@if (auth()->user()->organization->allowsFeature('reports'))
    <a href="{{ route('boq.excel.export', $project) }}" class="btn btn-ghost">{{ __('Export Excel') }}</a>
    @can('boq.manage')
        <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-ghost">{{ __('Import Excel or PDF') }}</a>
    @endcan
@endif
