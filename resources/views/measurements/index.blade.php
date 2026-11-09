@extends('layouts.app')
@section('title', __('Measurement book'))
@section('content')
    <x-page-header :title="__('Measurement book')" :subtitle="$project->name">
        @can('measurement.create')<a href="{{ route('measurements.create', $project) }}" class="btn btn-primary">{{ __('New entry') }}</a>@endcan
    </x-page-header>

    <div class="mb-3 flex flex-wrap gap-1.5">
        <a href="{{ route('measurements.index', $project) }}" class="btn {{ $status ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
        @foreach (['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'REJECTED'] as $option)
            <a href="{{ route('measurements.index', [$project, 'status' => $option]) }}" class="btn {{ $status === $option ? 'btn-ink' : 'btn-ghost' }}">{{ __(\Illuminate\Support\Str::headline(strtolower($option))) }}</a>
        @endforeach
    </div>

    <div class="card overflow-x-auto">
        @if ($entries->isEmpty())
            <x-empty :title="__('No measurements here')" :note="__('Record Nos × L × B × D against a BOQ item and send it for approval.')" />
        @else
            <table class="ledger min-w-[900px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('BOQ item') }}</th><th>{{ __('Location') }}</th><th class="r">{{ __('Nos') }}</th><th class="r">L</th><th class="r">B</th><th class="r">D</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td><a href="{{ route('measurements.show', $entry) }}" class="link num text-xs">{{ $entry->measured_on->format('d M Y') }}</a></td>
                        <td><span class="num text-xs">{{ $entry->boqItem->code }}</span> {{ \Illuminate\Support\Str::limit($entry->boqItem->description, 48) }}</td>
                        <td>{{ $entry->location ?? '—' }}</td>
                        <td class="r">{{ (float) $entry->nos }}</td>
                        <td class="r">{{ $entry->length ?? '—' }}</td>
                        <td class="r">{{ $entry->breadth ?? '—' }}</td>
                        <td class="r">{{ $entry->depth ?? '—' }}</td>
                        <td class="r {{ \App\Support\Dec::of($entry->qty)->isNegative() ? 'text-laterite' : '' }}">{{ fmt_qty($entry->qty) }} {{ $entry->boqItem->unit }}</td>
                        <td class="whitespace-nowrap">
                            <x-stamp :status="$entry->status" />
                            @if ($entry->deviation)<x-stamp status="deviation" />@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $entries->links() }}</div>
@endsection
