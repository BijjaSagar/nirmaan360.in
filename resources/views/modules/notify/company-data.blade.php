@extends('layouts.app')
@section('title', __('Company data'))
@section('content')
    <x-page-header :title="__('Company data')" :subtitle="__('Export or delete everything this company holds')" />

    @unless ($isOwner)
        <p class="mb-4 max-w-5xl rounded-[3px] border border-rule bg-paper-2/60 px-3 py-2 text-sm">{{ __('Only a company owner can export or delete this data.') }}</p>
    @endunless

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <section class="card self-start">
            <div class="card-head"><h2 class="card-title">{{ __('Export') }}</h2></div>
            <div class="space-y-3 p-4">
                <p class="text-sm text-ink-3">{{ __('A zip file with every record as a spreadsheet, plus all uploaded photos and bills. Passwords and keys are left out. The file is kept for 7 days.') }}</p>
                @if ($isOwner)
                    <form method="POST" action="{{ route('notify.data.export') }}">
                        @csrf
                        <button class="btn btn-primary" @disabled($exportInProgress)>{{ $exportInProgress ? __('Preparing your export...') : __('Export all company data') }}</button>
                        @error('export')<p class="error">{{ $message }}</p>@enderror
                    </form>
                @endif
            </div>
            @if ($exports->isNotEmpty())
                <div class="overflow-x-auto border-t border-rule">
                    <table class="ledger">
                        <thead><tr><th>{{ __('Requested') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('File') }}</th></tr></thead>
                        <tbody>
                        @foreach ($exports as $export)
                            <tr>
                                <td class="num whitespace-nowrap">{{ $export->created_at->format('d M Y, H:i') }}</td>
                                <td>{{ __(\Illuminate\Support\Str::headline($export->status)) }}</td>
                                <td class="r whitespace-nowrap">
                                    @if ($isOwner && $export->isDownloadable())
                                        <a href="{{ route('notify.data.download', $export) }}" class="link">{{ __('Download') }}</a>
                                    @else
                                        <span class="text-ink-3">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card self-start">
            <div class="card-head"><h2 class="card-title">{{ __('Delete this company') }}</h2>@if ($deletion)<span class="stamp border-laterite text-laterite">{{ __('Scheduled') }}</span>@endif</div>
            <div class="space-y-3 p-4">
                @if ($deletion)
                    <p class="text-sm">{{ __('Everything will be erased on :date. The company keeps working until then.', ['date' => $deletion->purge_after->format('d M Y')]) }}</p>
                    @if ($isOwner)
                        @if ($deletedOnCancel)
                            <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ __('Cancelling keeps the company. :name asked for their own account to be deleted, so it is deleted when you cancel.', ['name' => $deletedOnCancel->name]) }}</p>
                        @endif
                        <form method="POST" action="{{ route('notify.data.cancel') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-primary">{{ __('Cancel the deletion') }}</button>
                        </form>
                    @endif
                @else
                    <p class="text-sm text-ink-3">{{ __('After :days days every project, bill, photo, worker and user in this company is erased for good. This cannot be undone. You can cancel at any time during those :days days. Export your data first.', ['days' => $graceDays]) }}</p>
                    @if ($isOwner)
                        <form method="POST" action="{{ route('notify.data.delete') }}" autocomplete="off" class="space-y-3">
                            @csrf
                            <x-field :label="__('Type :name to confirm', ['name' => $organization->name])" name="confirm_name"><input id="confirm_name" name="confirm_name" class="input" autocomplete="off" required></x-field>
                            <x-field :label="__('Your password')" name="password"><input id="password" name="password" type="password" class="input" required autocomplete="current-password"></x-field>
                            <button class="btn btn-danger">{{ __('Delete this company') }}</button>
                        </form>
                    @endif
                @endif
            </div>
        </section>
    </div>
@endsection
