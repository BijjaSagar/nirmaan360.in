@extends('layouts.app')
@section('title', __('Weekly update'))
@section('content')
    @php
        use App\Services\OwnerPack\OwnerUpdateDocument;
        use App\Services\Wording\Fact;
        use App\Services\Wording\FactFormat;

        $draft = $update->isDraft();
        $user = auth()->user();
        $canApprove = $user->can('owner_pack.manage') && $user->can('money.view');
        $seesPhotos = $user->can('dpr.view');
        $date = fn ($day) => FactFormat::display(Fact::DATE, $day->toDateString(), app()->getLocale());
        $languages = array_intersect_key(config('buildos.locales'), array_flip(\App\Models\OwnerUpdate::LANGUAGES));
        $picked = array_map('intval', (array) old('photo_ids', $update->photoIds()));
        // Ticks come back after a refusal, except when the figures themselves changed: then each one is checked afresh.
        $ticked = $errors->has('facts') ? [] : array_filter((array) old('number_ticks', []), 'is_string');
    @endphp

    <x-page-header :title="__('Weekly update')" :subtitle="__(':from to :to', ['from' => $date($update->week_start), 'to' => $date($update->week_end)]).' · '.$project->name">
        @include('modules.owner_pack.updates._status', ['update' => $update])
        <a href="{{ route('owner-updates.index', $project) }}" class="btn btn-ghost">{{ __('All updates') }}</a>
    </x-page-header>

    @if (! $current)
        <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm" role="status">
            @if ($draft)
                {{ __('The records have changed since this draft was prepared. Save the draft to read them again.') }}
            @else
                {{ __('The records behind this update have changed since it was approved. It stays as it was approved, and a new link cannot be made for it. Prepare a new update for the week.') }}
            @endif
        </div>
    @endif

    <div class="space-y-5">
        @if ($draft)
            <form method="POST" action="{{ route('owner-updates.update', $update) }}" class="card">
                @csrf @method('PATCH')
                <div class="card-head"><h2 class="card-title">{{ __('Details') }}</h2></div>
                <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-field :label="__('First day of the week')" name="week_start" :hint="__('The update covers seven days from this day. Only daily reports that are approved are read.')">
                        <input id="week_start" name="week_start" type="date" value="{{ old('week_start', $update->week_start->toDateString()) }}" max="{{ today()->toDateString() }}" class="input" required>
                    </x-field>
                    <x-field :label="__('Language of the update')" name="language">
                        <select id="language" name="language" class="input" required>
                            @foreach ($languages as $code => $label)
                                <option value="{{ $code }}" lang="{{ $code }}" @selected(old('language', $update->language) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    @can('money.view')
                        @include('modules.owner_pack.updates._money-fields', ['update' => $update])
                    @endcan
                    <x-field :label="__('Opening sentence')" name="opening" class="sm:col-span-2 lg:col-span-3" :hint="__('State no amount, percentage or date here: the app writes those sentences itself. A number you type is marked and needs its own tick.')">
                        <textarea id="opening" name="opening" rows="2" maxlength="{{ config('wording.free_slot_max_chars') }}" class="input" lang="{{ $update->language }}">{{ old('opening', $note?->slot('opening')?->text) }}</textarea>
                    </x-field>
                    <x-field :label="__('Planned for next week')" name="next_week_text" class="sm:col-span-2 lg:col-span-3" :hint="__('Optional, in your own words. The update prints it as written by the contractor. A number you type is marked and needs its own tick.')">
                        <textarea id="next_week_text" name="next_week_text" rows="3" maxlength="{{ config('wording.free_slot_max_chars') }}" class="input" lang="{{ $update->language }}">{{ old('next_week_text', $update->next_week_text) }}</textarea>
                    </x-field>
                    <x-field :label="__('Closing sentence')" name="closing" class="sm:col-span-2 lg:col-span-3" :hint="__('Empty brings back the app\'s own sentence.')">
                        <textarea id="closing" name="closing" rows="2" maxlength="{{ config('wording.free_slot_max_chars') }}" class="input" lang="{{ $update->language }}">{{ old('closing', $note?->slot('closing')?->text) }}</textarea>
                    </x-field>
                </div>

                <div class="border-t border-rule p-4">
                    <h3 class="text-sm font-semibold">{{ __('Photos for the client') }}</h3>
                    <p class="hint">{{ __('Pick from the photos of this week\'s approved daily reports, at most :count.', ['count' => config('modules.owner_pack.max_photos')]) }} {{ __('The caption is the one staff typed on the report.') }}</p>
                    @error('photo_ids')<p class="error">{{ $message }}</p>@enderror
                    {{-- Sent empty when no photo is ticked, so the last photo can be taken off. --}}
                    <input type="hidden" name="photo_ids" value="">
                    @if ($weekPhotos->isEmpty())
                        <p class="mt-2 text-sm text-ink-3">{{ __('The approved daily reports of this week have no photos.') }}</p>
                    @else
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($weekPhotos as $photo)
                                <label class="block cursor-pointer rounded-[3px] border border-rule p-2 text-xs">
                                    @if ($seesPhotos)
                                        <img src="{{ route('dprs.photo', $photo) }}" alt="{{ __('Site photo') }}" loading="lazy" class="aspect-[4/3] w-full rounded-[2px] object-cover">
                                    @endif
                                    <span class="mt-1.5 flex items-start gap-1.5">
                                        <input type="checkbox" name="photo_ids[]" value="{{ $photo->id }}" class="mt-0.5" @checked(in_array($photo->id, $picked, true))>
                                        <span><span class="num">{{ $date($photo->report_date) }}</span>@if (trim((string) $photo->caption) !== '')<br>{{ $photo->caption }}@endif</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="border-t border-rule p-4">
                    <button class="btn btn-primary">{{ __('Save draft') }}</button>
                    <p class="hint">{{ __('Saving reads the daily reports, the measurements and the stages again, as they stand today.') }}</p>
                </div>
            </form>
        @else
            <section class="card px-4 py-3 text-sm">
                <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-3">
                    <div><dt class="text-xs text-ink-3">{{ __('Language of the update') }}</dt><dd>{{ config('buildos.locales.'.$update->language, $update->language) }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Approved by') }}</dt><dd>{{ $update->approvedBy?->name ?? '—' }}</dd></div>
                    @if ($update->approved_at)
                        <div><dt class="text-xs text-ink-3">{{ __('Approved on') }}</dt><dd class="num">{{ $date($update->approved_at) }}</dd></div>
                    @endif
                </dl>
            </section>
        @endif

        <section class="card" id="check">
            <div class="card-head"><h2 class="card-title">{{ $draft ? __('Check and approve') : __('The update as approved') }}</h2></div>
            <div class="space-y-3 p-4">
                @if ($draft && $note === null)
                    <p class="error">{{ __('The update cannot be shown from the saved figures. Save the draft again.') }}</p>
                @else
                    @if ($draft)
                        <p class="text-sm text-ink-2">{{ __('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.') }}</p>
                    @endif
                    <div class="space-y-2 rounded-[3px] border border-rule bg-paper/60 p-3 text-sm" lang="{{ $update->language }}">
                        @if ($draft)
                            @include('modules.owner_pack.quotations._note', ['slots' => $note->parts(), 'marked' => true, 'labeller' => OwnerUpdateDocument::label(...)])
                        @else
                            @foreach ($body as $slot)
                                <p class="whitespace-pre-line" data-slot="{{ $slot['id'] ?? '' }}">{{ $slot['text'] ?? '' }}</p>
                            @endforeach
                        @endif
                    </div>
                @endif
            </div>

            @if ($work !== [])
                <div class="border-t border-rule">
                    @include('modules.owner_pack.updates._work', ['tick' => $draft && $canApprove && $note !== null, 'ticked' => $ticked])
                </div>
            @endif

            @if ($photos !== [])
                <div class="grid grid-cols-2 gap-3 border-t border-rule p-4 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ($photos as $photo)
                        <figure class="rounded-[3px] border border-rule p-2 text-xs">
                            @if ($seesPhotos)
                                <img src="{{ route('dprs.photo', $photo['id']) }}" alt="{{ __('Site photo') }}" loading="lazy" class="aspect-[4/3] w-full rounded-[2px] object-cover">
                            @endif
                            <figcaption class="mt-1.5">
                                <span class="num">{{ $photo['date'] }}</span>@if ($photo['caption'] !== null)<br>{{ $photo['caption'] }}@endif
                                @if ($draft && $canApprove && $note !== null)
                                    <label class="mt-1 flex cursor-pointer items-center gap-1.5">
                                        <input type="checkbox" form="approve-form" name="number_ticks[]" value="photo_{{ $photo['n'] }}" @checked(in_array('photo_'.$photo['n'], $ticked, true))>
                                        <span>{{ __('Checked') }}</span>
                                    </label>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif

            @if ($draft && $note !== null)
                <div class="space-y-3 border-t border-rule p-4">
                    @error('number_ticks')<p class="error">{{ $message }}</p>@enderror
                    @error('facts')<p class="error">{{ $message }}</p>@enderror
                    @error('approve')<p class="error">{{ $message }}</p>@enderror
                    @if ($canApprove)
                        <p class="text-xs text-ink-3">{{ __('The marked figures were placed by the app from your records. Tick each one below after you have checked it, and tick each work row and each photo above.') }}</p>
                        <form id="approve-form" method="POST" action="{{ route('owner-updates.approve', $update) }}">
                            @csrf
                            <input type="hidden" name="fact_fingerprint" value="{{ $note->fingerprint }}">
                            <input type="hidden" name="text_fingerprint" value="{{ $textFingerprint }}">
                            <ul class="space-y-1.5 text-sm">
                                @foreach ($tickList as $row)
                                    <li>
                                        <label class="flex cursor-pointer items-start gap-2">
                                            <input type="checkbox" name="number_ticks[]" value="{{ $row['id'] }}" class="mt-1" @checked(in_array($row['id'], $ticked, true))>
                                            <span>{{ $row['label'] }}: <span class="num font-medium">{{ $row['value'] }}</span>@if ($row['context']) <span class="text-xs text-ink-3">({{ $row['context'] }})</span>@endif</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            <button class="btn btn-primary mt-4">{{ __('Approve update') }}</button>
                            <p class="hint">{{ __('An approved update cannot be changed. Nothing is sent to the client: you make the link and send it yourself.') }}</p>
                        </form>
                    @else
                        <p class="text-sm text-ink-3">{{ __('A person who manages the home-owner pack and may see money checks every figure and approves the update before it can be shared.') }}</p>
                    @endif
                </div>
            @endif
        </section>

        @if ($update->isApproved())
            <section class="card" x-data="{ copied: false }">
                <div class="card-head"><h2 class="card-title">{{ __('Share with the client') }}</h2></div>
                <div class="space-y-3 p-4 text-sm">
                    @error('share')<p class="error">{{ $message }}</p>@enderror
                    @error('facts')<p class="error">{{ $message }}</p>@enderror
                    @if ($update->isShared())
                        <p>{{ __('A link is open until :date. Anyone who has the link can read this update and see its photos without signing in, so send it only to your client.', ['date' => $date($update->share_expires_at)]) }}</p>
                        @if ($canApprove)
                            <div class="flex gap-2">
                                <input readonly value="{{ $shareUrl }}" class="input num text-xs" aria-label="{{ __('Link to the update') }}" x-ref="link">
                                <button type="button" class="btn btn-ghost" @click="navigator.clipboard.writeText($refs.link.value); copied = true" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost">{{ __('Send on WhatsApp') }}</a>
                                <form method="POST" action="{{ route('owner-updates.share.revoke', $update) }}" onsubmit="return confirm(@js(__('Withdraw the link? The client will no longer be able to open it.')))">@csrf<button class="btn btn-danger">{{ __('Withdraw the link') }}</button></form>
                                @if ($current)
                                    <form method="POST" action="{{ route('owner-updates.share', $update) }}" onsubmit="return confirm(@js(__('Make a new link? The link you sent earlier will stop working.')))">@csrf<button class="btn btn-ghost">{{ __('Make a new link') }}</button></form>
                                @endif
                            </div>
                            @unless ($current)
                                @include('modules.owner_pack.updates._prepare-again', ['update' => $update, 'project' => $project])
                            @endunless
                        @endif
                    @else
                        <p>
                            @if ($update->share_token === null)
                                {{ __('No link has been made yet.') }}
                            @elseif ($update->share_revoked_at !== null)
                                {{ __('The link was withdrawn. It no longer opens.') }}
                            @else
                                {{ __('The link has passed its date. It no longer opens.') }}
                            @endif
                        </p>
                        @if ($canApprove && $current)
                            <form method="POST" action="{{ route('owner-updates.share', $update) }}">@csrf<button class="btn btn-primary">{{ __('Make a link') }}</button></form>
                            <p class="hint">{{ trans_choice('The link stays open for :count day and you send it yourself.|The link stays open for :count days and you send it yourself.', config('modules.owner_pack.share_link_days'), ['count' => config('modules.owner_pack.share_link_days')]) }}</p>
                        @elseif ($canApprove)
                            {{-- The records changed since approval, so a link would be refused: the way on is a new update for the week. --}}
                            @include('modules.owner_pack.updates._prepare-again', ['update' => $update, 'project' => $project])
                        @endif
                    @endif
                </div>
            </section>
        @endif
    </div>
@endsection
