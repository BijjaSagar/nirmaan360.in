{{-- Which Firebase service account push is using and where it comes from. Only the project and the account's email are ever shown: the key never leaves the server. --}}
@if ($card['account'])
    <dl class="rounded-[3px] border border-rule bg-paper/60 px-3 py-2 text-sm">
        <div class="flex flex-wrap gap-x-2"><dt class="text-ink-3">{{ __('Firebase project') }}</dt><dd class="num break-all">{{ $card['account']['project_id'] }}</dd></div>
        <div class="flex flex-wrap gap-x-2"><dt class="text-ink-3">{{ __('Service account') }}</dt><dd class="num break-all">{{ $card['account']['client_email'] }}</dd></div>
    </dl>
    @if ($card['source'] === 'saved')
        <p class="text-sm text-ink-3">{{ __('A service account file is saved here, encrypted. Its key is never shown again.') }}</p>
        @if ($card['environment'] !== 'missing')
            <p class="text-sm text-ink-3">{{ __('The :variable environment variable is also set. The file saved here wins. Remove the saved details to use the variable instead.', ['variable' => 'FIREBASE_CREDENTIALS_BASE64']) }}</p>
        @endif
    @else
        <p class="text-sm text-ink-3">{{ __('Read from the :variable environment variable, because no file is saved here. A file saved here would be used instead.', ['variable' => 'FIREBASE_CREDENTIALS_BASE64']) }}</p>
    @endif
@else
    <p class="text-sm text-laterite">{{ __('No service account yet, so nothing is pushed. Everything else keeps working.') }}</p>
    <p class="text-sm text-ink-3">{{ __('Paste the file below. Or set the :variable environment variable to the same file in base64. A file saved here always wins.', ['variable' => 'FIREBASE_CREDENTIALS_BASE64']) }}</p>
    @if ($card['environment'] === 'unreadable')
        <p class="text-sm text-laterite">{{ __('The :variable environment variable is set, but it is not a service account file in base64.', ['variable' => 'FIREBASE_CREDENTIALS_BASE64']) }}</p>
    @endif
@endif
