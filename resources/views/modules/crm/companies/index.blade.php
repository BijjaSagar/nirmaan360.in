@extends('layouts.app')
@section('title', __('Companies'))
@section('content')
    @php
        $types = \App\Services\Crm\Vocab::partyTypes();
        $current = $filters['type'] ?? '';
        $query = array_filter($filters);
    @endphp
    <x-page-header :title="__('Companies')" :subtitle="__('Clients, consultants, subcontractors and vendors. The same list as Contacts.')">
        @can('crm.manage')<a href="{{ route('crm.companies.create') }}" class="btn btn-primary">{{ __('Add company') }}</a>@endcan
    </x-page-header>

    <div class="-mx-4 mb-3 flex gap-1.5 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
        <a href="{{ route('crm.companies.index', array_diff_key($query, ['type' => 1])) }}" class="btn min-h-9 shrink-0 {{ $current === '' ? 'btn-ink' : 'btn-ghost' }}">{{ __('All') }}</a>
        @foreach ($types as $key => $label)
            <a href="{{ route('crm.companies.index', [...$query, 'type' => $key]) }}" class="btn min-h-9 shrink-0 {{ $current === $key ? 'btn-ink' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" action="{{ route('crm.companies.index') }}" class="mb-4 grid gap-2 sm:grid-cols-3">
        @if ($current !== '')<input type="hidden" name="type" value="{{ $current }}">@endif
        <input name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="{{ __('Search name, person, phone or email') }}" aria-label="{{ __('Search') }}">
        <select name="tag" class="input" aria-label="{{ __('Tags') }}">
            <option value="">{{ __('Any tag') }}</option>
            @foreach ($tagOptions as $option)<option value="{{ $option }}" @selected(($filters['tag'] ?? '') === $option)>{{ $option }}</option>@endforeach
        </select>
        <button class="btn btn-ghost">{{ __('Filter') }}</button>
    </form>

    <div class="card overflow-x-auto">
        @if ($companies->isEmpty())
            <x-empty :title="__('No companies found')" :note="__('Add a company, or convert a lead into one.')" />
        @else
            <table class="ledger min-w-[680px]">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Type') }}</th><th>{{ __('Contact person') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Tags') }}</th></tr></thead>
                <tbody>
                @foreach ($companies as $company)
                    <tr>
                        <td><a href="{{ route('crm.companies.show', $company) }}" class="link">{{ $company->name }}</a></td>
                        <td>{{ $types[$company->type] ?? $company->type }}</td>
                        <td>{{ $company->contact_person ?? '—' }}</td>
                        <td class="num text-xs">{{ $company->phone ?? '—' }}</td>
                        <td>@include('modules.crm.partials.tags', ['tags' => $profiles[$company->id]->tags ?? [], 'route' => 'crm.companies.index'])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $companies->links() }}</div>
@endsection
