@extends('layouts.app')
@section('title', __('Permissions'))
@section('content')
    @php
        $tones = ['not_started' => 'border-ink-3 text-ink-3', 'applied' => 'border-neel text-neel', 'approved' => 'border-moss text-moss', 'rejected' => 'border-laterite text-laterite'];
        $labels = ['not_started' => __('Not started'), 'applied' => __('Applied'), 'approved' => __('Approved'), 'rejected' => __('Rejected')];
    @endphp
    <div x-data="{ add: {{ $errors->any() && ! old('_permit') ? 'true' : 'false' }} }">
        <x-page-header :title="__('Permissions')" :subtitle="$project->name">
            @can('permit.manage')
                <button type="button" class="btn btn-primary min-h-11" @click="add = ! add"><x-icon name="plus" class="size-4" />{{ __('Add permission') }}</button>
            @endcan
        </x-page-header>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-stat :label="__('Approved')" :value="$counts['approved'].' / '.$counts['total']" :note="__('permissions')" />
            <x-stat :label="__('Waiting for the authority')" :value="$counts['applied']" />
            <x-stat :label="__('Rejected')" :value="$counts['rejected']" :accent="$counts['rejected'] > 0" />
            @if ($totalFee !== null)
                <div class="col-span-2 lg:col-span-1"><x-stat :label="__('Total fees')" :value="inr($totalFee)" :note="__('A separate head, not part of the BOQ')" /></div>
            @endif
        </div>

        <p class="mb-4 text-sm text-ink-3">{{ __('This list is optional. If the client already has every permission, skip it and start from the BOQ.') }}</p>

        @can('permit.manage')
            <form method="POST" action="{{ route('project-setup.permits.store', $project) }}" x-show="add" x-cloak class="card mb-5">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <div class="card-head"><h2 class="card-title">{{ __('Add permission') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    @include('modules.project_setup._permit-fields', ['permit' => null])
                </div>
                <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                    <button type="button" class="btn btn-ghost min-h-11" @click="add = false">{{ __('Cancel') }}</button>
                    <button class="btn btn-primary min-h-11">{{ __('Save permission') }}</button>
                </div>
            </form>
        @endcan

        @if ($permits->isEmpty())
            <div class="card">
                <x-empty :title="__('No permissions listed')" :note="__('List what is needed before building starts: building plan sanction, fire NOC and other local approvals.')" />
            </div>
        @else
            <div class="space-y-3">
                @foreach ($permits as $permit)
                    <details class="card" @if ((string) old('_permit') === (string) $permit->id) open @endif>
                        <summary class="flex min-h-14 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold">{{ $permit->name }}</span>
                                @if ($permit->authority)<span class="block text-sm text-ink-3">{{ $permit->authority }}</span>@endif
                            </span>
                            @if ($totalFee !== null && $permit->fee !== null)
                                <span class="num text-sm">{{ inr($permit->fee) }}</span>
                            @endif
                            <span class="stamp {{ $tones[$permit->status] ?? $tones['not_started'] }}">{{ $labels[$permit->status] ?? $labels['not_started'] }}</span>
                        </summary>

                        <div class="border-t border-rule">
                            <dl class="grid gap-x-6 gap-y-1 px-4 py-3 text-sm sm:grid-cols-3">
                                <div><dt class="eyebrow text-ink-3">{{ __('Applied on') }}</dt><dd class="num">{{ $permit->applied_on?->format('d M Y') ?? '—' }}</dd></div>
                                <div><dt class="eyebrow text-ink-3">{{ __('Approved on') }}</dt><dd class="num">{{ $permit->approved_on?->format('d M Y') ?? '—' }}</dd></div>
                                <div><dt class="eyebrow text-ink-3">{{ __('Documents') }}</dt><dd class="num">{{ $permit->files->count() }}</dd></div>
                                @if ($permit->documents_required)
                                    <div class="sm:col-span-3"><dt class="eyebrow text-ink-3">{{ __('Documents required') }}</dt><dd class="whitespace-pre-line">{{ $permit->documents_required }}</dd></div>
                                @endif
                                @if ($permit->remarks)
                                    <div class="sm:col-span-3"><dt class="eyebrow text-ink-3">{{ __('Remarks') }}</dt><dd class="whitespace-pre-line">{{ $permit->remarks }}</dd></div>
                                @endif
                            </dl>

                            <div class="border-t border-rule px-4 py-3">
                                <h3 class="eyebrow mb-2 text-ink-3">{{ __('Documents') }}</h3>
                                @forelse ($permit->files as $file)
                                    <div class="flex items-center justify-between gap-3 py-1.5 text-sm">
                                        <a href="{{ route('project-setup.permits.files.show', $file) }}" class="link min-w-0 truncate" target="_blank" rel="noopener">{{ $file->original_name }}</a>
                                        @can('permit.manage')
                                            <form method="POST" action="{{ route('project-setup.permits.files.destroy', $file) }}" onsubmit="return confirm(@js(__('Remove this document?')))">
                                                @csrf @method('DELETE')
                                                <button class="link text-xs text-laterite">{{ __('Remove') }}</button>
                                            </form>
                                        @endcan
                                    </div>
                                @empty
                                    <p class="text-sm text-ink-3">{{ __('No documents uploaded yet.') }}</p>
                                @endforelse

                                @can('permit.manage')
                                    <form method="POST" action="{{ route('project-setup.permits.files.store', $permit) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-2">
                                        @csrf
                                        <div class="min-w-48 flex-1">
                                            <label class="label" for="file-{{ $permit->id }}">{{ __('Add a document') }}</label>
                                            <input id="file-{{ $permit->id }}" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,image/*" class="input" required>
                                        </div>
                                        <button class="btn btn-ghost min-h-11">{{ __('Upload') }}</button>
                                    </form>
                                    <p class="hint">{{ __('PDF, photo or Word file, up to 10 MB.') }}</p>
                                @endcan
                            </div>

                            @can('permit.manage')
                                <form method="POST" action="{{ route('project-setup.permits.update', $permit) }}" class="border-t border-rule">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="_permit" value="{{ $permit->id }}">
                                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                                        @include('modules.project_setup._permit-fields', ['permit' => $permit])
                                    </div>
                                    <div class="flex justify-end border-t border-rule px-4 py-3">
                                        <button class="btn btn-primary min-h-11">{{ __('Save changes') }}</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('project-setup.permits.destroy', $permit) }}" class="border-t border-rule bg-paper/50 px-4 py-3" onsubmit="return confirm(@js(__('Remove this permission and its documents?')))">
                                    @csrf @method('DELETE')
                                    <button class="link text-sm text-laterite">{{ __('Remove this permission') }}</button>
                                </form>
                            @endcan
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
    </div>
@endsection
