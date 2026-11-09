{{-- Notes, calls and meetings on a lead, tender or company. Needs $subjectType, $subjectId and $timeline. --}}
<section class="card">
    <div class="card-head"><h2 class="card-title">{{ __('Timeline') }}</h2></div>
    @can('crm.manage')
        <form method="POST" action="{{ route('crm.activities.store') }}" class="grid gap-2 border-b border-rule p-4 sm:grid-cols-4">
            @csrf
            <input type="hidden" name="subject_type" value="{{ $subjectType }}">
            <input type="hidden" name="subject_id" value="{{ $subjectId }}">
            <select name="kind" class="input" aria-label="{{ __('Type') }}">
                @foreach (\App\Models\CrmActivity::MANUAL_KINDS as $kind)
                    <option value="{{ $kind }}">{{ \App\Services\Crm\Vocab::activityKinds()[$kind] }}</option>
                @endforeach
            </select>
            <input name="body" class="input sm:col-span-2" maxlength="2000" required placeholder="{{ __('What happened?') }}" aria-label="{{ __('What happened?') }}">
            <button class="btn btn-ghost">{{ __('Add note') }}</button>
        </form>
    @endcan
    @forelse ($timeline as $entry)
        <div class="border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
            <p class="flex flex-wrap items-center gap-x-2 text-xs text-ink-3">
                <span class="eyebrow">{{ \App\Services\Crm\Vocab::activityKinds()[$entry->kind] ?? $entry->kind }}</span>
                <span class="num">{{ $entry->happened_at->format('d M Y, h:i A') }}</span>
                @if ($entry->user)<span>· {{ $entry->user->name }}</span>@endif
            </p>
            <p class="mt-0.5 whitespace-pre-line break-words">{{ $entry->body }}</p>
        </div>
    @empty
        <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing logged yet.') }}</p>
    @endforelse
</section>
