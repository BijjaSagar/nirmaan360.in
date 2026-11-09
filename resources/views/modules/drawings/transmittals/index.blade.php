@extends('layouts.app')
@section('title', __('Transmittals'))
@section('content')
    <x-page-header :title="__('Transmittals')" :subtitle="$project->name">
        @can('drawings.manage')<a href="{{ route('drawings.transmittals.create', $project) }}" class="btn btn-primary">{{ __('New transmittal') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($transmittals->isEmpty())
            <x-empty :title="__('No transmittals yet')" :note="__('A transmittal records which revisions went to whom, and when.')" />
        @else
            <table class="ledger min-w-[720px]">
                <thead><tr><th>{{ __('Number') }}</th><th class="w-[34%]">{{ __('Subject') }}</th><th>{{ __('To') }}</th><th>{{ __('Purpose') }}</th><th class="r">{{ __('Items') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($transmittals as $transmittal)
                    <tr>
                        <td class="num text-xs"><a href="{{ route('drawings.transmittals.show', $transmittal) }}" class="link">{{ $transmittal->number }}</a></td>
                        <td>{{ $transmittal->subject }}</td>
                        <td>{{ $transmittal->recipient_name }}</td>
                        <td>{{ __(\Illuminate\Support\Str::headline($transmittal->purpose)) }}</td>
                        <td class="r">{{ $transmittal->items_count }}</td>
                        <td class="num text-xs">{{ $transmittal->issued_at->format('d M Y') }}</td>
                        <td><x-stamp :status="$transmittal->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $transmittals->links() }}</div>
        @endif
    </div>
@endsection
