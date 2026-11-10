@extends('layouts.app')
@section('title', __('Integrations'))
@section('content')
    <x-page-header :title="__('Integrations')" :subtitle="__('Keys for SMS, WhatsApp and push notifications')" />
    @include('admin._tabs')

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        @foreach ($cards as $card)
            <section class="card self-start">
                <div class="card-head">
                    <h2 class="card-title">{{ $card['title'] }}</h2>
                    @if ($card['state'] === 'tested')
                        <span class="stamp border-moss text-moss">{{ __('Connected') }}</span>
                    @elseif ($card['state'] === 'saved')
                        <span class="stamp border-haldi text-[#8a6208]">{{ __('Keys saved, not tested') }}</span>
                    @else
                        <span class="stamp border-laterite text-laterite">{{ __('Not connected') }}</span>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.integrations.update', $card['name']) }}" autocomplete="off">
                    @csrf @method('PATCH')
                    <div class="space-y-3 p-4">
                        <p class="text-sm text-ink-3">{{ $card['purpose'] }}</p>
                        @if ($card['testedAt'])
                            <p class="text-sm text-moss">{{ __('Last test passed on :date.', ['date' => $card['testedAt']->timezone(config('app.timezone'))->format('d M Y, H:i')]) }}</p>
                        @endif
                        @if ($card['name'] === 'fcm')
                            @include('modules.notify._push-account', ['card' => $card])
                        @endif
                        @foreach ($card['fields'] as $field)
                            <x-field :label="$field['label']" :name="$field['key']" :hint="$field['saved'] ? __('Saved. Leave blank to keep it.') : ($field['hint'] ?? null)">
                                @if ($field['type'] === 'select')
                                    <select id="{{ $field['key'] }}" name="{{ $field['key'] }}" class="input">
                                        @foreach ($field['options'] as $value => $label)
                                            <option value="{{ $value }}" @selected((string) old($field['key'], $field['value']) === (string) $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field['type'] === 'textarea')
                                    <textarea id="{{ $field['key'] }}" name="{{ $field['key'] }}" rows="4" class="input num text-xs" autocomplete="off" spellcheck="false"></textarea>
                                @elseif ($field['type'] === 'password')
                                    <input id="{{ $field['key'] }}" name="{{ $field['key'] }}" type="password" class="input num" autocomplete="new-password">
                                @else
                                    <input id="{{ $field['key'] }}" name="{{ $field['key'] }}" value="{{ old($field['key'], $field['value']) }}" class="input num" autocomplete="off">
                                @endif
                            </x-field>
                        @endforeach
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove" value="1"> {{ __('Remove the saved details') }}</label>
                    </div>
                    <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
                </form>

                <form method="POST" action="{{ route('admin.integrations.test', $card['name']) }}" class="border-t border-rule p-4" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <p class="text-sm text-ink-3">{{ $card['testNote'] }}</p>
                    @error($card['name'])<p class="error">{{ $message }}</p>@enderror
                    @if ($card['name'] === 'sms')
                        <x-field :label="__('Mobile number to test')" name="phone" class="mt-2"><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" class="input num" placeholder="98XXXXXXXX" @disabled(! $card['live'])></x-field>
                    @endif
                    {{-- A test can take a few seconds. One tap is enough, so the button stays off until the answer comes. --}}
                    <button class="btn btn-ghost mt-3" @disabled(! $card['live']) x-bind:disabled="busy || {{ $card['live'] ? 'false' : 'true' }}"><span x-show="! busy">{{ __('Test') }}</span><span x-show="busy" x-cloak>{{ __('Checking…') }}</span></button>
                    @unless ($card['live'])<p class="hint">{{ __('Save all the details above to test.') }}</p>@endunless
                </form>

                @if ($card['name'] === 'fcm')
                    <form method="POST" action="{{ route('admin.integrations.push-test') }}" class="border-t border-rule p-4" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <p class="text-sm text-ink-3">{{ __('Sends a real notification to the phones you are signed in on in the mobile app. Yours now: :count.', ['count' => $ownPhones]) }}</p>
                        @error('push_test')<p class="error">{{ $message }}</p>@enderror
                        <button class="btn btn-ghost mt-3" @disabled(! $card['live']) x-bind:disabled="busy || {{ $card['live'] ? 'false' : 'true' }}"><span x-show="! busy">{{ __('Send a test notification to me') }}</span><span x-show="busy" x-cloak>{{ __('Sending…') }}</span></button>
                    </form>
                @endif
            </section>
        @endforeach

        <section class="card self-start">
            <div class="card-head">
                <h2 class="card-title">{{ __('Email') }}</h2>
                <span class="stamp {{ $emailConnected ? 'border-moss text-moss' : 'border-laterite text-laterite' }}">{{ $emailConnected ? __('Connected') : __('Not connected') }}</span>
            </div>
            <div class="p-4">
                <p class="text-sm text-ink-3">{{ __('Notification emails use the SMTP server from the Email tab.') }}</p>
                <a href="{{ route('admin.email') }}" class="btn btn-ghost mt-3">{{ __('Open Email settings') }}</a>
            </div>
        </section>
    </div>

    <section class="card mt-5 max-w-5xl overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Recent delivery failures') }}</h2></div>
        @if ($failures->isEmpty())
            <x-empty :title="__('No failures')" :note="__('Messages that cannot be delivered after their retries are listed here.')" />
        @else
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('When') }}</th><th>{{ __('Company') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Event') }}</th><th>{{ __('Error') }}</th></tr></thead>
                <tbody>
                @foreach ($failures as $failure)
                    <tr>
                        <td class="num whitespace-nowrap">{{ $failure->created_at->format('d M, H:i') }}</td>
                        <td>{{ $failure->organization?->name }}</td>
                        <td>{{ $failure->channel }}</td>
                        <td class="num text-xs">{{ $failure->event }}</td>
                        <td class="text-xs break-words">{{ $failure->error }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
