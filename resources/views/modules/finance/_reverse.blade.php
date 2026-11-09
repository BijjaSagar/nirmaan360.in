{{-- Expects $action: the URL that reverses the entry. --}}
<details class="inline-block text-left">
    <summary class="link cursor-pointer text-xs">{{ __('Reverse') }}</summary>
    <form method="POST" action="{{ $action }}" class="mt-2 flex min-w-52 flex-col gap-2">
        @csrf
        <input name="reason" class="input" required maxlength="500" placeholder="{{ __('Reason for reversal') }}" aria-label="{{ __('Reason for reversal') }}">
        <button class="btn btn-danger">{{ __('Reverse entry') }}</button>
    </form>
</details>
