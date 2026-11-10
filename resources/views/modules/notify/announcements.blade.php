@extends('layouts.app')
@section('title', __('Announcements'))
@section('content')
    <x-page-header :title="__('Announcements')" :subtitle="$company === null ? __('Send news or a reminder to the mobile app, for every company or some of them') : __('Send news or a reminder to your team on the mobile app')" />
    @if ($company === null)
        @include('admin._tabs')
    @endif

    @unless ($pushConnected)
        <p class="mb-4 max-w-3xl rounded border border-laterite p-3 text-sm text-laterite">{{ __('Push is not connected yet, so nothing reaches phones. Announcements are still listed in the app.') }}</p>
    @endunless

    <form method="POST" action="{{ route($routes['store']) }}" enctype="multipart/form-data" class="card max-w-3xl"
          x-data="{ audience: @js(old('audience', 'all')), schedule: @js(old('schedule', 'now')), busy: false }" @submit="busy = true">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', $clientUuid) }}">
        <div class="card-head"><h2 class="card-title">{{ __('New announcement') }}</h2></div>

        <div class="space-y-4 p-4">
            @error('client_uuid')<p class="error">{{ $message }}</p>@enderror
            <x-field :label="__('Title')" name="title" :hint="__('Up to :count letters. Shown in bold on the phone.', ['count' => \App\Services\Notify\AnnouncementService::TITLE_MAX])">
                <input id="title" name="title" value="{{ old('title') }}" maxlength="{{ \App\Services\Notify\AnnouncementService::TITLE_MAX }}" class="input" required>
            </x-field>
            <x-field :label="__('Message')" name="body" :hint="__('The notification shows the start; the app shows all of it.')">
                <textarea id="body" name="body" rows="4" maxlength="{{ \App\Services\Notify\AnnouncementService::BODY_MAX }}" class="input" required>{{ old('body') }}</textarea>
            </x-field>

            <details class="rounded border border-rule p-3" @if (old('title_mr') || old('body_mr') || old('title_hi') || old('body_hi')) open @endif>
                <summary class="cursor-pointer text-sm font-semibold">{{ __('Marathi and Hindi (optional)') }}</summary>
                <p class="hint mt-1">{{ __('Each phone gets the language its app is set to. A language left empty gets the English text.') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach (['mr' => 'मराठी', 'hi' => 'हिन्दी'] as $locale => $language)
                        <div class="space-y-3">
                            <x-field :label="__('Title in :language', ['language' => $language])" :name="'title_'.$locale">
                                <input id="title_{{ $locale }}" name="title_{{ $locale }}" value="{{ old('title_'.$locale) }}" maxlength="{{ \App\Services\Notify\AnnouncementService::TITLE_MAX }}" class="input" lang="{{ $locale }}">
                            </x-field>
                            <x-field :label="__('Message in :language', ['language' => $language])" :name="'body_'.$locale">
                                <textarea id="body_{{ $locale }}" name="body_{{ $locale }}" rows="3" maxlength="{{ \App\Services\Notify\AnnouncementService::BODY_MAX }}" class="input" lang="{{ $locale }}">{{ old('body_'.$locale) }}</textarea>
                            </x-field>
                        </div>
                    @endforeach
                </div>
            </details>

            <x-field :label="__('Picture (optional)')" name="image" :hint="__('JPG or PNG, up to 1 MB. Shown in the notification and in the app. A wide picture, twice as wide as it is tall, looks best.')">
                <input id="image" name="image" type="file" accept="image/jpeg,image/png" class="input">
            </x-field>

            <fieldset class="space-y-2">
                <legend class="label">{{ __('Send to') }}</legend>
                @error('audience')<p class="error">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="audience" value="all" x-model="audience"> {{ $company === null ? __('Everyone, every company') : __('Everyone in the company') }}</label>
                    @if ($company === null)
                        <label class="flex items-center gap-2"><input type="radio" name="audience" value="company" x-model="audience"> {{ __('One company') }}</label>
                    @endif
                    <label class="flex items-center gap-2"><input type="radio" name="audience" value="roles" x-model="audience"> {{ __('Some roles') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="audience" value="project" x-model="audience"> {{ __('The people on one project') }}</label>
                </div>

                @if ($company === null)
                    <div x-show="audience === 'company' || audience === 'roles'" x-cloak>
                        <x-field :label="__('Company')" name="organization_id">
                            <select id="organization_id" name="organization_id" class="input" x-bind:disabled="audience !== 'company' && audience !== 'roles'">
                                <option value="" x-show="audience === 'roles'">{{ __('Every company') }}</option>
                                @foreach ($companies as $organization)
                                    <option value="{{ $organization->id }}" @selected((string) old('organization_id') === (string) $organization->id)>{{ $organization->name }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>
                @endif

                <div x-show="audience === 'roles'" x-cloak>
                    <p class="label">{{ __('Roles') }}</p>
                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        @foreach ($roles as $key => $label)
                            <label class="flex items-center gap-2"><input type="checkbox" name="roles[]" value="{{ $key }}" @checked(in_array($key, old('roles', []), true)) x-bind:disabled="audience !== 'roles'"> {{ $label }}</label>
                        @endforeach
                    </div>
                    @error('roles')<p class="error">{{ $message }}</p>@enderror
                    @error('roles.*')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div x-show="audience === 'project'" x-cloak>
                    <x-field :label="__('Project')" name="project_id" :hint="__('Everyone who can open this project: the people given it, and those who see every project.')">
                        <select id="project_id" name="project_id" class="input" x-bind:disabled="audience !== 'project'">
                            <option value="">{{ __('Choose a project') }}</option>
                            @foreach ($projects->groupBy('organization_id') as $companyProjects)
                                @if ($company === null)<optgroup label="{{ $companyProjects->first()->organization?->name }}">@endif
                                @foreach ($companyProjects as $project)
                                    <option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->code }} · {{ $project->name }}</option>
                                @endforeach
                                @if ($company === null)</optgroup>@endif
                            @endforeach
                        </select>
                    </x-field>
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="label">{{ __('When') }}</legend>
                @error('schedule')<p class="error">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="schedule" value="now" x-model="schedule"> {{ __('Now') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="schedule" value="once" x-model="schedule"> {{ __('At a set time') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="schedule" value="daily" x-model="schedule"> {{ __('Every day, as a reminder') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" name="schedule" value="weekly" x-model="schedule"> {{ __('Every week, as a reminder') }}</label>
                </div>
                <p class="hint">{{ __('Times are India time. A person who keeps quiet hours gets it when their quiet hours end.') }}</p>

                <div x-show="schedule === 'once'" x-cloak>
                    <x-field :label="__('Send on')" name="send_at">
                        <input id="send_at" name="send_at" type="datetime-local" value="{{ old('send_at') }}" class="input num" x-bind:disabled="schedule !== 'once'">
                    </x-field>
                </div>
                <div class="grid gap-3 sm:grid-cols-3" x-show="schedule === 'daily' || schedule === 'weekly'" x-cloak>
                    <div x-show="schedule === 'weekly'">
                        <x-field :label="__('Day')" name="repeat_weekday">
                            <select id="repeat_weekday" name="repeat_weekday" class="input" x-bind:disabled="schedule !== 'weekly'">
                                @foreach ($weekdays as $day => $name)
                                    <option value="{{ $day }}" @selected((string) old('repeat_weekday', 1) === (string) $day)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>
                    <x-field :label="__('Time')" name="repeat_time">
                        <input id="repeat_time" name="repeat_time" type="time" value="{{ old('repeat_time', '09:00') }}" class="input num" x-bind:disabled="schedule !== 'daily' && schedule !== 'weekly'">
                    </x-field>
                    <x-field :label="__('Last day (optional)')" name="repeat_until">
                        <input id="repeat_until" name="repeat_until" type="date" value="{{ old('repeat_until') }}" class="input num" x-bind:disabled="schedule !== 'daily' && schedule !== 'weekly'">
                    </x-field>
                </div>
            </fieldset>

            <p class="hint">{{ __('People can switch announcements off in their notification settings. Someone who may not see money is not pushed a message with a rupee amount in it.') }}</p>
        </div>

        <div class="border-t border-rule px-4 py-3">
            <button class="btn btn-primary" x-bind:disabled="busy">
                <span x-show="! busy"><span x-show="schedule === 'now'">{{ __('Send now') }}</span><span x-show="schedule !== 'now'" x-cloak>{{ __('Save and schedule') }}</span></span>
                <span x-show="busy" x-cloak>{{ __('Sending…') }}</span>
            </button>
        </div>
    </form>

    <section class="card mt-5 max-w-6xl overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Sent and scheduled') }}</h2></div>
        @error('announcement')<p class="error px-4 pt-3">{{ $message }}</p>@enderror
        @if ($history->isEmpty())
            <x-empty :title="__('Nothing sent yet')" :note="__('Announcements and reminders you send are listed here with how many phones they reached.')" />
        @else
            <table class="ledger min-w-[880px]">
                <thead>
                    <tr>
                        <th>{{ __('Message') }}</th>
                        <th>{{ __('Sent to') }}</th>
                        <th>{{ __('When') }}</th>
                        <th class="text-right">{{ __('People') }}</th>
                        <th class="text-right">{{ __('Delivered') }}</th>
                        <th class="text-right">{{ __('Failed') }}</th>
                        <th>{{ __('By') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($history as $announcement)
                    <tr>
                        <td class="max-w-xs">
                            <div class="flex items-start gap-3">
                                @if ($announcement->imageUrl())
                                    <img src="{{ $announcement->imageUrl() }}" alt="" class="size-12 shrink-0 rounded border border-rule object-cover" loading="lazy">
                                @endif
                                <div>
                                    <p class="font-semibold">{{ $announcement->title }}</p>
                                    <p class="text-xs text-ink-3">{{ \Illuminate\Support\Str::limit($announcement->body, 120) }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="text-sm">
                            {{ $service->describeAudience($announcement) }}
                            @if ($company === null && ! $announcement->isPlatform())
                                <p class="text-xs text-ink-3">{{ __('From :company', ['company' => $announcement->organization?->name]) }}</p>
                            @endif
                        </td>
                        <td class="text-sm">
                            {{ $service->describeTiming($announcement) }}
                            <p class="text-xs text-ink-3">
                                @switch ($announcement->status)
                                    @case('scheduled') {{ $announcement->next_run_at ? __('Next: :date', ['date' => $announcement->next_run_at->format('d M Y, H:i')]) : __('Scheduled') }} @break
                                    @case('sending') {{ __('Sending') }} @break
                                    @case('sent') {{ __('Last sent :date', ['date' => $announcement->last_sent_at?->format('d M Y, H:i')]) }} @break
                                    @case('cancelled') {{ __('Stopped on :date', ['date' => $announcement->cancelled_at?->format('d M Y, H:i')]) }} @break
                                @endswitch
                                @if ($announcement->repeats() && $announcement->runs > 0) · {{ __('Sent :count times', ['count' => $announcement->runs]) }} @endif
                            </p>
                        </td>
                        <td class="num text-right">{{ $announcement->recipients_count }}</td>
                        <td class="num text-right">{{ $announcement->sent_count }} <span class="text-xs text-ink-3">/ {{ $announcement->phones_count }}</span></td>
                        <td class="num text-right">{{ $announcement->failed_count }}</td>
                        <td class="text-sm">{{ $announcement->author?->name }}<p class="num text-xs text-ink-3">{{ $announcement->created_at->format('d M, H:i') }}</p></td>
                        <td>
                            @if ($announcement->canBeCancelled())
                                <form method="POST" action="{{ route($routes['cancel'], $announcement) }}" x-data @submit="if (! confirm(@js(__('Stop this? Nothing more will be sent for it.')))) $event.preventDefault()">
                                    @csrf
                                    <button class="btn btn-ghost">{{ __('Stop') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="hint px-4 pb-3">{{ __('Delivered counts the phones Firebase accepted, out of the phones it was queued for. People who switched announcements off, or turned off push, get no push but still see it in the app.') }}</p>
        @endif
    </section>
@endsection
