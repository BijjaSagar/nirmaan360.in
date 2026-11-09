@extends('layouts.app')
@section('title', __('Indents'))
@section('content')
    <x-page-header :title="__('Indents')" :subtitle="$project->name">
        @can('indent.create')<a href="{{ route('procurement.indents.create', $project) }}" class="btn btn-primary">{{ __('Raise indent') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($indents->isEmpty())
            <x-empty :title="__('No indents yet')" :note="__('An indent asks for material. Once approved it can go out for quotation.')" />
        @else
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Store') }}</th><th>{{ __('Required by') }}</th><th class="r">{{ __('Lines') }}</th><th>{{ __('Raised by') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($indents as $indent)
                    <tr>
                        <td><a href="{{ route('procurement.indents.show', $indent) }}" class="link num text-xs">{{ $indent->number }}</a></td>
                        <td>{{ $indent->store->name }}</td>
                        <td class="num text-xs">{{ $indent->required_by?->format('d M Y') ?? '—' }}</td>
                        <td class="r">{{ $indent->items_count }}</td>
                        <td>{{ $indent->creator?->name ?? '—' }}</td>
                        <td><x-stamp :status="$indent->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $indents->links() }}</div>
@endsection
