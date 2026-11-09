@extends('layouts.app')
@section('title', __('Projects'))
@section('content')
    <x-page-header :title="__('Projects')">
        @can('project.manage')<a href="{{ route('projects.create') }}" class="btn btn-primary">{{ __('New project') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($projects->isEmpty())
            <x-empty :title="__('No projects yet')" :note="__('Create your first project, then add its BOQ.')" />
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Project') }}</th><th>{{ __('Client') }}</th><th>{{ __('Location') }}</th><th class="r">{{ __('Contract value') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($projects as $row)
                    <tr>
                        <td class="num text-xs">{{ $row->code }}</td>
                        <td><a href="{{ route('projects.show', $row) }}" class="link">{{ $row->name }}</a></td>
                        <td>{{ $row->client?->name ?? '—' }}</td>
                        <td>{{ $row->location ?? '—' }}</td>
                        <td class="r">{{ inr($row->contract_value) }}</td>
                        <td><x-stamp :status="$row->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $projects->links() }}</div>
@endsection
