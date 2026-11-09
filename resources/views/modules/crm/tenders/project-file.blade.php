@extends('layouts.app')
@section('title', __('Tender file'))
@section('content')
    <x-page-header :title="__('Tender file')" :subtitle="$project->name">
        @if ($tender)
            @can('crm.view')<a href="{{ route('crm.tenders.show', $tender) }}" class="btn btn-ghost">{{ __('Open tender') }}</a>@endcan
        @endif
    </x-page-header>

    @if ($tender)
        <section class="card mb-5">
            <div class="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="eyebrow text-ink-3">{{ __('Tender') }}</p><p class="font-semibold">{{ $tender->title }}</p></div>
                <div><p class="eyebrow text-ink-3">{{ __('Issuing authority') }}</p><p>{{ $tender->authority }}</p></div>
                <div><p class="eyebrow text-ink-3">{{ __('Tender number') }}</p><p>{{ $tender->reference ?? '—' }}</p></div>
                <div><p class="eyebrow text-ink-3">{{ __('Awarded value') }}</p><p class="num">{{ $tender->awarded_value !== null ? inr($tender->awarded_value) : '—' }}</p></div>
            </div>
        </section>
    @endif

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Documents') }}</h2></div>
        @forelse ($documents as $document)
            <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                <div class="min-w-0">
                    <a href="{{ route('crm.documents.download', $document) }}" class="link break-words">{{ $document->title }}</a>
                    <p class="text-xs text-ink-3">{{ \App\Services\Crm\Vocab::documentKinds()[$document->kind] ?? $document->kind }} · {{ number_format($document->size / 1024, 0) }} KB · {{ $document->created_at->format('d M Y') }}</p>
                </div>
            </div>
        @empty
            <x-empty :title="__('No tender documents')" :note="__('Documents kept on a tender appear here once it is converted to this project.')" />
        @endforelse
    </section>
@endsection
