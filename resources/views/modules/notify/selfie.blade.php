@extends('layouts.app')
@section('title', __('Selfie attendance'))
@section('content')
    <x-page-header :title="__('Selfie attendance')" :subtitle="$project->name">
        <a href="{{ route('attendance.index', $project) }}" class="btn btn-ghost">{{ __('Attendance register') }}</a>
    </x-page-header>

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        @if ($sites->isEmpty())
            <section class="card">
                <x-empty :title="__('No site has a map location')" :note="__('Add a latitude and longitude to a site. Selfies are checked against its geofence.')">
                    @can('project.manage')<a href="{{ route('projects.show', $project) }}" class="btn btn-primary">{{ __('Open project') }}</a>@endcan
                </x-empty>
            </section>
        @else
            <form method="POST" action="{{ route('notify.selfie.store', $project) }}" enctype="multipart/form-data" class="card self-start"
                  x-data="{
                      lat: '', lng: '', accuracy: '', state: 'idle',
                      locate() {
                          if (! navigator.geolocation) { this.state = 'unsupported'; return; }
                          this.state = 'finding';
                          navigator.geolocation.getCurrentPosition(
                              (position) => {
                                  this.lat = position.coords.latitude;
                                  this.lng = position.coords.longitude;
                                  this.accuracy = position.coords.accuracy;
                                  this.state = 'found';
                              },
                              () => { this.state = 'denied'; },
                              { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
                          );
                      },
                  }"
                  x-init="locate()">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <input type="hidden" name="lat" :value="lat">
                <input type="hidden" name="lng" :value="lng">
                <input type="hidden" name="accuracy_m" :value="accuracy">

                <div class="card-head"><h2 class="card-title">{{ __('Mark with a selfie') }}</h2></div>
                <div class="space-y-3 p-4">
                    <x-field :label="__('Site')" name="site_id">
                        <select id="site_id" name="site_id" class="input" required>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected((int) old('site_id') === $site->id)>{{ $site->name }} ({{ __('within :radius m', ['radius' => $site->geofence_radius_m]) }})</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field :label="__('Worker')" name="worker_id">
                        <select id="worker_id" name="worker_id" class="input" required>
                            <option value="">{{ __('Choose a worker') }}</option>
                            @foreach ($workers as $worker)
                                <option value="{{ $worker->id }}" @selected((int) old('worker_id') === $worker->id)>{{ $worker->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <div>
                        <p class="label">{{ __('Location') }}</p>
                        <p class="text-sm" role="status">
                            <span x-show="state === 'finding'">{{ __('Finding your location...') }}</span>
                            <span x-show="state === 'found'" x-cloak class="text-moss">{{ __('Location found.') }}</span>
                            <span x-show="state === 'denied'" x-cloak class="text-laterite">{{ __('Location is blocked. Allow it in your browser, then try again.') }}</span>
                            <span x-show="state === 'unsupported'" x-cloak class="text-laterite">{{ __('This device cannot share its location.') }}</span>
                        </p>
                        <button type="button" class="btn btn-ghost mt-2" x-show="state === 'denied' || state === 'found'" x-cloak @click="locate()">{{ __('Find location again') }}</button>
                        @error('lat')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <x-field :label="__('Photo')" name="selfie">
                        <input id="selfie" name="selfie" type="file" accept="image/*" capture="user" class="input" required>
                    </x-field>

                    <div class="rounded-[3px] bg-paper-2/60 p-3">
                        <p class="label">{{ __('Consent') }}</p>
                        <p class="text-sm">{{ $consentNote }}</p>
                        <label class="mt-2 flex items-start gap-2 text-sm font-semibold"><input type="checkbox" name="consent" value="1" class="mt-0.5 size-5 shrink-0" required> {{ __('I have explained this and the worker agrees.') }}</label>
                        @error('consent')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary w-full sm:w-auto" :disabled="state !== 'found'">{{ __('Mark attendance') }}</button></div>
            </form>
        @endif

        <section class="card self-start overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Today') }}</h2></div>
            @if ($selfies->isEmpty())
                <x-empty :title="__('No selfies yet today')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('Worker') }}</th><th>{{ __('Site') }}</th><th class="r">{{ __('Distance') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($selfies as $selfie)
                        <tr>
                            <td class="font-semibold">{{ $selfie->worker?->name }}</td>
                            <td>{{ $selfie->site?->name }}</td>
                            <td class="r num whitespace-nowrap">{{ $selfie->distance_m }} m</td>
                            <td class="r whitespace-nowrap">
                                <a href="{{ route('notify.selfie.photo', $selfie) }}" target="_blank" rel="noopener" class="link text-xs">{{ __('Photo') }}</a>
                                <form method="POST" action="{{ route('notify.selfie.destroy', $selfie) }}" class="inline" onsubmit="return confirm(@js(__('Delete this photo? The attendance mark stays.')))">
                                    @csrf @method('DELETE')
                                    <button class="link ml-2 text-xs text-laterite">{{ __('Delete') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
@endsection
