{{-- Pick a template to open as a WhatsApp or email message. Needs $templates, $subjectType and $subjectId. --}}
<section class="card">
    <div class="card-head"><h2 class="card-title">{{ __('Send a message') }}</h2></div>
    @if ($templates->isEmpty())
        <p class="px-4 py-5 text-sm text-ink-3">{{ __('No message templates yet.') }}</p>
    @else
        <form method="GET" action="{{ route('crm.messages.compose') }}" class="space-y-2 p-4">
            <input type="hidden" name="subject_type" value="{{ $subjectType }}">
            <input type="hidden" name="subject_id" value="{{ $subjectId }}">
            <select name="template" class="input" aria-label="{{ __('Template') }}" required>
                @foreach ($templates->groupBy('channel') as $channel => $group)
                    <optgroup label="{{ \App\Services\Crm\Vocab::channels()[$channel] ?? $channel }}">
                        @foreach ($group as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <button class="btn btn-ghost w-full">{{ __('Write message') }}</button>
            <p class="hint">{{ __('You read it first, then it opens in WhatsApp or your mail app. Nothing is sent from here.') }}</p>
        </form>
    @endif
</section>
